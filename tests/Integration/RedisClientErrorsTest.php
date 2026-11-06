<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Redis\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Predis\Connection\ConnectionException;
use Predis\Response\ServerException;
use Uslanozan\CacheContract\Facades\CacheContract;

/*
 * The Redis error policy on both supported clients, against real Redis
 * (ADR 0007, update 2026-11-06). Every test runs on phpredis and on predis,
 * whatever REDIS_CLIENT says: the point is that both clients behave the same.
 *
 * phpredis throws a RedisException for a connection error and for a plain
 * command Redis rejects; predis throws a ConnectionException and a
 * ServerException. A failed script is a RedisException the package throws on
 * phpredis, and a ServerException on predis.
 */

dataset('redis clients', ['phpredis', 'predis']);

beforeEach(function () {
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));
    // Every failure is reported, so each test can count on its own report.
    config()->set('cache-contract.redis_errors.report_interval', 0);
    config()->set('cache-contract.tags', ['users' => 'Data shown about users.']);
    config()->set('cache-contract.definitions', [
        'external' => [
            'exchange_rates' => ['purpose' => 'exchange_rates', 'type' => 'array', 'ttl' => 600, 'description' => 'Rates.'],
        ],
        'user' => [
            'profile' => [
                'purpose' => 'profile', 'type' => 'array', 'params' => ['user_id' => 'int'], 'ttl' => 300,
                'description' => 'Profile page data.', 'tags' => ['users'],
            ],
        ],
    ]);
});

/**
 * Points the package at a Redis store on this client: the real server, or a
 * port nothing listens on. With a user, the connection logs in as that user.
 */
function useRedisClient(string $client, bool $reachable = true, ?string $user = null): void
{
    config()->set('database.redis.client', $client);
    config()->set('database.redis.clients', array_filter([
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => $reachable ? (int) env('REDIS_PORT', 6379) : 1,
        'database' => 1,
        'timeout' => 1.0,
        'read_timeout' => 1.0,        // phpredis
        'read_write_timeout' => 1.0,  // predis
        'max_retries' => 0,
        'username' => $user,
        'password' => $user === null ? null : 'secret',
    ], fn (mixed $value): bool => $value !== null));
    config()->set('cache.stores.clients', ['driver' => 'redis', 'connection' => 'clients']);
    config()->set('cache-contract.store', 'clients');

    // The Redis manager and the store read their config once: build them again.
    app()->forgetInstance('redis');
    Cache::forgetDriver('clients');
}

/** @return class-string<Throwable> what this client throws when Redis cannot be reached */
function connectionErrorOf(string $client): string
{
    return $client === 'phpredis' ? RedisException::class : ConnectionException::class;
}

/** @return class-string<Throwable> what this client throws for a command Redis rejects */
function rejectedCommandErrorOf(string $client): string
{
    return $client === 'phpredis' ? RedisException::class : ServerException::class;
}

/**
 * Asserts that a command Redis rejected was reported, with this text in its
 * message. The fake matches a closure by its exact parameter type, so the
 * closure is typed for the client.
 */
function assertRejectedCommandReported(string $client, string $needle): void
{
    $client === 'phpredis'
        ? Exceptions::assertReported(fn (RedisException $e): bool => str_contains($e->getMessage(), $needle))
        : Exceptions::assertReported(fn (ServerException $e): bool => str_contains($e->getMessage(), $needle));
}

/** The store's connection, logged in as the default user. */
function clientsConnection(): Connection
{
    /** @var RedisStore $store */
    $store = Cache::store('clients')->getStore();

    return $store->connection();
}

/**
 * Creates a Redis user that may run every command but these, and logs the
 * package in as that user. Returns the user's name, for deleteRedisUser().
 *
 * @param  list<string>  $denied  e.g. ['get'] or ['setex']
 */
function loginAsUserWithout(string $client, array $denied): string
{
    useRedisClient($client);
    $user = 'cc_clients_'.bin2hex(random_bytes(4));
    clientsConnection()->command('acl', ['SETUSER', $user, 'on', '>secret', '~*', '+@all', ...array_map(fn (string $command): string => '-'.$command, $denied)]);
    useRedisClient($client, user: $user);

    return $user;
}

function deleteRedisUser(string $client, string $user): void
{
    useRedisClient($client);
    clientsConnection()->command('acl', ['DELUSER', $user]);
}

