<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fakes;

use Uslanozan\CacheContract\Contracts\DefinitionRegistry;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Exceptions\UnknownDefinition;

/**
 * Registry built from Definition objects instead of the config file. Use it
 * in tests until the real registry exists (LRVL-5), and afterwards whenever a
 * test does not care about config loading.
 */
final class InMemoryDefinitionRegistry implements DefinitionRegistry
{
    /** @var array<string, Definition> */
    private array $definitions = [];

    public function __construct(Definition ...$definitions)
    {
        foreach ($definitions as $definition) {
            $this->definitions[$definition->id] = $definition;
        }
    }

    public function get(string $id): Definition
    {
        return $this->definitions[$id] ?? throw UnknownDefinition::withId($id);
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function bySubject(string $subject): array
    {
        return array_values(array_filter(
            $this->definitions,
            fn (Definition $definition): bool => $definition->subject === $subject,
        ));
    }
}
