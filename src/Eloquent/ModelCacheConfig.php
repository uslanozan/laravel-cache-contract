<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;

/**
 * The validated "models" section of the config: the default TTL of model
 * entries and the per-model overrides. Read once, the same for every request.
 *
 *     'models' => [
 *         'ttl' => 3600,
 *         'overrides' => [
 *             User::class => ['ttl' => 600, 'version' => 2, 'safe_scopes' => [SortScope::class]],
 *         ],
 *     ],
 */
final readonly class ModelCacheConfig
{
    private const OVERRIDE_FIELDS = ['ttl', 'version', 'safe_scopes'];

    /**
     * @param  positive-int  $ttl
     * @param  array<class-string<Model>, array{ttl?: positive-int, version?: positive-int, safe_scopes?: list<string>}>  $overrides
     */
    private function __construct(
        public int $ttl,
        private array $overrides,
    ) {}

    /**
     * @param  array<mixed>  $config  the value of config('cache-contract.models')
     *
     * @throws InvalidConfiguration
     */
    public static function fromConfig(array $config): self
    {
        $ttl = self::positiveInt($config['ttl'] ?? null, 'models.ttl');

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
                throw InvalidConfiguration::forKey($path, 'expected an array with "ttl", "version" or "safe_scopes"');
            }

            if ($unknown = array_diff(array_keys($settings), self::OVERRIDE_FIELDS)) {
                throw InvalidConfiguration::forKey($path, sprintf(
                    'unknown setting "%s"; expected "ttl", "version" or "safe_scopes"',
                    implode('", "', $unknown),
                ));
            }

            $override = [];
            if (array_key_exists('ttl', $settings)) {
                $override['ttl'] = self::positiveInt($settings['ttl'], "{$path}.ttl");
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

        return new self($ttl, $overrides);
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
     * @param  class-string<Model>  $class
     * @return positive-int
     */
    public function versionFor(string $class): int
    {
        return $this->overrides[$class]['version'] ?? 1;
    }

    /**
     * Global scopes of this model that never change which row an ID returns.
     *
     * @param  class-string<Model>  $class
     * @return list<string>
     */
    public function safeScopesFor(string $class): array
    {
        return $this->overrides[$class]['safe_scopes'] ?? [];
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
