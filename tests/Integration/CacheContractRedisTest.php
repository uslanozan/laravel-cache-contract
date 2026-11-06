<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Tests\Support\RedisClient;
use Uslanozan\CacheContract\Tests\Support\RedisCommandCounter;

/*
 * The facade against real Redis (phpredis). The `array` store keeps integers
 * as integers and cannot fail, so ADR 0007's promises are checked here.
 */

beforeEach(function () {
    config()->set('database.redis.client', RedisClient::name());

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

/**
 * Puts a list under the definition's key, so Redis rejects the GET in the
 * package's scripts with WRONGTYPE.
 *
 * @param  array<string, mixed>  $params
 */
function putListAt(string $id, array $params): void
{
    /** @var RedisStore $store */
    $store = Cache::store('contract')->getStore();
    $store->connection()->rpush($store->getPrefix().CacheContract::key($id, $params), 'not a cache entry');
}

describe('add and pull', function () {
    it('adds with a read and one script, with the TTL of the definition', function () {
        /** @var RedisStore $store */
        $store = Cache::store('contract')->getStore();
        $results = [];

        // The script checks and writes in one step: nothing can run between them (ADR 0012).
        $commands = RedisCommandCounter::count($store->connection(), function () use (&$results) {
            $results[] = CacheContract::add('user.profile', ['user_id' => 1], ['lock' => 'a']);
        });

        $results[] = CacheContract::add('user.profile', ['user_id' => 1], ['lock' => 'b']);
        $ttl = $store->connection()->ttl($store->getPrefix().CacheContract::key('user.profile', ['user_id' => 1]));

        expect($commands)->toBe(['eval' => 1, 'get' => 1])
            ->and($results)->toBe([true, false])
            ->and(CacheContract::get('user.profile', ['user_id' => 1]))->toBe(['lock' => 'a'])
            ->and($ttl)->toBeGreaterThan(290)->toBeLessThanOrEqual(300);
    });

    it('reads and leaves a tombstone in one script, so nothing can change the entry in between', function () {
        /** @var RedisStore $store */
        $store = Cache::store('contract')->getStore();
        CacheContract::put('user.profile', ['user_id' => 1], ['token' => 'abc']);
        $pulled = null;

        $commands = RedisCommandCounter::count($store->connection(), function () use (&$pulled) {
            $pulled = CacheContract::pull('user.profile', ['user_id' => 1], 'taken');
        });

        $key = $store->getPrefix().CacheContract::key('user.profile', ['user_id' => 1]);

        expect($commands)->toBe(['eval' => 1])
            ->and($pulled)->toBe(['token' => 'abc'])
            ->and(CacheContract::pull('user.profile', ['user_id' => 1], 'taken'))->toBe('taken')
            ->and(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse()
            ->and($store->connection()->ttl($key))->toBeGreaterThan(0)->toBeLessThanOrEqual(10);
    });

    it('decodes a pulled value like a read, also with a phpredis serializer', function (array $values) {
        RedisClient::skipUnlessPhpRedis('the serializer option');
        config()->set('database.redis.contract.options', ['serializer' => Redis::SERIALIZER_PHP]);
        app('redis')->purge('contract');
        Cache::forgetDriver('contract');

        foreach ($values as $i => $value) {
            CacheContract::put('user.profile', ['user_id' => $i], $value);
        }

        expect(array_map(fn (int $i): mixed => CacheContract::pull('user.profile', ['user_id' => $i], 'missing'), array_keys($values)))
            ->toBe($values);
    })->with([
        'mixed values' => [[['token' => 'abc'], 0, null, false, '', 7, 1.5]],
    ]);

    // phpredis answers a script Redis rejects with false, as for a missing key;
    // predis throws it. Either way it must be reported, not taken for a miss.
    // A list at the key makes the script's GET fail with WRONGTYPE.
    it('reports a script Redis rejects instead of taking it for a miss', function () {
        putListAt('user.profile', ['user_id' => 1]);
        Exceptions::fake();

        expect(CacheContract::pull('user.profile', ['user_id' => 1], 'default'))->toBe('default');
        RedisClient::assertRejectedCommandReported('WRONGTYPE');
    });

    it('throws a command Redis rejects when the policy says so', function () {
        config()->set('cache-contract.redis_errors.read', 'throw');
        putListAt('user.profile', ['user_id' => 1]);

        expect(fn () => CacheContract::pull('user.profile', ['user_id' => 1]))->toThrow(RedisClient::rejectedCommandError(), 'WRONGTYPE');
    });

    it('returns the default when Redis is unreachable', function () {
        config()->set('cache-contract.store', 'unreachable');
        Exceptions::fake();

        expect(CacheContract::pull('user.profile', ['user_id' => 1], 'default'))->toBe('default')
            ->and(CacheContract::add('user.profile', ['user_id' => 1], 'x'))->toBeFalse();

        Exceptions::assertReported(RedisClient::connectionError());
    });
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

    Exceptions::assertReported(RedisClient::connectionError());
});

it('reports an outage once per interval, however many calls fail', function () {
    config()->set('cache-contract.store', 'unreachable');
    Exceptions::fake();

    for ($i = 0; $i < 50; $i++) {
        CacheContract::remember('external.exchange_rates', [], fn () => ['usd' => 1]);
        CacheContract::forget('external.exchange_rates');
    }

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(RedisClient::connectionError());
});

it('throws when Redis is unreachable and the policy says so', function () {
    config()->set('cache-contract.store', 'unreachable');
    config()->set('cache-contract.redis_errors.read', 'throw');

    expect(fn () => CacheContract::remember('external.exchange_rates', [], fn () => 1))->toThrow(RedisClient::connectionError());
});
