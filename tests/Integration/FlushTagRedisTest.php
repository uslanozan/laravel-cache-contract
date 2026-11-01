<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Tests\Support\RedisCommandCounter;

/*
 * flushTag() against real Redis (LRVL-40): one write to the tag's counter
 * gives every entry of every tagged definition a new key; the old entries
 * stay until their TTL ends and are never read again.
 */

beforeEach(function () {
    config()->set('database.redis.client', 'phpredis');
    config()->set('database.redis.tags', [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'database' => 1,
        'timeout' => 1.0,
        'read_timeout' => 1.0,
        'max_retries' => 0,
    ]);
    config()->set('cache.stores.tags', ['driver' => 'redis', 'connection' => 'tags']);
    config()->set('cache-contract.store', 'tags');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));

    config()->set('cache-contract.tags', ['users' => 'Data shown about users.']);
    config()->set('cache-contract.definitions', [
        'user' => [
            'profile' => [
                'purpose' => 'profile', 'type' => 'array', 'params' => ['user_id' => 'int'], 'ttl' => 300,
                'description' => 'Profile page data.', 'tags' => ['users'],
            ],
            'avatar' => ['purpose' => 'avatar', 'type' => 'string', 'ttl' => 300, 'description' => 'Avatar URL.', 'tags' => ['users']],
        ],
        'external' => [
            'exchange_rates' => ['purpose' => 'exchange_rates', 'type' => 'array', 'ttl' => 600, 'description' => 'Rates.'],
        ],
    ]);
});

/** @return array<string, int> Redis commands $operation sends on the store's connection */
function flushTagCommands(Closure $operation): array
{
    $store = Cache::store('tags')->getStore();
    assert($store instanceof RedisStore);

    return RedisCommandCounter::count($store->connection(), $operation);
}

it('gives every entry of the tagged definitions a new key with one write', function () {
    CacheContract::put('user.profile', ['user_id' => 1], ['name' => 'Ada']);
    CacheContract::put('user.avatar', [], 'ada.png');
    CacheContract::put('external.exchange_rates', [], ['usd' => 1]);
    $profile = CacheContract::key('user.profile', ['user_id' => 1]);
    $avatar = CacheContract::key('user.avatar');
    $rates = CacheContract::key('external.exchange_rates');

    expect(flushTagCommands(fn () => CacheContract::flushTag('users')))->toBe(['setex' => 1])
        ->and(CacheContract::key('user.profile', ['user_id' => 1]))->not->toBe($profile)
        ->and(CacheContract::key('user.avatar'))->not->toBe($avatar)
        ->and(CacheContract::key('external.exchange_rates'))->toBe($rates)
        // The old entries are never read again and expire through their TTL.
        ->and(Cache::store('tags')->has($profile))->toBeTrue()
        ->and(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse()
        ->and(CacheContract::get('external.exchange_rates'))->toBe(['usd' => 1]);
});
