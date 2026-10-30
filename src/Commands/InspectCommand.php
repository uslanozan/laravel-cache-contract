<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Commands;

use Illuminate\Console\Command;
use Throwable;
use Uslanozan\CacheContract\Contracts\DefinitionRegistry;
use Uslanozan\CacheContract\Contracts\KeyFactory;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Definitions\Ttl;
use Uslanozan\CacheContract\Exceptions\CacheContractException;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Keys\KeyFormat;
use Uslanozan\CacheContract\Runtime\GenerationCounters;
use Uslanozan\CacheContract\Similarity\NameSimilarity;

/**
 * Shows one definition and how its cache key is built:
 *
 *     php artisan cache-contract:inspect user.profile
 *     php artisan cache-contract:inspect user.profile --param=user_id=42 --context=locale=tr
 *     php artisan cache-contract:inspect user.profile --param=user_id=42 --generation=users=8123
 *     php artisan cache-contract:inspect user.profile --param=user_id=42 --live
 *
 * A definition with tags or invalidated_by ends its key with "~g" and the
 * values of its generation counters (ADR 0009). The command connects to Redis
 * only with --live, and then only reads: a missing counter is reported, not
 * created.
 *
 * Secret parameters are never taken from --param (shell history, ps, CI
 * logs): the command asks for them without echoing, and only the hash is
 * printed.
 *
 * Terminal input is always text, so --param values are converted to the
 * declared parameter type here. The key factory itself stays strict (an int
 * parameter never accepts "42" from application code).
 */
final class InspectCommand extends Command
{
    /** Minimum name score for a "did you mean" suggestion. */
    public const SUGGESTION_THRESHOLD = 0.6;

    protected $signature = 'cache-contract:inspect
        {id : The definition ID, e.g. user.profile}
        {--param=* : A parameter value as name=value; arrays as JSON, e.g. ids=[1,2]}
        {--context=* : A context value as name=value, e.g. locale=tr}
        {--generation=* : A generation counter value as name=value, e.g. users=8123}
        {--live : Read the generation counters from Redis (read only)}';

    protected $description = 'Show a cache definition and the key it generates';

