<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Runtime;

use Closure;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Throwable;
use Uslanozan\CacheContract\Contracts\DefinitionRegistry;
use Uslanozan\CacheContract\Contracts\KeyFactory;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Definitions\Lock;
use Uslanozan\CacheContract\Definitions\TagRegistry;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;
use Uslanozan\CacheContract\Exceptions\InvalidDefinitions;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Exceptions\UnknownDefinition;
use Uslanozan\CacheContract\Exceptions\UnknownTag;

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
 * A removal leaves a tombstone, and a loader writes only if the key still
 * holds what its read found, so a value loaded before forget() or pull() is
 * not written back after it (ADR 0012).
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
        private TagRegistry $tags,
        private EntryStore $entries = new EntryStore,
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
        return new self($this->cache, $this->definitions, $this->keys, $this->errors, $this->counters, $this->tags, $this->entries, $this->store, array_merge($this->context, $context));
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
        $key = $this->keyFor($this->definitions->get($id), $params, RedisOperation::Read);
        $entry = $key === false ? false : $this->read($key);

        return $entry !== false && $entry->isHit() ? $entry->value() : value($default);
    }

    /**
     * Whether a value is cached, including a cached null.
     *
     * @param  array<string, mixed>  $params
     */
    public function has(string $id, array $params = []): bool
    {
        $key = $this->keyFor($this->definitions->get($id), $params, RedisOperation::Read);

        if ($key === false) {
            return false;
        }

        $entry = $this->read($key);

        return $entry !== false && $entry->isHit();
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
        $key = $this->keyFor($definition, $params, RedisOperation::Write);

        return $key !== false && $this->write($definition, $key, $value);
    }

    /**
     * Caches a value only when none is cached yet, for the definition's TTL.
     * On Redis the check and the write are one script: of two concurrent
     * calls, one writes. A cached null counts as cached, and so does a value
     * other code wrote under the key. On Redis, a tombstone left by forget()
     * or pull() does not; on other stores it blocks add() until it expires
     * (ADR 0012).
     *
     * Not a "run once" guarantee for a definition with tags or invalidated_by:
     * its generation counters are read first, and a change in between gives
     * the two calls different keys.
     *
     * @param  array<string, mixed>  $params
     * @return bool true when this call wrote the value
     */
    public function add(string $id, array $params, mixed $value): bool
    {
        $definition = $this->definitions->get($id);
        $key = $this->keyFor($definition, $params, RedisOperation::Write);

        if ($key === false) {
            return false;
        }

        try {
            return $this->entries->add($this->repository(), $key, Envelope::wrap($value), $definition->ttl);
        } catch (Throwable $e) {
            $this->errors->handle($e, RedisOperation::Write);

            return false;
        }
    }

    /**
     * The cached value, removed in the same call, or $default when nothing is
     * cached. On Redis, one script reads the value and leaves a tombstone in
     * its place (ADR 0012), so of two concurrent pulls exactly one gets the
     * value, a value written in between is never removed by mistake, and a
     * loader that read before the pull cannot write the value back. Laravel's
     * Cache::pull() reads and deletes in two steps, so both would get it.
     * Other stores read, then delete, and hand the value only to the call
     * whose delete removed an entry; that is not atomic, and leaves no
     * tombstone.
     *
     * When the cache cannot be reached, $default is returned: a value that
     * may not have been removed is not handed out.
     *
     * @param  array<string, mixed>  $params
     * @param  mixed  $default  a value, or a closure that returns one
     */
    public function pull(string $id, array $params = [], mixed $default = null): mixed
    {
        $key = $this->keyFor($this->definitions->get($id), $params, RedisOperation::Read);

        if ($key === false) {
            return value($default);
        }

        $store = $this->repository()->getStore();

        if ($store instanceof RedisStore) {
            try {
                $entry = $this->entries->take($store, $key);
            } catch (Throwable $e) {
                $this->errors->handle($e, RedisOperation::Read);

                return value($default);
            }

            return $entry->isHit() ? $entry->value() : value($default);
        }

        $entry = $this->read($key);

        return $entry !== false && $entry->isHit() && $this->remove($key) ? $entry->value() : value($default);
    }

    /**
     * The cached value; on a miss, runs $loader, caches its result and
     * returns it. A null result is cached too.
     *
     * The result is written only if the key still holds what the read found
     * (ADR 0012): after a forget() or pull() while the loader ran, the loaded
     * value may be old, so it is returned but not cached.
     *
     * With a "lock" in the definition, a miss takes the lock "{key}|lock"
     * first, so one request runs the loader while the others wait for its
     * value (ADR 0012); see rememberWithLock().
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
        $key = $this->keyFor($definition, $params, RedisOperation::Read);
        $entry = $key === false ? false : $this->read($key);

        if ($entry !== false && $entry->isHit()) {
            return $entry->value();
        }

        // When the read failed, Redis is down: no lock, and no write that would fail too.
        if ($key !== false && $entry !== false && $definition->lock !== null) {
            $store = $this->repository()->getStore();

            if ($store instanceof LockProvider) {
                return $this->rememberWithLock($definition, $definition->lock, $store, $key, $loader);
            }
        }

        $value = $loader();

        if ($key !== false && $entry !== false) {
            $this->writeLoaded($definition, $key, $entry, $value);
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
        $key = $this->keyFor($definition, $params, RedisOperation::Write);

        $value = $loader();

        if ($key !== false) {
            $this->write($definition, $key, $value);
        }

        return $value;
    }

    /**
     * Removes the cached value by leaving a tombstone in its place for
     * "tombstone_ttl" seconds, so a loader that read before this call does
     * not write its old value back (ADR 0012).
     *
     * @param  array<string, mixed>  $params
     * @return bool true when the tombstone was written, whether or not a value
     *              was cached; false only when Redis could not be reached
     */
    public function forget(string $id, array $params = []): bool
    {
        $key = $this->keyFor($this->definitions->get($id), $params, RedisOperation::Invalidate);

        return $key !== false && $this->bury($key);
    }

    /**
     * Invalidates every entry of every definition with this tag, for every
     * parameter and context, by giving the tag's generation counter a new
     * value (ADR 0009): one Redis write, whatever the number of entries. Old
     * entries are no longer read and expire through their TTL. The context of
     * this instance plays no part.
     *
     * @return bool false when Redis could not be reached (the "invalidate" policy, ADR 0007)
     *
     * @throws UnknownTag when the tag is not declared under "tags"
     * @throws InvalidDefinitions when the "tags" config breaks a rule
     */
    public function flushTag(string $tag): bool
    {
        if (! $this->tags->has($tag)) {
            throw UnknownTag::withName($tag, $this->tags->closest($tag));
        }

        return $this->counters->bump([$tag]);
    }

    /**
     * remember() after a miss, with the stampede lock (ADR 0012).
     *
     * With the lock, the entry is read again, since another request may have
     * filled it while this one waited; only on a miss does the loader run.
     * When the wait ends without the lock, this request does not fail: it
     * reads once more and on a miss loads too. Either way the value is written
     * with the compare-and-set against the last read, so it never overwrites
     * what the holder wrote. A Redis error while taking the lock follows the
     * "read" policy: the loader runs and nothing is written. The lock is
     * released by its owner only, also when the loader throws.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $loader
     * @return TValue|mixed
     */
    private function rememberWithLock(Definition $definition, Lock $settings, LockProvider $store, string $key, Closure $loader): mixed
    {
        try {
            $lock = $store->lock($key.'|lock', $settings->seconds);
            $acquired = (bool) $lock->block($settings->wait);
        } catch (LockTimeoutException) {
            $acquired = false;
        } catch (Throwable $e) {
            $this->errors->handle($e, RedisOperation::Read);

            return $loader();
        }

        try {
            $entry = $this->read($key);

            if ($entry !== false && $entry->isHit()) {
                return $entry->value();
            }

            $value = $loader();

            if ($entry !== false) {
                $this->writeLoaded($definition, $key, $entry, $value);
            }

            return $value;
        } finally {
            if ($acquired) {
                $this->release($lock);
            }
        }
    }

    /**
     * A failure follows the "read" policy, as for taking the lock; an
     * unreleased lock expires after its "seconds".
     */
    private function release(CacheLock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $e) {
            $this->errors->handle($e, RedisOperation::Read);
        }
    }

    /**
     * The key, with the generations of the definition's counters read in one
     * call; false when Redis could not be reached (handled as $operation,
     * ADR 0007). A definition without counters needs no Redis here.
     *
     * @param  array<string, mixed>  $params
     */
    private function keyFor(Definition $definition, array $params, RedisOperation $operation): string|false
    {
        $key = $this->keys->make($definition, $params, $this->context); // checks the parameters before any I/O
        $counters = $definition->generationCounters();

        if ($counters === []) {
            return $key;
        }

        $generations = $this->counters->current($counters, $operation);

        return $generations === false ? false : $this->keys->make($definition, $params, $this->context, $generations);
    }

    /**
     * What the key holds; false when Redis could not be reached.
     */
    private function read(string $key): StoredEntry|false
    {
        try {
            return $this->entries->read($this->repository(), $key);
        } catch (Throwable $e) {
            $this->errors->handle($e, RedisOperation::Read);

            return false;
        }
    }

    /**
     * Writes the value in the envelope for the definition's TTL, whatever the
     * key holds: put() and refresh(). A failure follows the "write" policy.
     */
    private function write(Definition $definition, string $key, mixed $value): bool
    {
        try {
            return $this->repository()->put($key, Envelope::wrap($value), $definition->ttl);
        } catch (Throwable $e) {
            $this->errors->handle($e, RedisOperation::Write);

            return false;
        }
    }

    /**
     * A loader's write: only if the key still holds what $seen found
     * (ADR 0012). A failure follows the "write" policy.
     */
    private function writeLoaded(Definition $definition, string $key, StoredEntry $seen, mixed $value): void
    {
        try {
            $this->entries->writeIfUnchanged($this->repository(), $key, $seen, Envelope::wrap($value), $definition->ttl);
        } catch (Throwable $e) {
            $this->errors->handle($e, RedisOperation::Write);
        }
    }

    /**
     * Leaves a tombstone at the key; false when the cache could not be
     * reached ("invalidate" policy).
     */
    private function bury(string $key): bool
    {
        try {
            return $this->entries->bury($this->repository(), $key);
        } catch (Throwable $e) {
            $this->errors->handle($e, RedisOperation::Invalidate);

            return false;
        }
    }

    /**
     * Deletes the entry, for pull() on stores other than Redis; false when
     * there was none or the cache could not be reached ("invalidate" policy).
     */
    private function remove(string $key): bool
    {
        try {
            return $this->repository()->forget($key);
        } catch (Throwable $e) {
            $this->errors->handle($e, RedisOperation::Invalidate);

            return false;
        }
    }

    private function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
