<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Redis\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Runtime\GenerationCounters;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;
use Uslanozan\CacheContract\Tests\Support\RedisClient;

/*
 * An MGET Redis rejects, against real Redis (LRVL-79), on the client in
 * REDIS_CLIENT. phpredis answers it with false and keeps the error aside; the
 * package throws that error as a RedisException. predis throws a
 * ServerException itself. Either way it follows the "read" policy instead of
 * every generation counter (or every model in findMany()) looking missing.
 *
 * The package logs in as a Redis user that may not run MGET: GET, SET and
 * scripts work, so only the MGET is rejected.
 */

beforeEach(function () {
    config()->set('database.redis.client', RedisClient::name());
    config()->set('database.redis.mget', RedisClient::connection());
    config()->set('cache.stores.mget', ['driver' => 'redis', 'connection' => 'mget']);
    config()->set('cache-contract.store', 'mget');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));
    config()->set('cache-contract.redis_errors.report_interval', 0);
    config()->set('cache-contract.tags', ['users' => 'Data shown about users.']);
    config()->set('cache-contract.definitions', [
        'user' => [
            'profile' => [
                'purpose' => 'profile', 'type' => 'array', 'params' => ['user_id' => 'int'],
                'ttl' => 300, 'tags' => ['users'], 'description' => 'Profile page data.',
            ],
        ],
    ]);
});

afterEach(function () {
    if (isset($this->user)) {
        loginToMget(null);
        mgetConnection()->command('acl', ['DELUSER', $this->user]);
    }
});

/** Logs the package's store in as this Redis user, or as the default one. */
function loginToMget(?string $user): void
{
    config()->set('database.redis.mget.username', $user);
    config()->set('database.redis.mget.password', $user === null ? null : 'secret');
    app()->forgetInstance('redis');
    Cache::forgetDriver('mget');
}

function mgetConnection(): Connection
{
    /** @var RedisStore $store */
    $store = Cache::store('mget')->getStore();

    return $store->connection();
}

/**
 * Creates a user with these ACL rules on top of every command, and logs the
 * package in as that user.
 *
 * @param  list<string>  $rules  e.g. ['-mget']
 */
function loginAsMgetUser(array $rules): string
{
    $user = 'cc_mget_'.bin2hex(random_bytes(4));
    mgetConnection()->command('acl', ['SETUSER', $user, 'on', '>secret', '+@all', ...$rules]);
    loginToMget($user);

    return $user;
}

/** The raw value of the "users" counter, read as the default user: null when it does not exist. */
function usersCounter(): ?int
{
    loginToMget(null);
    $value = app(GenerationCounters::class)->peek(['users'])['users'];

    return $value;
}

describe('the generation counters', function () {
    it('follow "read" when their MGET is rejected, and an existing counter is not overwritten', function () {
        $before = app(GenerationCounters::class)->currentOrFail(['users'])['users'];
        $this->user = loginAsMgetUser(['~*', '-mget']);
        Exceptions::fake();

        expect(CacheContract::get('user.profile', ['user_id' => 1], 'default'))->toBe('default')
            ->and(CacheContract::remember('user.profile', ['user_id' => 1], fn () => ['name' => 'Ada']))->toBe(['name' => 'Ada']);

        RedisClient::assertRejectedCommandReported("run the 'mget' command");
        Exceptions::assertReportedCount(2);
        expect(usersCounter())->toBe($before);
    });

    it('are not created when their MGET is rejected', function () {
        $this->user = loginAsMgetUser(['~*', '-mget']);
        Exceptions::fake();

        CacheContract::remember('user.profile', ['user_id' => 1], fn () => ['name' => 'Ada']);

        expect(usersCounter())->toBeNull();
    });

    it('throw the rejection when "read" is "throw"', function () {
        $this->user = loginAsMgetUser(['~*', '-mget']);
        config()->set('cache-contract.redis_errors.read', 'throw');

        expect(fn () => CacheContract::get('user.profile', ['user_id' => 1]))->toThrow(RedisClient::rejectedCommandError(), 'NOPERM');
    });

    it('make inspect --live fail with one line instead of "Not in Redis yet"', function () {
        app(GenerationCounters::class)->currentOrFail(['users']);
        $this->user = loginAsMgetUser(['~*', '-mget']);

        $exit = Artisan::call('cache-contract:inspect', ['id' => 'user.profile', '--param' => ['user_id=42'], '--live' => true]);
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->toContain('Could not read the generation counters ('.RedisClient::rejectedCommandError().': NOPERM')
            ->and($output)->not->toContain('Not in Redis yet');
    });
});

it('lets findMany() load from the database and cache nothing when the MGET of its entries is rejected', function () {
    // MGET allowed on some keys only needs an ACL selector (Redis 7.0+).
    // phpredis answers INFO flat, predis by section.
    $info = mgetConnection()->command('info', ['server']);
    $version = $info['redis_version'] ?? $info['Server']['redis_version'] ?? '0';
    if (version_compare((string) $version, '7.0.0', '<')) {
        test()->markTestSkipped("ACL read and write key permissions need Redis 7.0+, this is {$version}");
    }

    ModelCacheDatabase::create();
    $ids = [1, ...ModelCacheDatabase::addMembers(2)];
    Member::modelCache()->key(1); // creates the model's generation counter, as the default user, and caches nothing

    // MGET is allowed on the counters only (an ACL selector); GET, scripts and writes work
    // on every key. So the entries' MGET is rejected, but a write would succeed: a read
    // taken for "all missing" would cache the rows, and the check below would see them.
    $this->user = loginAsMgetUser(['~*', '-mget', '(~*:gen:* +mget)']);
    Exceptions::fake();

    $names = Member::cachedMany($ids)->pluck('name')->all();

    expect($names)->toBe(['Ada', 'Member 2', 'Member 3']);
    RedisClient::assertRejectedCommandReported('NOPERM');

    // Only the rejected read was reported, and nothing was written: no entry exists.
    Exceptions::assertReportedCount(1);
    loginToMget(null);
    /** @var RedisStore $store */
    $store = Cache::store('mget')->getStore();
    foreach ($ids as $id) {
        expect(mgetConnection()->command('exists', [$store->getPrefix().Member::modelCache()->key($id)]))->toBe(0);
    }
});
