<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Eloquent\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Eloquent\ModelCache;
use Uslanozan\CacheContract\Eloquent\ModelCacheFactory;
use Uslanozan\CacheContract\Eloquent\ProvidesCacheContext;
use Uslanozan\CacheContract\Exceptions\CacheContractException;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Exceptions\ModelNotCachable;

/**
 * Shortcuts to the model cache on the model itself (ADR 0006). They only call
 * ModelCache, so the trait and the service always behave the same:
 *
 *     class User extends Model
 *     {
 *         use HasCache;
 *     }
 *
 *     User::cached(5);              // ModelCache::for(User::class)->find(5)
 *     User::cachedMany([3, 1, 2]);  // ModelCache::for(User::class)->findMany([3, 1, 2])
 *     User::modelCache()->withContext(['tenant_id' => 7])->find(5);
 *     $user->forgetCache();         // this model's entries
 *     User::flushCache();           // every entry of the model
 *
 * Saving, deleting or restoring a model clears its entries after the
 * transaction commits (nothing is cleared on rollback). A model whose global
 * scopes need a context gives it through ProvidesCacheContext, and the
 * entries of its previous context are cleared too, so a row moved from
 * tenant 7 to tenant 9 leaves no stale entry behind.
 *
 * The service is resolved from the container on every call and nothing is
 * kept on the model class, so no tenant or other request state carries over
 * between requests on Octane or between jobs on a queue worker. The event
 * listeners are registered once, when the model class boots.
 */
trait HasCache
{
    /**
     * The model cache of this model, for withContext() and withTrashed().
     *
     * @return ModelCache<static>
     *
     * @throws ModelNotCachable
     */
    public static function modelCache(): ModelCache
    {
        return app(ModelCacheFactory::class)->for(static::class);
    }

    /**
     * The model with this primary key, from the cache or the database; null
     * when there is no such row.
     *
     * @throws ModelNotCachable when the model has global scopes that need a context
     * @throws InvalidParameters when the ID does not match the model's key type
     */
    public static function cached(int|string $id): ?static
    {
        return static::modelCache()->find($id);
    }

    /**
     * The models with these primary keys, in the order asked for.
     *
     * @param  array<array-key, int|string>  $ids
     * @return Collection<int, static>
     *
     * @throws ModelNotCachable when the model has global scopes that need a context
     * @throws InvalidParameters when an ID does not match the model's key type
     */
    public static function cachedMany(array $ids): Collection
    {
        return static::modelCache()->findMany($ids);
    }

    /**
     * Removes this model's entries: for its current and, if changed, its
     * previous ID and context, with and without withTrashed().
     *
     * @return bool false when Redis could not be reached
     *
     * @throws ModelNotCachable when the model needs a context and does not implement ProvidesCacheContext
     */
    public function forgetCache(): bool
    {
        $cache = static::modelCache();

        if ($cache->needsContext() && ! $this instanceof ProvidesCacheContext) {
            throw ModelNotCachable::missingCacheContext(static::class);
        }

        $forgotten = true;
        foreach ($this->cacheTargets() as [$context, $id]) {
            $forgotten = $cache->withContext($context)->forget($id) && $forgotten;
        }

        return $forgotten;
    }

    /**
     * Invalidates every entry of this model, for every ID and context
     * (ADR 0009). Use it after changes that fire no model events, such as
     * User::query()->update([...]).
     *
     * @return bool false when Redis could not be reached
     */
    public static function flushCache(): bool
    {
        return static::modelCache()->flush();
    }

    /**
     * Registered once per model class, when it boots.
     */
    public static function bootHasCache(): void
    {
        foreach (['saved', 'deleted', 'restored'] as $event) {
            static::registerModelEvent($event, static function (Model $model): void {
                /** @var static $model */
                $model->clearCacheAfterChange();
            });
        }
    }

    /**
     * Clears this model's entries once the change is committed. Never fails
     * the save or the commit because of the cache setup: the package's own
     * errors (a model it cannot cache, a missing ProvidesCacheContext, an
     * invalid context) are reported instead.
     */
    protected function clearCacheAfterChange(): void
    {
        try {
            $cache = static::modelCache();

            if ($cache->needsContext() && ! $this instanceof ProvidesCacheContext) {
                // Nothing to clear: every read of such a model throws before it caches anything.
                report(ModelNotCachable::missingCacheContext(static::class));

                return;
            }

            // Taken now: after the commit, getOriginal() already holds the new values.
            $targets = $this->cacheTargets();
        } catch (CacheContractException $e) {
            report($e);

            return;
        }

        $this->runAfterCommit(static function () use ($cache, $targets): void {
            foreach ($targets as [$context, $id]) {
                try {
                    $cache->withContext($context)->forget($id);
                } catch (CacheContractException $e) {
                    // Thrown here, it would fail the commit and skip the transaction's
                    // other after-commit callbacks, queued jobs included.
                    report($e);
                }
            }
        });
    }

    /**
     * The (context, ID) pairs this model's entries can be under: its current
     * values and the ones before the change, once each.
     *
     * @return list<array{0: array<string, scalar>, 1: int|string}>
     */
    private function cacheTargets(): array
    {
        $before = $this->newInstance([], true);
        $before->setRawAttributes($this->getRawOriginal(), true);

        /** @var array<string, array{0: array<string, scalar>, 1: int|string}> $targets */
        $targets = [];
        foreach ([$this, $before] as $model) {
            $id = $model->getKey();

            if (! is_int($id) && ! is_string($id)) {
                continue; // a new model has no previous key
            }

            /** @var array<string, scalar> $context */
            $context = $model instanceof ProvidesCacheContext ? $model->cacheContext() : [];
            $targets[serialize([$context, $id])] = [$context, $id];
        }

        return array_values($targets);
    }

    /**
     * Runs $callback after the current transaction on this model's
     * connection commits, or now when there is none; never on rollback.
     */
    private function runAfterCommit(Closure $callback): void
    {
        $connection = $this->getConnection();

        // afterCommit() alone attaches it to the last open transaction on any connection.
        // shortcut: a transaction on another connection opened inside one on this connection still gets it; register per connection if that case shows up.
        if ($connection->transactionLevel() === 0) {
            $callback();
        } else {
            $connection->afterCommit($callback);
        }
    }
}
