<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Definitions;

use Illuminate\Contracts\Config\Repository as Config;
use Uslanozan\CacheContract\Exceptions\InvalidDefinitions;

/**
 * The tags declared under "tags" in config/cache-contract.php (ADR 0002):
 * the tags definitions may carry and CacheContract::flushTag() accepts.
 * Validated and read once, on first use.
 *
 *     'tags' => [
 *         'users' => 'Data shown about users.',
 *     ],
 *
 * A plain class, not a contract: no application needs to replace the tag
 * list. Registered as a singleton; it holds only config, so it is safe under
 * Octane and queue workers.
 */
final class TagRegistry
{
    /** @var array<string, string>|null tag name => description; null until first use */
    private ?array $tags = null;

    public function __construct(
        private readonly Config $config,
        private readonly DefinitionValidator $validator,
    ) {}

    /**
     * @throws InvalidDefinitions when the "tags" config breaks a rule
     */
    public function has(string $name): bool
    {
        return isset($this->tags()[$name]);
    }

    /**
     * @return array<string, string> tag name => description, in config order
     *
     * @throws InvalidDefinitions when the "tags" config breaks a rule
     */
    public function all(): array
    {
        return $this->tags();
    }

    /**
     * The declared tag closest to $name, for a "did you mean" hint.
     *
     * @throws InvalidDefinitions when the "tags" config breaks a rule
     */
    public function closest(string $name): ?string
    {
        return $this->validator->closest($name, array_map(strval(...), array_keys($this->tags())));
    }

    /**
     * @return array<string, string>
     *
     * @throws InvalidDefinitions
     */
    private function tags(): array
    {
        if ($this->tags === null) {
            $tags = $this->config->get('cache-contract.tags', []);
            $errors = $this->validator->validateTags($tags);

            if ($errors !== []) {
                throw InvalidDefinitions::fromErrors($errors);
            }

            /** @var array<string, string> $tags validated above */
            $this->tags = $tags;
        }

        return $this->tags;
    }
}