describe('when Redis cannot be reached', function () {
    it('keeps the application working with "report"', function (string $client) {
        useRedisClient($client, reachable: false);
        Exceptions::fake();

        expect(CacheContract::get('external.exchange_rates', [], 'default'))->toBe('default')
            ->and(CacheContract::has('external.exchange_rates'))->toBeFalse()
            ->and(CacheContract::remember('external.exchange_rates', [], fn () => ['usd' => 1]))->toBe(['usd' => 1])
            ->and(CacheContract::put('external.exchange_rates', [], ['usd' => 1]))->toBeFalse()
            ->and(CacheContract::add('external.exchange_rates', [], ['usd' => 1]))->toBeFalse()
            ->and(CacheContract::pull('external.exchange_rates', [], 'default'))->toBe('default')
            ->and(CacheContract::forget('external.exchange_rates'))->toBeFalse()
            ->and(CacheContract::flushTag('users'))->toBeFalse()
            ->and(CacheContract::get('user.profile', ['user_id' => 1], 'default'))->toBe('default');

        Exceptions::assertReported(connectionErrorOf($client));
        Exceptions::assertReportedCount(9);
    })->with('redis clients');

    it('throws for the operations set to "throw"', function (string $client) {
        useRedisClient($client, reachable: false);
        config()->set('cache-contract.redis_errors', ['read' => 'throw', 'write' => 'throw', 'invalidate' => 'throw', 'report_interval' => 0]);

        expect(fn () => CacheContract::get('external.exchange_rates'))->toThrow(connectionErrorOf($client))
            ->and(fn () => CacheContract::put('external.exchange_rates', [], ['usd' => 1]))->toThrow(connectionErrorOf($client))
            ->and(fn () => CacheContract::forget('external.exchange_rates'))->toThrow(connectionErrorOf($client));
    })->with('redis clients');
});

describe('a command Redis rejects', function () {
    afterEach(function () {
        if (isset($this->user)) {
            deleteRedisUser($this->client, $this->user);
        }
    });

    it('follows "read" for a read', function (string $client) {
        $this->client = $client;
        $this->user = loginAsUserWithout($client, ['get']);
        Exceptions::fake();

        expect(CacheContract::get('external.exchange_rates', [], 'default'))->toBe('default')
            ->and(CacheContract::remember('external.exchange_rates', [], fn () => ['usd' => 1]))->toBe(['usd' => 1]);
        assertRejectedCommandReported($client, 'NOPERM');
    })->with('redis clients');

    it('is thrown when "read" is "throw"', function (string $client) {
        $this->client = $client;
        $this->user = loginAsUserWithout($client, ['get']);
        config()->set('cache-contract.redis_errors.read', 'throw');

        expect(fn () => CacheContract::get('external.exchange_rates'))->toThrow(rejectedCommandErrorOf($client), 'NOPERM');
    })->with('redis clients');

    it('follows "write" for a script that fails inside Redis, and nothing is cached', function (string $client) {
        $this->client = $client;
        // Reads and scripts work; every write, inside a script or not, is rejected.
        $this->user = loginAsUserWithout($client, ['setex']);
        Exceptions::fake();

        expect(CacheContract::remember('external.exchange_rates', [], fn () => ['usd' => 1]))->toBe(['usd' => 1])
            ->and(CacheContract::add('external.exchange_rates', [], ['usd' => 1]))->toBeFalse()
            ->and(CacheContract::has('external.exchange_rates'))->toBeFalse();
        assertRejectedCommandReported($client, 'ACL failure in script');
    })->with('redis clients');

    it('follows "read" for a pull() whose script finds the wrong type', function (string $client) {
        useRedisClient($client);
        /** @var RedisStore $store */
        $store = Cache::store('clients')->getStore();
        clientsConnection()->command('hset', [$store->getPrefix().CacheContract::key('external.exchange_rates'), 'field', 'value']);
        Exceptions::fake();

        expect(CacheContract::pull('external.exchange_rates', [], 'default'))->toBe('default');
        assertRejectedCommandReported($client, 'WRONGTYPE');
    })->with('redis clients');

    it('follows "read" when the generation counters cannot be read', function (string $client) {
        if ($client === 'phpredis') {
            // phpredis answers a rejected MGET with false or nulls, not an exception (LRVL-79).
            test()->markTestSkipped('phpredis: a rejected MGET is LRVL-79');
        }

        $this->client = $client;
        $this->user = loginAsUserWithout($client, ['mget']);
        Exceptions::fake();

        expect(CacheContract::get('user.profile', ['user_id' => 1], 'default'))->toBe('default')
            ->and(CacheContract::remember('user.profile', ['user_id' => 1], fn () => ['name' => 'Ada']))->toBe(['name' => 'Ada']);
        Exceptions::assertReported(fn (ServerException $e): bool => str_contains($e->getMessage(), 'NOPERM') && str_contains($e->getMessage(), 'mget'));
    })->with('redis clients');
});
