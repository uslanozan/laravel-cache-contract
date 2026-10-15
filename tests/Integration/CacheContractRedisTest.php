<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Facades\CacheContract;

/*
 * The facade against real Redis (phpredis). The `array` store keeps integers
 * as integers and cannot fail, so ADR 0007's promises are checked here.
 */

beforeEach(function () {
    config()->set('database.redis.client', 'phpredis');

    foreach (['contract' => (int) env('REDIS_PORT', 6379), 'unreachable' => 1] as $connection => $port) {
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

    config()->set('cache-contract.store', 'contract');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));
    config()->set('cache-contract.definitions', [
        'external' => [
            'exchange_rates' => ['purpose' => 'exchange_rates', 'type' => 'array', 'ttl' => 600, 'description' => 'Rates.'],
        ],
        'user' => [
            'profile' => [
                'purpose' => 'profile', 'type' => 'array', 'params' => ['user_id' => 'int'],
                'ttl' => 300, 'description' => 'Profile page data.',
            ],
        ],
    ]);
});

it('round-trips every value exactly, falsy values and numbers included', function () {
    $values = [null, false, true, 0, 5, -1, 0.0, 1.5, '', '0', [], [1, 2], ['name' => 'Ozan']];

    foreach ($values as $userId => $value) {
        $calls = 0;
        $loader = function () use (&$calls, $value) {
            $calls++;

            return $value;
        };

        CacheContract::remember('user.profile', ['user_id' => $userId], $loader);

        expect(CacheContract::remember('user.profile', ['user_id' => $userId], $loader))->toBe($value)
            ->and($calls)->toBe(1)
            ->and(CacheContract::has('user.profile', ['user_id' => $userId]))->toBeTrue();
    }
});

it('writes with the TTL of the definition', function () {
    CacheContract::put('user.profile', ['user_id' => 1], ['name' => 'Ozan']);

    /** @var RedisStore $store */
    $store = Cache::store('contract')->getStore();
    $ttl = $store->connection()->ttl($store->getPrefix().CacheContract::key('user.profile', ['user_id' => 1]));

    expect($ttl)->toBeGreaterThan(290)->toBeLessThanOrEqual(300);
});

it('forgets an entry', function () {
    CacheContract::remember('user.profile', ['user_id' => 1], fn () => ['name' => 'Ozan']);

    expect(CacheContract::forget('user.profile', ['user_id' => 1]))->toBeTrue()
        ->and(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse()
        ->and(CacheContract::remember('user.profile', ['user_id' => 1], fn () => ['name' => 'Barış']))->toBe(['name' => 'Barış']);
});

it('keeps tenants apart in Redis', function () {
    CacheContract::withContext(['tenant_id' => 1])->put('external.exchange_rates', [], ['usd' => 1]);
    CacheContract::withContext(['tenant_id' => 2])->put('external.exchange_rates', [], ['usd' => 2]);

    expect(CacheContract::withContext(['tenant_id' => 1])->get('external.exchange_rates'))->toBe(['usd' => 1])
        ->and(CacheContract::withContext(['tenant_id' => 2])->get('external.exchange_rates'))->toBe(['usd' => 2])
        ->and(CacheContract::has('external.exchange_rates'))->toBeFalse();
});

it('keeps the application working when Redis is unreachable', function () {
    config()->set('cache-contract.store', 'unreachable');
    Exceptions::fake();

    expect(CacheContract::remember('external.exchange_rates', [], fn () => ['usd' => 1]))->toBe(['usd' => 1])
        ->and(CacheContract::forget('external.exchange_rates'))->toBeFalse();

    Exceptions::assertReported(RedisException::class);
});

it('throws when Redis is unreachable and the policy says so', function () {
    config()->set('cache-contract.store', 'unreachable');
    config()->set('cache-contract.redis_errors.read', 'throw');

    expect(fn () => CacheContract::remember('external.exchange_rates', [], fn () => 1))->toThrow(RedisException::class);
});
