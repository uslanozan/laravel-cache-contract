<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Facades\ModelCache;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;
use Uslanozan\CacheContract\Tests\Support\RedisCommandCounter;

/*
 * findMany() against real Redis (phpredis): 100 models take one MGET, one
 * query and one bulk write, and large lists are split into chunks.
 */

beforeEach(function () {
    config()->set('database.redis.client', 'phpredis');

    foreach (['find_many' => (int) env('REDIS_PORT', 6379), 'unreachable' => 1] as $connection => $port) {
        config()->set("database.redis.{$connection}", [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => $port,
            'database' => 1,
            'timeout' => 1.0,
            'read_timeout' => 1.0,
            'max_retries' => 0,
        ]);
        config()->set("cache.stores.{$connection}", ['driver' => 'redis', 'connection' => $connection]);
    }

    config()->set('cache-contract.store', 'find_many');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));

    ModelCacheDatabase::create();
});

/**
 * Database queries and Redis commands $operation runs. Redis commands are
 * counted on the store's own connection, so other clients of the server (the
 * CI health check's PING) do not change the result (see RedisCommandCounter).
 *
 * @return array{queries: int, redis: array<string, int>}
 */
function findManyRedisCost(Closure $operation): array
{
    $store = Cache::store('find_many')->getStore();
    assert($store instanceof RedisStore);

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $redis = RedisCommandCounter::count($store->connection(), $operation);
    } finally {
        DB::disableQueryLog();
    }

    return ['queries' => count(DB::getQueryLog()), 'redis' => $redis];
}

it('loads 100 models with one MGET, one query and one bulk write', function () {
    $ids = [1, ...ModelCacheDatabase::addMembers(99)];

    $first = findManyRedisCost(fn () => expect(ModelCache::for(Member::class)->findMany($ids))->toHaveCount(100));
    $second = findManyRedisCost(fn () => expect(ModelCache::for(Member::class)->findMany($ids))->toHaveCount(100));

    // The bulk write is one MULTI … EXEC transaction of 100 SETEX.
    expect($first)->toBe(['queries' => 1, 'redis' => ['exec' => 1, 'mget' => 1, 'multi' => 1, 'setex' => 100]])
        ->and($second)->toBe(['queries' => 0, 'redis' => ['mget' => 1]]);
});

it('splits large lists into chunks', function () {
    config()->set('cache-contract.models.chunk_size', 2);
    $ids = [1, ...ModelCacheDatabase::addMembers(4)];

    $cost = findManyRedisCost(fn () => expect(ModelCache::for(Member::class)->findMany($ids))->toHaveCount(5));

    expect($cost['queries'])->toBe(3)
        ->and($cost['redis']['mget'])->toBe(3)
        ->and($cost['redis']['exec'])->toBe(3)
        ->and($cost['redis']['setex'])->toBe(5);
});

it('loads from the database when Redis is unreachable', function () {
    config()->set('cache-contract.store', 'unreachable');
    ModelCacheDatabase::addMembers(2);
    Exceptions::fake();

    expect(ModelCache::for(Member::class)->findMany([3, 1, 2])->pluck('id')->all())->toBe([3, 1, 2]);

    Exceptions::assertReported(RedisException::class);
});