    public function handle(DefinitionRegistry $registry, KeyFactory $keys, KeyFormat $format, NameSimilarity $names): int
    {
        try {
            $id = $this->definitionId();

            if (! $registry->has($id)) {
                $this->components->error(sprintf('Unknown cache definition "%s".', $id));
                $this->suggest($id, $registry, $names);

                return self::FAILURE;
            }

            $definition = $registry->get($id);
            $this->describe($definition);
            $this->components->twoColumnDetail('Key pattern', $format->render($definition, $this->pattern($definition)));

            $params = $this->pairs('param');
            $context = $this->pairs('context');
            $secrets = array_keys(array_filter($definition->params, fn (ParameterType $type): bool => $type === ParameterType::Secret));

            // A value on the command line ends up in shell history, ps and CI logs.
            if ($given = array_values(array_intersect(array_keys($params), $secrets))) {
                $this->components->error(sprintf(
                    'Secret parameter "%s" is not accepted with --param, so it never lands in shell history or logs; you are asked for it instead.',
                    implode('", "', $given),
                ));

                return self::FAILURE;
            }

            if ($params === [] && count($definition->params) > count($secrets)) {
                $this->components->info('Pass --param=name=value for every parameter to see a concrete key.');

                return self::SUCCESS;
            }

            if ($secrets !== []) {
                if (! $this->input->isInteractive()) {
                    $this->components->info(sprintf('Secret parameters (%s) are asked for interactively; run without --no-interaction to see a concrete key.', implode(', ', $secrets)));

                    return self::SUCCESS;
                }

                foreach ($secrets as $name) {
                    $value = $this->secret("{$name} (secret, not shown)");
                    $params[$name] = is_string($value) ? $value : '';
                }
            }

            $params = $this->convert($definition, $params);
            $generations = $this->generations($definition);

            // false: Redis could not be read; null: a counter does not exist yet. Both are printed.
            if ($generations === false) {
                return self::FAILURE;
            }

            if ($generations === null) {
                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('Key', $keys->make($definition, $params, $context, $generations));

            if ($definition->generationCounters() !== [] && $generations === []) {
                $this->components->info('At runtime the key also ends with "~g" and the value of each counter. Pass --generation=name=value for every counter, or --live to read them from Redis.');
            }

            return self::SUCCESS;
        } catch (CacheContractException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function definitionId(): string
    {
        $id = $this->argument('id');

        return is_string($id) ? $id : '';
    }

    private function describe(Definition $definition): void
    {
        $this->newLine();

        foreach ([
            'ID' => $definition->id,
            'Subject' => $definition->subject ?? '-',
            'Purpose' => $definition->purpose,
            'Type' => $definition->type->value,
            'TTL' => sprintf('%d s (%s)', $definition->ttl, Ttl::forHumans($definition->ttl)),
            'Version' => (string) $definition->version,
            'Params' => ListCommand::describeParams($definition) ?: '-',
            'Tags' => $definition->tags === [] ? '-' : implode(', ', $definition->tags),
            'Invalidated by' => $definition->invalidatedBy === [] ? '-' : implode(', ', $definition->invalidatedBy),
            'Counters' => implode(', ', $definition->generationCounters()) ?: '-',
            'Description' => $definition->description,
        ] as $label => $value) {
            $this->components->twoColumnDetail($label, $value);
        }
    }

    /** The {params} segment with type placeholders: "user_id={int}", or "-". */
    private function pattern(Definition $definition): string
    {
        $params = $definition->params;
        ksort($params);

        $segments = array_map(
            fn (string $name, ParameterType $type): string => "{$name}={{$type->value}}",
            array_keys($params),
            $params,
        );

        // Counter values in name order, as the key factory writes them: ~g{admin}.{users}.
        if (($counters = $definition->generationCounters()) !== []) {
            $segments[] = '~g{'.implode('}.{', $counters).'}';
        }

        return $segments === [] ? '-' : implode(':', $segments);
    }

    /**
     * The generations for the key: from --generation, from Redis with --live,
     * or none. With --live, false when Redis could not be read and null when a
     * counter does not exist yet; the reason is printed.
     *
     * @return array<string, int>|false|null
     *
     * @throws InvalidParameters
     */
    private function generations(Definition $definition): array|false|null
    {
        $given = $this->pairs('generation');
        $live = (bool) $this->option('live');

        if ($given !== [] && $live) {
            throw InvalidParameters::forDefinition($definition->id, 'pass either --generation or --live, not both');
        }

        if (! $live) {
            $generations = [];

            foreach ($given as $name => $value) {
                if (preg_match('/^\d+$/', $value) !== 1) {
                    throw InvalidParameters::forDefinition($definition->id, sprintf('generation "%s" must be int, "%s" given', $name, $value));
                }

                $generations[$name] = (int) $value;
            }

            return $generations;
        }

        $counters = $definition->generationCounters();

        if ($counters === []) {
            return [];
        }

        try {
            $values = $this->laravel->make(GenerationCounters::class)->peek($counters);
        } catch (CacheContractException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->components->error('Could not read the generation counters from Redis: '.$e->getMessage());

            return false;
        }

        $missing = array_keys(array_filter($values, fn (?int $value): bool => $value === null));

        if ($missing !== []) {
            $this->components->info(sprintf(
                'Not in Redis yet: %s. The next cache call creates it with a new value, so no entry of this definition is cached right now.',
                implode(', ', $missing),
            ));

            return null;
        }

        /** @var array<string, int> $values */
        return $values;
    }

    /**
     * Parses repeated "name=value" options.
     *
     * @return array<string, string>
     *
     * @throws InvalidParameters
     */
    private function pairs(string $option): array
    {
        $pairs = [];
        $values = $this->option($option);

        foreach (is_array($values) ? $values : [] as $pair) {
            if (! is_string($pair) || ! str_contains($pair, '=')) {
                throw InvalidParameters::forDefinition($this->definitionId(), sprintf(
                    '--%s "%s" must be name=value',
                    $option,
                    is_string($pair) ? $pair : get_debug_type($pair),
                ));
            }

            [$name, $value] = explode('=', $pair, 2);
            $pairs[$name] = $value;
        }

        return $pairs;
    }

    /**
     * Converts terminal text to the declared types. Unknown names pass through
     * unchanged, so the key factory reports them as unexpected.
     *
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     *
     * @throws InvalidParameters
     */
    private function convert(Definition $definition, array $params): array
    {
        $converted = [];

        foreach ($params as $name => $value) {
            $type = $definition->params[$name] ?? null;
            $converted[$name] = $type === null ? $value : $this->toType($definition, $name, $type, $value);
        }

        return $converted;
    }

    /**
     * @throws InvalidParameters
     */
    private function toType(Definition $definition, string $name, ParameterType $type, string $value): mixed
    {
        $converted = match ($type) {
            ParameterType::String, ParameterType::Secret => $value,
            ParameterType::Int => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null,
            ParameterType::Float => is_numeric($value) ? (float) $value : null,
            ParameterType::Bool => match (strtolower($value)) {
                'true', '1' => true,
                'false', '0' => false,
                default => null,
            },
            ParameterType::Array => is_array($decoded = json_decode($value, true)) ? $decoded : null,
        };

        if ($converted === null) {
            throw InvalidParameters::forDefinition($definition->id, sprintf('"%s" must be %s, "%s" given', $name, $type->value, $value));
        }

        return $converted;
    }

    private function suggest(string $id, DefinitionRegistry $registry, NameSimilarity $names): void
    {
        $scores = [];

        foreach ($registry->all() as $definition) {
            $score = $names->compare($id, $definition->id)->score;

            if ($score >= self::SUGGESTION_THRESHOLD) {
                $scores[$definition->id] = $score;
            }
        }

        if ($scores === []) {
            $this->line('  Run <comment>php artisan cache-contract:list</comment> to see all definitions.');

            return;
        }

        arsort($scores);
        $this->line('  Did you mean: '.implode(', ', array_map(
            fn (string $candidate): string => "<comment>{$candidate}</comment>",
            array_slice(array_keys($scores), 0, 3),
        )).'?');
    }
}
