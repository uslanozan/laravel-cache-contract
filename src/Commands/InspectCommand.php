<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Commands;

use Illuminate\Console\Command;
use Uslanozan\CacheContract\Contracts\DefinitionRegistry;
use Uslanozan\CacheContract\Contracts\KeyFactory;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Exceptions\CacheContractException;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Keys\KeyFormat;
use Uslanozan\CacheContract\Similarity\NameSimilarity;

/**
 * Shows one definition and how its cache key is built:
 *
 *     php artisan cache-contract:inspect user.profile
 *     php artisan cache-contract:inspect user.profile --param=user_id=42 --context=locale=tr
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
        {--context=* : A context value as name=value, e.g. locale=tr}';

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

            if ($params === [] && $definition->params !== []) {
                $this->components->info('Pass --param=name=value for every parameter to see a concrete key.');

                return self::SUCCESS;
            }

            $key = $keys->make($definition, $this->convert($definition, $params), $context);
            $this->components->twoColumnDetail('Key', $key);

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
            'TTL' => $definition->ttl.' s',
            'Version' => (string) $definition->version,
            'Params' => ListCommand::describeParams($definition) ?: '-',
            'Tags' => $definition->tags === [] ? '-' : implode(', ', $definition->tags),
            'Invalidated by' => $definition->invalidatedBy === [] ? '-' : implode(', ', $definition->invalidatedBy),
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

        return $segments === [] ? '-' : implode(':', $segments);
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
