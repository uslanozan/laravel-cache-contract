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
     * Secret parameters are hashed with APP_KEY (ADR 0004). Without it every
     * key of such a definition would throw on first use in production, so it
     * is a definition error here, where cache-contract:list and CI see it too.
     *
     * @param  array<string, Definition>  $definitions
     *
     * @throws InvalidDefinitions
     */
    private function assertAppKeyForSecrets(array $definitions): void
    {
        $appKey = $this->config->get('app.key');

        if (is_string($appKey) && $appKey !== '') {
            return;
        }

        $errors = [];

        foreach ($definitions as $definition) {
            foreach ($definition->params as $name => $type) {
                if ($type === ParameterType::Secret) {
                    $errors[] = new ValidationError($definition->id, sprintf(
                        'secret parameter "%s" needs APP_KEY to hash its value; set it (php artisan key:generate)',
                        $name,
                    ));
                }
            }
        }

        if ($errors !== []) {
            throw InvalidDefinitions::fromErrors($errors);
        }
    }

    /**
     * @return array<string, Definition>
     *
     * @throws InvalidDefinitions
     */
    private function definitions(): array
    {
        if ($this->definitions === null) {
            $definitions = [];

            foreach ($this->loader->load($this->config->get('cache-contract.definitions', [])) as $definition) {
                $definitions[$definition->id] = $definition;
            }

            $this->assertAppKeyForSecrets($definitions);
            $this->definitions = $definitions;
        }

        return $this->definitions;
    }
}
