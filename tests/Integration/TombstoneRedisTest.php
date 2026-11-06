<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Runtime\Tombstone;
use Uslanozan\CacheContract\Tests\Support\RedisClient;

// ADR 0012 against real Redis: removals leave a tombstone, and a loader writes
// only if the key still holds what its read found, in one Lua script.
//
// The client comes from REDIS_CLIENT (phpredis by default; see RedisClient), so
// the same tests run on predis. Tests of phpredis' own options skip on predis.

beforeEach(function () {
    config()->set('database.redis.client', RedisClient::name());
    config()->set('database.redis.contract', [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'database' => 1,
        'timeout' => 1.0,
        'read_timeout' => 1.0,
        'max_retries' => 0,
    ]);
    config()->set('cache.stores.contract', ['driver' => 'redis', 'connection' => 'contract']);
    config()->set('cache-contract.store', 'contract');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));
    config()->set('cache-contract.definitions', [
        'user' => [
            'profile' => [
                'purpose' => 'profile', 'type' => 'array', 'params' => ['user_id' => 'int'],
                'ttl' => 300, 'description' => 'Profile page data.',
            ],
        ],
    ]);
});

/**
 * Rebuilds the contract connection with these options. A prefix works on both
 * clients; serializer and compression are phpredis' own, so a test that sets
 * them skips on predis.
 *
 * @param  array<string, int|string>  $options  e.g. ['serializer' => Redis::SERIALIZER_PHP]
 */
function tombstoneConnection(array $options): void
{
    if (array_diff_key($options, ['prefix' => true]) !== []) {
        RedisClient::skipUnlessPhpRedis('serializer and compression options');
    }

    config()->set('database.redis.contract.options', $options);
    // The Redis manager reads its config once, when it is built: build it again.
    app('redis')->purge('contract');
    app()->forgetInstance('redis');
    Cache::forgetDriver('contract');

    if (RedisClient::isPhpRedis()) {
        /** @var Redis $client */
        $client = contractConnection()->client();

        expect($client->getOption(Redis::OPT_SERIALIZER))->toBe($options['serializer'] ?? Redis::SERIALIZER_NONE)
            ->and($client->getOption(Redis::OPT_COMPRESSION))->toBe($options['compression'] ?? Redis::COMPRESSION_NONE);
    }
}

/** The raw key of user.profile for this user, with the store prefix (not the connection's). */
function rawProfileKey(int $userId): string
{
    /** @var RedisStore $store */
    $store = Cache::store('contract')->getStore();

    return $store->getPrefix().CacheContract::key('user.profile', ['user_id' => $userId]);
}

/** The store's own connection: Laravel's commands work the same on both clients. */
function contractConnection(): Connection
{
    /** @var RedisStore $store */
    $store = Cache::store('contract')->getStore();

    return $store->connection();
}

dataset('tombstone connection options', [
    'no serializer' => [[]],
    'phpredis PHP serializer' => [['serializer' => Redis::SERIALIZER_PHP]],
    'lzf compression' => [['compression' => Redis::COMPRESSION_LZF]],
    'PHP serializer and zstd compression' => [['serializer' => Redis::SERIALIZER_PHP, 'compression' => Redis::COMPRESSION_ZSTD]],
]);

