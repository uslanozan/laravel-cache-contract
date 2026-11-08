<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Definitions\Ttl;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;

/**
 * The validated "models" section of the config: the default TTL of model
 * entries and of "no such row", the chunk size of bulk reads and the
 * per-model overrides. Read once, the same for every request.
 *
 *     'models' => [
 *         'ttl' => 3600,
 *         'null_ttl' => 60,
 *         'chunk_size' => 1000,
 *         'overrides' => [
 *             User::class => ['ttl' => 600, 'null_ttl' => 30, 'version' => 2, 'safe_scopes' => [SortScope::class]],
 *         ],
 *     ],
 */
final readonly class ModelCacheConfig
{
    private const OVERRIDE_FIELDS = ['ttl', 'null_ttl', 'version', 'safe_scopes'];

    /** Keys per MGET and IDs per query in findMany() when the config does not say. */
    public const DEFAULT_CHUNK_SIZE = 1000;

    /** Seconds a cached "no such row" lives when the config does not say (LRVL-60). */
    public const DEFAULT_NULL_TTL = 60;

    /**
     * @param  positive-int  $ttl
     * @param  positive-int|null  $nullTtl  null: "no such row" lives as long as a row
     * @param  positive-int  $chunkSize
     * @param  array<class-string<Model>, array{ttl?: positive-int, null_ttl?: positive-int|null, version?: positive-int, safe_scopes?: list<string>}>  $overrides
     */
    private function __construct(
        public int $ttl,
        public ?int $nullTtl,
        public int $chunkSize,
        private array $overrides,
    ) {}

    /**
     * @param  array<mixed>  $config  the value of config('cache-contract.models')
     *
     * @throws InvalidConfiguration
     */
    public static function fromConfig(array $config): self
    {
        $ttl = self::ttl($config['ttl'] ?? null, 'models.ttl');
        $nullTtl = array_key_exists('null_ttl', $config)
            ? self::nullTtl($config['null_ttl'], $ttl, 'models.null_ttl')
            : self::DEFAULT_NULL_TTL;
        $chunkSize = self::positiveInt($config['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE, 'models.chunk_size');

        $raw = $config['overrides'] ?? [];
        if (! is_array($raw)) {
            throw InvalidConfiguration::forKey('models.overrides', 'expected an array keyed by model class');
        }

        $overrides = [];
        foreach ($raw as $class => $settings) {
            $class = (string) $class;
            $path = "models.overrides.{$class}";

            if (! is_subclass_of($class, Model::class)) {
                throw InvalidConfiguration::forKey($path, 'the key must be an existing Eloquent model class');
            }

            if (! is_array($settings)) {
                throw InvalidConfiguration::forKey($path, 'expected an array with "ttl", "null_ttl", "version" or "safe_scopes"');
            }

            if ($unknown = array_diff(array_keys($settings), self::OVERRIDE_FIELDS)) {
                throw InvalidConfiguration::forKey($path, sprintf(
                    'unknown setting "%s"; expected "ttl", "null_ttl", "version" or "safe_scopes"',
                    implode('", "', $unknown),
                ));
            }

            $override = [];
            if (array_key_exists('ttl', $settings)) {
                $override['ttl'] = self::ttl($settings['ttl'], "{$path}.ttl");
            }
            if (array_key_exists('null_ttl', $settings)) {
                $override['null_ttl'] = self::nullTtl($settings['null_ttl'], $override['ttl'] ?? $ttl, "{$path}.null_ttl");
            }
            if (array_key_exists('version', $settings)) {
                $override['version'] = self::positiveInt($settings['version'], "{$path}.version");
            }
            if (array_key_exists('safe_scopes', $settings)) {
                $scopes = $settings['safe_scopes'];
                if (! is_array($scopes) || ! array_is_list($scopes) || $scopes !== array_filter($scopes, is_string(...))) {
                    throw InvalidConfiguration::forKey("{$path}.safe_scopes", 'expected a list of global scope names');
                }
                /** @var list<string> $scopes */
                $override['safe_scopes'] = $scopes;
            }

            $overrides[$class] = $override;
        }

        return new self($ttl, $nullTtl, $chunkSize, $overrides);
    }

    /**
     * @param  class-string<Model>  $class
     * @return positive-int
     */
    public function ttlFor(string $class): int
    {
        return $this->overrides[$class]['ttl'] ?? $this->ttl;
    }

    /**
     * Seconds a cached "no such row" of this model lives, or null for as long
     * as a row. The model's own "null_ttl" if it has one; otherwise the
     * default, but never longer than the model's TTL, so a short "ttl"
     * override needs no "null_ttl" of its own.
     *
     * @param  class-string<Model>  $class
     * @return positive-int|null
     */
    public function nullTtlFor(string $class): ?int
    {
        if (array_key_exists('null_ttl', $this->overrides[$class] ?? [])) {
            return $this->overrides[$class]['null_ttl'];
        }

        return $this->nullTtl === null ? null : min($this->nullTtl, $this->ttlFor($class));
    }

    /**
     * @param  class-string<Model>  $class
     * @return positive-int
     */
    public function versionFor(string $class): int
    {
        return $this->overrides[$class]['version'] ?? 1;
    }

    /**
     * Global scopes of this model whose result does not depend on the request,
     * the user or the tenant, so they need no context.
     *
     * @param  class-string<Model>  $class
     * @return list<string>
     */
    public function safeScopesFor(string $class): array
    {
        return $this->overrides[$class]['safe_scopes'] ?? [];
    }

    /**
     * Seconds, or a duration with units such as "1 hour" (Ttl).
     *
     * @return positive-int
     */
    private static function ttl(mixed $value, string $key): int
    {
        if (($problem = Ttl::problem($value)) !== null) {
            throw InvalidConfiguration::forKey($key, $problem);
        }

        return Ttl::seconds($value);
    }

    /**
     * A TTL for "no such row", no longer than $ttl; null for as long as a row.
     *
     * @return positive-int|null
     */
    private static function nullTtl(mixed $value, int $ttl, string $key): ?int
    {
        if ($value === null) {
            return null;
        }

        $seconds = self::ttl($value, $key);

        if ($seconds > $ttl) {
            throw InvalidConfiguration::forKey($key, sprintf('must not be longer than the TTL (%d s), got %d s', $ttl, $seconds));
        }

        return $seconds;
    }

    /**
     * @return positive-int
     */
    private static function positiveInt(mixed $value, string $key): int
    {
        if (! is_int($value) || $value < 1) {
            throw InvalidConfiguration::forKey($key, sprintf(
                'expected a positive integer, got %s',
                is_scalar($value) ? var_export($value, true) : get_debug_type($value),
            ));
        }

        return $value;
    }
}
