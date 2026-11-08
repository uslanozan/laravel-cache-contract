<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Facades\ModelCache;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;
use Uslanozan\CacheContract\Tests\Support\QueryCounter;
use Uslanozan\CacheContract\Tests\Support\RedisClient;
use Uslanozan\CacheContract\Tests\Support\RedisCommandCounter;

/*
 * findMany() against real Redis (phpredis): 100 models take one read of the
 * model's generation counter, one MGET, one query and one bulk write, and
 * large lists are split into chunks.
 */

beforeEach(function () {
    config()->set('database.redis.client', RedisClient::name());

    foreach (['find_many' => true, 'unreachable' => false] as $connection => $reachable) {
        config()->set("database.redis.{$connection}", RedisClient::connection($reachable));
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

    $redis = [];
    $queries = QueryCounter::count(function () use ($store, $operation, &$redis) {
        $redis = RedisCommandCounter::count($store->connection(), $operation);
    });

    return ['queries' => $queries, 'redis' => $redis];
}

it('loads 100 models with one counter read, one MGET, one query and one write per model', function () {
    $ids = [1, ...ModelCacheDatabase::addMembers(99)];

    $first = findManyRedisCost(fn () => expect(ModelCache::for(Member::class)->findMany($ids))->toHaveCount(100));
    $second = findManyRedisCost(fn () => expect(ModelCache::for(Member::class)->findMany($ids))->toHaveCount(100));

    // One MGET reads the generation counter (ADR 0009), created once with an
    // atomic EVAL; one MGET reads the entries; each model is written with its
    // own compare-and-set EVAL (ADR 0012): 1 + 100.
    expect($first)->toBe(['queries' => 1, 'redis' => ['eval' => 101, 'mget' => 2]])
        ->and($second)->toBe(['queries' => 0, 'redis' => ['mget' => 2]]);
});

it('splits large lists into chunks', function () {
    config()->set('cache-contract.models.chunk_size', 2);
    $ids = [1, ...ModelCacheDatabase::addMembers(4)];

    $cost = findManyRedisCost(fn () => expect(ModelCache::for(Member::class)->findMany($ids))->toHaveCount(5));

    // Three chunks of entries, plus one read of the generation counter; one
    // compare-and-set per model, plus the EVAL that creates the counter.
    expect($cost['queries'])->toBe(3)
        ->and($cost['redis'])->toBe(['eval' => 6, 'mget' => 4]);
});

it('loads from the database when Redis is unreachable', function () {
    config()->set('cache-contract.store', 'unreachable');
    ModelCacheDatabase::addMembers(2);
    Exceptions::fake();

    expect(ModelCache::for(Member::class)->findMany([3, 1, 2])->pluck('id')->all())->toBe([3, 1, 2]);

    Exceptions::assertReported(RedisClient::connectionError());
});
