<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Eloquent\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Uslanozan\CacheContract\Eloquent\ModelCache;
use Uslanozan\CacheContract\Eloquent\ModelCacheFactory;
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
 *
 * The service is resolved from the container on every call and nothing is
 * kept on the model class, so no tenant or other request state carries over
 * between requests on Octane or between jobs on a queue worker.
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
}
