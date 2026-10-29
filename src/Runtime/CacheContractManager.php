<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Runtime;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Throwable;
use Uslanozan\CacheContract\Contracts\DefinitionRegistry;
use Uslanozan\CacheContract\Contracts\KeyFactory;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Exceptions\UnknownDefinition;

/**
 * Reads and writes cache entries through their definitions: the service
 * behind the CacheContract facade.
 *
 *     CacheContract::remember('user.profile', ['user_id' => 42], fn () => $this->load(42));
 *     CacheContract::withContext(['tenant_id' => 7])->get('user.active_list', [...]);
 *
 * The key comes from the definition and the parameters, the TTL from the
 * definition. Values are stored in an envelope, so a cached null is a hit
 * (ADR 0007). Redis connection errors follow the "redis_errors" setting.
 * A definition with tags or invalidated_by reads its generation counters
 * first, in one call, and their values end the key (ADR 0009).
 *
 * Immutable: withContext() returns a new instance and never changes this one,
 * so the shared instance carries no tenant or locale from one request or job
 * to the next (Octane, queue workers).
 */
final readonly class CacheContractManager
{
    /**
     * @param  string|null  $store  cache store name; null uses the default store
     * @param  array<string, scalar>  $context
     */
    public function __construct(
        private CacheFactory $cache,
        private DefinitionRegistry $definitions,
        private KeyFactory $keys,
        private RedisErrorPolicy $errors,
        private GenerationCounters $counters,
        private ?string $store = null,
        private array $context = [],
    ) {}

    /**
     * A copy that adds these values to every key it builds, on top of the
     * context this instance already has.
     *
     * @param  array<string, scalar>  $context  e.g. ['tenant_id' => 7, 'locale' => 'tr']
     */
    public function withContext(array $context): self
    {
        return new self($this->cache, $this->definitions, $this->keys, $this->errors, $this->counters, $this->store, array_merge($this->context, $context));
    }

    /**
     * @return array<string, scalar>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * The physical key for these parameters and this instance's context. For
     * a definition with tags or invalidated_by it ends with the current values
     * of its generation counters (ADR 0009), read from Redis; any Redis error
     * then propagates.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws UnknownDefinition
     * @throws InvalidParameters
     * @throws InvalidConfiguration when a secret parameter is used without APP_KEY
     */
    public function key(string $id, array $params = []): string
    {
        $definition = $this->definitions->get($id);
        $key = $this->keys->make($definition, $params, $this->context); // checks the parameters before any I/O
        $counters = $definition->generationCounters();

        return $counters === [] ? $key : $this->keys->make($definition, $params, $this->context, $this->counters->currentOrFail($counters));
    }

    /**
     * The cached value, or $default when nothing is cached. A cached null is
     * returned as null; use has() to tell it from a miss.
     *
     * @param  array<string, mixed>  $params
     * @param  mixed  $default  a value, or a closure that returns one
     */
    public function get(string $id, array $params = [], mixed $default = null): mixed
    {
        $key = $this->keyFor($this->definitions->get($id), $params, $this->errors->read);
        $entry = $key === false ? false : $this->read($key);

        return Envelope::isHit($entry) ? Envelope::unwrap($entry) : value($default);
    }

    /**
     * Whether a value is cached, including a cached null.
     *
     * @param  array<string, mixed>  $params
     */
    public function has(string $id, array $params = []): bool
    {
        $key = $this->keyFor($this->definitions->get($id), $params, $this->errors->read);

        return $key !== false && Envelope::isHit($this->read($key));
    }

    /**
     * Caches a value for the definition's TTL.
     *
     * @param  array<string, mixed>  $params
     * @return bool false when the value could not be written
     */
    public function put(string $id, array $params, mixed $value): bool
    {
        $definition = $this->definitions->get($id);
        $key = $this->keyFor($definition, $params, $this->errors->write);

        return $key !== false && $this->write($definition, $key, $value);
    }

    /**
     * The cached value; on a miss, runs $loader, caches its result and
     * returns it. A null result is cached too.
     *
     * @template TValue
     *
     * @param  array<string, mixed>  $params
     * @param  Closure(): TValue  $loader
     * @return TValue|mixed
     */
    public function remember(string $id, array $params, Closure $loader): mixed
    {
        $definition = $this->definitions->get($id);
        // Read before the loader runs, so a value loaded across an invalidation
        // is written under the old generation and never read (ADR 0009).
        $key = $this->keyFor($definition, $params, $this->errors->read);
        $entry = $key === false ? false : $this->read($key);

        if (Envelope::isHit($entry)) {
            return Envelope::unwrap($entry);
        }

        $value = $loader();

        // When the read failed, Redis is down: skip the write that would fail too.
        if ($key !== false && $entry !== false) {
            $this->write($definition, $key, $value);
        }

        return $value;
    }

    /**
     * Runs $loader and caches its result, whether or not a value is cached.
     *
     * @template TValue
     *
     * @param  array<string, mixed>  $params
     * @param  Closure(): TValue  $loader
     * @return TValue
     */
    public function refresh(string $id, array $params, Closure $loader): mixed
    {
        $definition = $this->definitions->get($id);
        $key = $this->keyFor($definition, $params, $this->errors->write);

        $value = $loader();

        if ($key !== false) {
            $this->write($definition, $key, $value);
        }

        return $value;
    }

    /**
     * Removes the cached value.
     *
     * @param  array<string, mixed>  $params
     * @return bool false when nothing was removed or Redis could not be reached
     */
    public function forget(string $id, array $params = []): bool
    {
        $key = $this->keyFor($this->definitions->get($id), $params, $this->errors->invalidate);

        if ($key === false) {
            return false;
        }

        try {
            return $this->repository()->forget($key);
        } catch (Throwable $e) {
            $this->errors->handle($e, $this->errors->invalidate);

            return false;
        }
    }

    /**
     * The key, with the generations of the definition's counters read in one
     * call; false when Redis could not be reached (handled with $mode,
     * ADR 0007). A definition without counters needs no Redis here.
     *
     * @param  array<string, mixed>  $params
     */
    private function keyFor(Definition $definition, array $params, RedisErrorMode $mode): string|false
    {
        $key = $this->keys->make($definition, $params, $this->context); // checks the parameters before any I/O
        $counters = $definition->generationCounters();

        if ($counters === []) {
            return $key;
        }

        $generations = $this->counters->current($counters, $mode);

        return $generations === false ? false : $this->keys->make($definition, $params, $this->context, $generations);
    }

    /**
     * @return array{0: mixed}|false|null the envelope on a hit, null on a miss,
     *                                    false when Redis could not be reached
     */
    private function read(string $key): array|false|null
    {
        try {
            $entry = $this->repository()->get($key);

            return Envelope::isHit($entry) ? $entry : null;
        } catch (Throwable $e) {
            $this->errors->handle($e, $this->errors->read);

            return false;
        }
    }

    private function write(Definition $definition, string $key, mixed $value): bool
    {
        try {
            return $this->repository()->put($key, Envelope::wrap($value), $definition->ttl);
        } catch (Throwable $e) {
            $this->errors->handle($e, $this->errors->write);

            return false;
        }
    }

    private function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
