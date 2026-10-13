<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Definitions;

use Illuminate\Contracts\Config\Repository as Config;
use Uslanozan\CacheContract\Contracts\DefinitionRegistry;
use Uslanozan\CacheContract\Exceptions\InvalidDefinitions;
use Uslanozan\CacheContract\Exceptions\UnknownDefinition;

/**
 * The registry the package uses at runtime: definitions from
 * config/cache-contract.php, validated and loaded once, on first use.
 *
 * Registered as a singleton. It holds only the definitions, which are the
 * same for every request, so it is safe under Octane and queue workers.
 * A deploy that changes the config needs a restart of long-running workers
 * (php artisan octane:reload / queue:restart), as for any other config.
 */
final class ConfigDefinitionRegistry implements DefinitionRegistry
{
    /** @var array<string, Definition>|null keyed by id; null until first use */
    private ?array $definitions = null;

    public function __construct(
        private readonly Config $config,
        private readonly DefinitionLoader $loader,
    ) {}

    public function get(string $id): Definition
    {
        return $this->definitions()[$id] ?? throw UnknownDefinition::withId($id);
    }

    public function has(string $id): bool
    {
        return isset($this->definitions()[$id]);
    }

    public function all(): array
    {
        return array_values($this->definitions());
    }

    public function bySubject(string $subject): array
    {
        return array_values(array_filter(
            $this->definitions(),
            fn (Definition $definition): bool => $definition->subject === $subject,
        ));
    }

    /**
     * @return array<string, Definition>
     *
     * @throws InvalidDefinitions
     */
    private function definitions(): array
    {
        if ($this->definitions === null) {
            $this->definitions = [];

            foreach ($this->loader->load($this->config->get('cache-contract.definitions', [])) as $definition) {
                $this->definitions[$definition->id] = $definition;
            }
        }

        return $this->definitions;
    }
}
