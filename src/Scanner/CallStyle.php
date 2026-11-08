<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Scanner;

/**
 * How the code reached the cache.
 *
 * @internal prototype (LRVL-18), the public scanner API is designed in phase 5
 */
enum CallStyle: string
{
    /** Cache::get(...), also through Cache::store(...) or Cache::tags(...). */
    case Facade = 'facade';

    /** cache('key') and cache()->get(...). */
    case Helper = 'helper';

    /** A property or parameter typed as a cache repository or manager. */
    case Repository = 'repository';

    /** app('cache'), resolve('cache.store') or $app['cache']. */
    case Container = 'container';

    /** Redis::get(...): direct Redis, not the cache API. */
    case Redis = 'redis';

    /** A listed method of a wrapper class from scanner.wrappers; the cache call itself is inside the wrapper. */
    case Wrapper = 'wrapper';

    /** RateLimiter::hit(...): the framework's rate limiter, which keeps its counters in the cache. */
    case RateLimiter = 'rate-limiter';

    /** A 'throttle:60,1' middleware string: the rate limiter again, through middleware. */
    case Throttle = 'throttle';

    public function category(): Category
    {
        return match ($this) {
            self::Facade, self::Helper, self::Repository, self::Container => Category::Cache,
            self::Redis => Category::Redis,
            self::RateLimiter, self::Throttle => Category::Indirect,
            self::Wrapper => Category::Wrapper,
        };
    }
}