describe('the race from #43', function () {
    it('does not write back a value loaded before a forget', function (array $options) {
        tombstoneConnection($options);

        $value = CacheContract::remember('user.profile', ['user_id' => 1], function () {
            CacheContract::forget('user.profile', ['user_id' => 1]); // the row changes while the loader runs

            return ['name' => 'old'];
        });

        expect($value)->toBe(['name' => 'old'])
            ->and(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse()
            ->and(CacheContract::remember('user.profile', ['user_id' => 1], fn () => ['name' => 'new']))->toBe(['name' => 'new'])
            ->and(CacheContract::get('user.profile', ['user_id' => 1]))->toBe(['name' => 'new']);
    })->with('tombstone connection options');

    it('does not write back a value loaded before a pull', function (array $options) {
        tombstoneConnection($options);

        // The read misses; while the loader runs, another request caches a value and pulls it.
        CacheContract::remember('user.profile', ['user_id' => 1], function () {
            CacheContract::put('user.profile', ['user_id' => 1], ['name' => 'pulled']);
            CacheContract::pull('user.profile', ['user_id' => 1]);

            return ['name' => 'old'];
        });

        expect(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse();
    })->with('tombstone connection options');

    it('caches at once a value loaded after the removal', function (array $options) {
        tombstoneConnection($options);
        CacheContract::put('user.profile', ['user_id' => 1], ['name' => 'old']);
        CacheContract::forget('user.profile', ['user_id' => 1]);

        CacheContract::remember('user.profile', ['user_id' => 1], fn () => ['name' => 'new']);

        expect(CacheContract::get('user.profile', ['user_id' => 1]))->toBe(['name' => 'new']);
    })->with('tombstone connection options');

    it('does not write over a second removal that happens while the loader runs', function () {
        CacheContract::forget('user.profile', ['user_id' => 1]);

        CacheContract::remember('user.profile', ['user_id' => 1], function () {
            CacheContract::forget('user.profile', ['user_id' => 1]); // a new token

            return ['name' => 'old'];
        });

        expect(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse();
    });
});

describe('forget', function () {
    it('leaves a tombstone for tombstone_ttl seconds, also when nothing was cached', function () {
        config()->set('cache-contract.tombstone_ttl', 5);

        expect(CacheContract::forget('user.profile', ['user_id' => 1]))->toBeTrue()
            ->and(contractConnection()->ttl(rawProfileKey(1)))->toBeGreaterThan(0)->toBeLessThanOrEqual(5)
            ->and(Tombstone::is(Cache::store('contract')->get(CacheContract::key('user.profile', ['user_id' => 1]))))->toBeTrue();
    });
});

describe('add', function () {
    it('caches over a tombstone', function () {
        CacheContract::put('user.profile', ['user_id' => 1], 'old');
        CacheContract::forget('user.profile', ['user_id' => 1]);

        expect(CacheContract::add('user.profile', ['user_id' => 1], 'a'))->toBeTrue()
            ->and(CacheContract::add('user.profile', ['user_id' => 1], 'b'))->toBeFalse()
            ->and(CacheContract::get('user.profile', ['user_id' => 1]))->toBe('a')
            ->and(contractConnection()->ttl(rawProfileKey(1)))->toBeGreaterThan(290);
    });

    it('counts a cached null and a foreign value as cached', function () {
        CacheContract::put('user.profile', ['user_id' => 1], null);
        Cache::store('contract')->put(CacheContract::key('user.profile', ['user_id' => 2]), 'legacy', 300);

        expect(CacheContract::add('user.profile', ['user_id' => 1], 'a'))->toBeFalse()
            ->and(CacheContract::add('user.profile', ['user_id' => 2], 'a'))->toBeFalse();
    });
});

describe('pull', function () {
    it('replaces a tombstone already there instead of removing it', function () {
        CacheContract::forget('user.profile', ['user_id' => 1]);
        $before = Cache::store('contract')->get(CacheContract::key('user.profile', ['user_id' => 1]));

        expect(CacheContract::pull('user.profile', ['user_id' => 1], 'default'))->toBe('default');

        $after = Cache::store('contract')->get(CacheContract::key('user.profile', ['user_id' => 1]));
        expect(Tombstone::is($after))->toBeTrue()
            ->and($after)->not->toBe($before);
    });

    it('leaves an empty key empty', function () {
        expect(CacheContract::pull('user.profile', ['user_id' => 1], 'default'))->toBe('default')
            ->and(contractConnection()->exists(rawProfileKey(1)))->toBe(0);
    });

    it('decodes a pulled value like a read', function (array $options) {
        tombstoneConnection($options);
        $values = [['token' => 'abc'], 0, null, false, '', 7, 1.5, 'cache-contract'];

        foreach ($values as $i => $value) {
            CacheContract::put('user.profile', ['user_id' => $i], $value);
        }

        expect(array_map(fn (int $i): mixed => CacheContract::pull('user.profile', ['user_id' => $i], 'missing'), array_keys($values)))
            ->toBe($values);
    })->with('tombstone connection options');
});

// Laravel's default config/database.php sets a connection prefix (REDIS_PREFIX,
// "<app>_database_"), so most applications run with one. The client adds it to
// KEYS[1] of EVAL as it does to GET and SETEX, so the scripts see the same key.
it('works under a connection prefix, as Laravel sets by default', function () {
    $connectionPrefix = 'probeapp_database_';
    // No prefix, not even the global one (Testbench sets "laravel-database-"): sees
    // the keys as Redis stores them.
    config()->set('database.redis.bare', [...config('database.redis.contract'), 'options' => ['prefix' => '']]);
    tombstoneConnection(['prefix' => $connectionPrefix]);
    $bare = app('redis')->connection('bare');

    // The race from #43 stays closed.
    CacheContract::remember('user.profile', ['user_id' => 1], function () {
        CacheContract::forget('user.profile', ['user_id' => 1]);

        return ['name' => 'old'];
    });
    expect(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse();
    CacheContract::remember('user.profile', ['user_id' => 1], fn () => ['name' => 'new']);
    expect(CacheContract::get('user.profile', ['user_id' => 1]))->toBe(['name' => 'new']);

    // The tombstone lands under the connection prefix, nowhere else.
    CacheContract::forget('user.profile', ['user_id' => 2]);
    expect($bare->exists($connectionPrefix.rawProfileKey(2)))->toBe(1)
        ->and($bare->exists(rawProfileKey(2)))->toBe(0);

    // pull() and add() read and write the same keys.
    CacheContract::put('user.profile', ['user_id' => 3], 'once');
    expect(CacheContract::pull('user.profile', ['user_id' => 3], 'default'))->toBe('once')
        ->and(CacheContract::pull('user.profile', ['user_id' => 3], 'default'))->toBe('default')
        ->and($bare->ttl($connectionPrefix.rawProfileKey(3)))->toBeGreaterThan(0)->toBeLessThanOrEqual(10)
        ->and(CacheContract::add('user.profile', ['user_id' => 3], 'a'))->toBeTrue()
        ->and(CacheContract::add('user.profile', ['user_id' => 3], 'b'))->toBeFalse()
        ->and(CacheContract::get('user.profile', ['user_id' => 3]))->toBe('a');
});

it('repairs a key that holds a value written by other code', function () {
    Cache::store('contract')->put(CacheContract::key('user.profile', ['user_id' => 1]), 'legacy', 300);

    CacheContract::remember('user.profile', ['user_id' => 1], fn () => ['name' => 'Ozan']);

    expect(CacheContract::get('user.profile', ['user_id' => 1]))->toBe(['name' => 'Ozan']);
});

// A command Redis rejects inside a script makes the script fail. phpredis
// answers that with false, keeping the error aside (a rejected EVAL itself, or
// a rejected plain command, throws instead), and the package throws it; predis
// throws a ServerException itself. A user without SETEX can read and run
// scripts, but every script that writes fails with an ACL error.
describe('a script Redis rejects', function () {
    beforeEach(function () {
        $this->user = 'cc_nosetex_'.bin2hex(random_bytes(4));
        contractConnection()->command('acl', ['SETUSER', $this->user, 'on', '>secret', '~*', '+@all', '-setex']);

        config()->set('database.redis.contract.username', $this->user);
        config()->set('database.redis.contract.password', 'secret');
        tombstoneConnection([]);
        Exceptions::fake();
    });

    afterEach(function () {
        config()->set('database.redis.contract.username', null);
        config()->set('database.redis.contract.password', null);
        tombstoneConnection([]);
        contractConnection()->command('acl', ['DELUSER', $this->user]);
    });

    it('is reported by remember() as a failed write, and nothing is cached', function () {
        expect(CacheContract::remember('user.profile', ['user_id' => 1], fn () => 'loaded'))->toBe('loaded')
            ->and(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse();

        RedisClient::assertRejectedCommandReported('ACL failure in script');
    });

    it('is reported by add() instead of answering "already cached"', function () {
        expect(CacheContract::add('user.profile', ['user_id' => 1], 'a'))->toBeFalse();

        RedisClient::assertRejectedCommandReported('ACL failure in script');
    });

    it('is reported by pull() instead of taking it for a miss', function () {
        // put() uses SETEX, which this user cannot run: write the envelope with SET.
        contractConnection()->set(rawProfileKey(1), serialize(['once']), 'EX', 300);

        expect(CacheContract::pull('user.profile', ['user_id' => 1], 'default'))->toBe('default')
            ->and(CacheContract::get('user.profile', ['user_id' => 1]))->toBe('once');

        RedisClient::assertRejectedCommandReported('ACL failure in script');
    });

    it('is thrown when the policy says so', function () {
        config()->set('cache-contract.redis_errors.write', 'throw');

        expect(fn () => CacheContract::remember('user.profile', ['user_id' => 1], fn () => 'loaded'))
            ->toThrow(RedisClient::rejectedCommandError(), 'ACL failure in script');
    });
});
