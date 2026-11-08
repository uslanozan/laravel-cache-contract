<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Facades;

use Illuminate\Support\Facades\Facade;
use Uslanozan\CacheContract\Eloquent\ModelCacheFactory;

/**
 * Caches Eloquent models by primary key (ADR 0006):
 *
 *     ModelCache::for(User::class)->find(5);
 *
 * @method static \Uslanozan\CacheContract\Eloquent\ModelCache<\Illuminate\Database\Eloquent\Model> for(class-string<\Illuminate\Database\Eloquent\Model> $class)
 *
 * @see ModelCacheFactory
 */
final class ModelCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ModelCacheFactory::class;
    }
}
