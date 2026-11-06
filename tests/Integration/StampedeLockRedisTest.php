<?php

declare(strict_types=1);

use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Uslanozan\CacheContract\Facades\CacheContract;

// The stampede lock of ADR 0012 against real Redis, through Laravel's
// RedisLock on the store's own connection. The client comes from REDIS_CLIENT
// (phpredis by default), as in TombstoneRedisTest.

beforeEach(function () {
    $client = env('REDIS_CLIENT', 'phpredis');
    config()->set('database.redis.client', is_string($client) && $client !== '' ? $client : 'phpredis');
    config()->set('database.redis.lock_redis', [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'database' => 1,
        'timeout' => 1.0,
        'read_timeout' => 1.0,
        'max_retries' => 0,
    ]);
    config()->set('cache.stores.lock_redis', ['driver' => 'redis', 'connection' => 'lock_redis']);
    config()->set('cache-contract.store', 'lock_redis');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));
    config()->set('cache-contract.definitions', [
        'report' => [
            'totals' => [
                'purpose' => 'totals', 'type' => 'array', 'params' => ['year' => 'int'], 'ttl' => 600,
                'description' => 'Totals.', 'lock' => ['seconds' => 10, 'wait' => 0],
            ],
        ],
    ]);
});

/** The lock's name as the store sees it, and the raw Redis key with the store prefix. */
function totalsLockName(int $year): string
{
    return CacheContract::key('report.totals', ['year' => $year]).'|lock';
}

function lockRedisConnection(): Connection
{
    /** @var RedisStore $store */
    $store = Cache::store('lock_redis')->getStore();

    return $store->connection();
}

function rawLockKey(int $year): string
{
    /** @var RedisStore $store */
    $store = Cache::store('lock_redis')->getStore();

    return $store->getPrefix().totalsLockName($year);
}

it('holds "{key}|lock" in Redis while the loader runs, with the lock seconds, and removes it afterwards', function () {
    $during = [];

    $value = CacheContract::remember('report.totals', ['year' => 2026], function () use (&$during) {
        $during = [lockRedisConnection()->exists(rawLockKey(2026)), lockRedisConnection()->ttl(rawLockKey(2026))];

        return ['sum' => 42];
    });

    expect($value)->toBe(['sum' => 42])
        ->and($during[0])->toBe(1)
        ->and($during[1])->toBeGreaterThan(0)->toBeLessThanOrEqual(10)
        ->and(lockRedisConnection()->exists(rawLockKey(2026)))->toBe(0)
        ->and(CacheContract::get('report.totals', ['year' => 2026]))->toBe(['sum' => 42]);
});

it('reads again with the lock and returns the value the holder cached', function () {
    Event::listen(CacheMissed::class, function () {
        Event::forget(CacheMissed::class);
        CacheContract::put('report.totals', ['year' => 2026], ['sum' => 'from the holder']);
    });

    expect(CacheContract::remember('report.totals', ['year' => 2026], fn () => ['sum' => 'mine']))->toBe(['sum' => 'from the holder']);
});

it('loads after the wait without overwriting what the holder wrote', function () {
    Cache::store('lock_redis')->lock(totalsLockName(2026), 10)->get(); // another request holds it

    $value = CacheContract::remember('report.totals', ['year' => 2026], function () {
        CacheContract::put('report.totals', ['year' => 2026], ['sum' => 'from the holder']);

        return ['sum' => 'mine'];
    });

    expect($value)->toBe(['sum' => 'mine'])
        ->and(CacheContract::get('report.totals', ['year' => 2026]))->toBe(['sum' => 'from the holder'])
        ->and(lockRedisConnection()->exists(rawLockKey(2026)))->toBe(1); // still the holder's
});

it('caches after the wait when the holder wrote nothing', function () {
    Cache::store('lock_redis')->lock(totalsLockName(2026), 10)->get();

    CacheContract::remember('report.totals', ['year' => 2026], fn () => ['sum' => 'mine']);

    expect(CacheContract::get('report.totals', ['year' => 2026]))->toBe(['sum' => 'mine']);
});

it('names the lock after the entry key, so each parameter set and generation has its own', function () {
    config()->set('cache-contract.tags', ['reports' => 'Every report.']);
    config()->set('cache-contract.definitions.report.totals.tags', ['reports']);

    $before = totalsLockName(2026);
    CacheContract::flushTag('reports');

    expect(totalsLockName(2025))->not->toBe($before)
        ->and(totalsLockName(2026))->not->toBe($before)
        ->and(totalsLockName(2026))->toEndWith('|lock')
        ->and(substr_count(totalsLockName(2026), '|'))->toBe(1);
});
