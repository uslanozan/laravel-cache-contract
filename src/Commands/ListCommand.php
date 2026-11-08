<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Uslanozan\CacheContract\Contracts\DefinitionRegistry;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Exceptions\InvalidDefinitions;

/**
 * Lists the cache definitions of the application:
 *
 *     php artisan cache-contract:list
 *     php artisan cache-contract:list --section=user --subject=User
 *     php artisan cache-contract:list --json
 */
final class ListCommand extends Command
{
    protected $signature = 'cache-contract:list
        {--section= : Only definitions in this section}
        {--subject= : Only definitions about this model (class name or short name)}
        {--json : Print machine-readable JSON instead of a table}';

    protected $description = 'List the cache definitions in config/cache-contract.php';

    public function handle(DefinitionRegistry $registry): int
    {
        try {
            $definitions = $this->filter($registry->all());
        } catch (InvalidDefinitions $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(
                array_map($this->toArray(...), $definitions),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        if ($definitions === []) {
            $this->components->info($this->isFiltered() ? 'No definitions match the filters.' : 'No cache definitions yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Subject', 'Type', 'Params', 'TTL', 'Version', 'Description'],
            array_map(fn (Definition $d): array => [
                $d->id,
                $d->subject === null ? '-' : class_basename($d->subject),
                $d->type->value,
                self::describeParams($d) ?: '-',
                $d->ttl,
                $d->version,
                Str::limit($d->description, 50),
            ], $definitions),
        );

        return self::SUCCESS;
    }

    /**
     * @param  list<Definition>  $definitions
     * @return list<Definition>
     */
    private function filter(array $definitions): array
    {
        $section = $this->stringOption('section');
        $subject = $this->stringOption('subject');

        return array_values(array_filter($definitions, fn (Definition $d): bool => ($section === null || $d->section === $section)
            && ($subject === null || $this->matchesSubject($d, $subject))));
    }

    private function matchesSubject(Definition $definition, string $subject): bool
    {
        if ($definition->subject === null) {
            return false;
        }

        return strcasecmp($definition->subject, ltrim($subject, '\\')) === 0
            || strcasecmp(class_basename($definition->subject), $subject) === 0;
    }

    private function isFiltered(): bool
    {
        return $this->stringOption('section') !== null || $this->stringOption('subject') !== null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Definition $definition): array
    {
        return [
            'id' => $definition->id,
            'section' => $definition->section,
            'name' => $definition->name,
            'subject' => $definition->subject,
            'purpose' => $definition->purpose,
            'type' => $definition->type->value,
            // Always a JSON object, also when empty: {} rather than [].
            'params' => (object) array_map(fn (ParameterType $type): string => $type->value, $definition->params),
            'ttl' => $definition->ttl,
            'version' => $definition->version,
            'tags' => $definition->tags,
            'invalidated_by' => $definition->invalidatedBy,
            'description' => $definition->description,
        ];
    }

    /** "tenant_id: int, role: string" */
    public static function describeParams(Definition $definition): string
    {
        return implode(', ', array_map(
            fn (string $name, ParameterType $type): string => "{$name}: {$type->value}",
            array_keys($definition->params),
            $definition->params,
        ));
    }
}
