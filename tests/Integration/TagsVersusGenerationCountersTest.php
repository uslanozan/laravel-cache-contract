<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Redis\RedisManager;
use Uslanozan\CacheContract\Tests\Support\RedisCommandCounter;

/*
 * Characterization tests for ADR 0009: how Laravel's Redis cache tags behave,
 * and the properties of a generation counter kept in the key that the ADR
 * relies on. They pin Laravel's behaviour and the idea, not package code.
 */

const INVALIDATION_PREFIX = 'cache_contract_invalidation:';

function invalidationRedis(): RedisManager
{
    return new RedisManager(app(), 'phpredis', [
        'options' => ['prefix' => ''],
        'cache' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => 1,
            'timeout' => 1.0,
            'read_timeout' => 1.0,
        ],
    ]);
}

function invalidationCache(RedisManager $redis): Repository
{
    return new Repository(new RedisStore($redis, INVALIDATION_PREFIX, 'cache'));
}

function invalidationName(string $kind): string
{
    return $kind.'_'.bin2hex(random_bytes(6));
}

/**
 * Calls per Redis command the "cache" connection sends while $operation runs.
 * Counted on the client, so other clients of the server (the CI health
 * check's PING) do not change the result (see RedisCommandCounter).
 *
 * @return array<string, int>
 */
function invalidationCommandCalls(RedisManager $redis, Closure $operation): array
{
    return RedisCommandCounter::count($redis->connection('cache'), $operation);
}

beforeEach(function () {
    $this->redis = invalidationRedis();
    $this->cache = invalidationCache($this->redis);
});

describe('Laravel cache tags', function () {
    it('reads many tagged keys with one GET each, never MGET', function () {
        $tagged = $this->cache->tags([invalidationName('tag')]);
        $keys = array_map(fn (int $i) => "k:{$i}", range(1, 20));

        $calls = invalidationCommandCalls($this->redis, fn () => $tagged->many($keys));

        expect($calls['get'] ?? 0)->toBe(20)
            ->and($calls['mget'] ?? 0)->toBe(0);
    });

    it('writes many tagged keys with one SETEX and one ZADD each', function () {
        $tagged = $this->cache->tags([invalidationName('tag')]);
        $values = array_fill_keys(array_map(fn (int $i) => "k:{$i}", range(1, 20)), [1]);

        $calls = invalidationCommandCalls($this->redis, fn () => $tagged->putMany($values, 60));

        expect($calls['setex'] ?? 0)->toBe(20)
            ->and($calls['zadd'] ?? 0)->toBe(20);
    });

    it('keeps tag set members after their entries are gone, until stale entries are flushed', function () {
        $tag = invalidationName('tag');
        $tagged = $this->cache->tags([$tag]);
        $connection = $this->redis->connection('cache');
        $set = INVALIDATION_PREFIX."tag:{$tag}:entries";

        $tagged->put('k', [1], 1);
        $connection->del(INVALIDATION_PREFIX.$tagged->taggedItemKey('k'));   // as if it had expired

        expect($connection->zcard($set))->toBe(1);

        $this->travel(5)->seconds();
        $tagged->flushStale();

        expect($connection->zcard($set))->toBe(0);
    });

    it('keeps a value written by a slow loader after the tag was flushed', function () {
        $tagged = $this->cache->tags([invalidationName('tag')]);
        $readBeforeTheChange = 'old row';

        $tagged->flush();
        $tagged->put('k', [$readBeforeTheChange], 60);

        expect($tagged->get('k'))->toBe(['old row']);
    });

    it('lets flexible load a fresh value after a flush', function () {
        $tagged = $this->cache->tags([invalidationName('tag')]);

        $tagged->flexible('list', [60, 600], fn () => ['v1']);
        $tagged->flush();

        expect($tagged->flexible('list', [60, 600], fn () => ['v2']))->toBe(['v2']);
    });
});

describe('a generation counter in the key', function () {
    it('creates a counter atomically only when add() is given a TTL', function () {
        $withTtl = invalidationCommandCalls($this->redis, fn () => $this->cache->add(invalidationName('gen'), 1000, 3600));
        $withoutTtl = invalidationCommandCalls($this->redis, fn () => $this->cache->add(invalidationName('gen'), 1000));

        // With a TTL: one Lua script that checks and writes in one step.
        // Without: a separate GET and SET, so two creators can both write.
        expect($withTtl)->toBe(['eval' => 1])
            ->and($withoutTtl)->toBe(['get' => 1, 'set' => 1]);
    });

    it('invalidates every entry with one write of a new random value', function () {
        $counter = invalidationName('gen');
        $this->cache->add($counter, random_int(1, PHP_INT_MAX >> 1), 3600);
        $key = fn () => 'k:g'.$this->cache->get($counter);
        $this->cache->put($key(), ['v1'], 60);

        $calls = invalidationCommandCalls($this->redis, fn () => $this->cache->put($counter, random_int(1, PHP_INT_MAX >> 1), 3600));

        expect($calls)->toBe(['setex' => 1])
            ->and($this->cache->get($key()))->toBeNull();
    });

    it('never reads a value written by a slow loader under the old generation', function () {
        $counter = invalidationName('gen');
        $this->cache->add($counter, random_int(1, PHP_INT_MAX >> 1), 3600);
        $generationAtLoadStart = $this->cache->get($counter);

        $this->cache->put($counter, random_int(1, PHP_INT_MAX >> 1), 3600);
        $this->cache->put("k:g{$generationAtLoadStart}", ['old row'], 60);

        expect($this->cache->get('k:g'.$this->cache->get($counter)))->toBeNull();
    });

    it('restarts a missing counter at 1 when it is incremented', function () {
        $counter = invalidationName('gen');

        expect($this->cache->increment($counter))->toBe(1);
    });

    it('serves old entries again if a lost counter restarts at a value it had before', function () {
        $counter = invalidationName('gen');
        $this->cache->forever($counter, 1);
        $this->cache->put('k:g1', ['old row'], 60);
        $this->cache->increment($counter);

        $this->cache->forget($counter);
        $this->cache->forever($counter, 1);

        expect($this->cache->get('k:g'.$this->cache->get($counter)))->toBe(['old row']);
    });
});
