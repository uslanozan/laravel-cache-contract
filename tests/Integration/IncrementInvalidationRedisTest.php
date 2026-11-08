<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Uslanozan\CacheContract\CacheContractServiceProvider;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Runtime\Tombstone;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;
use Uslanozan\CacheContract\Tests\Support\RedisClient;

/*
 * increment() and decrement() against real Redis (LRVL-78): they fire
 * "updated" but never "saved", and both the model cache and invalidated_by
 * now clear on "updated". The client comes from REDIS_CLIENT (phpredis by
 * default; see RedisClient).
 */

beforeEach(function () {
    config()->set('database.redis.client', RedisClient::name());
    config()->set('database.redis.increments', RedisClient::connection());
    config()->set('cache.stores.increments', ['driver' => 'redis', 'connection' => 'increments']);
    config()->set('cache-contract.store', 'increments');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));
    config()->set('cache-contract.definitions', [
        'member' => [
            'logins' => [
                'purpose' => 'logins', 'type' => 'array', 'ttl' => 300, 'description' => 'Logins per member.',
                'invalidated_by' => [Member::class],
            ],
        ],
    ]);
    app()->register(CacheContractServiceProvider::class, true); // listeners come from the config at boot

    ModelCacheDatabase::create(); // seeded without model events
});

/** @return array<int, int> */
function cachedLoginsInRedis(): array
{
    /** @var array<int, int> */
    return CacheContract::remember('member.logins', [], fn () => Member::query()->pluck('logins', 'id')->all());
}

it('serves the new value after increment() and decrement(), from the model cache and invalidated_by', function () {
    expect(Member::cached(1)?->logins)->toBe(0)
        ->and(cachedLoginsInRedis())->toBe([1 => 0]);

    $member = Member::query()->findOrFail(1);
    $member->increment('logins', 3);

    // A tombstone on the model's entry (ADR 0012), and a new key for the definition.
    expect(Tombstone::is(Cache::store('increments')->get(Member::modelCache()->key(1))))->toBeTrue()
        ->and(Member::cached(1)?->logins)->toBe(3)
        ->and(cachedLoginsInRedis())->toBe([1 => 3]);

    $member->decrement('logins');

    expect(Member::cached(1)?->logins)->toBe(2)
        ->and(cachedLoginsInRedis())->toBe([1 => 2]);
});

it('clears an increment() after the commit, not before', function () {
    Member::cached(1);
    cachedLoginsInRedis();

    DB::transaction(function () {
        Member::query()->findOrFail(1)->increment('logins');

        expect(Member::cached(1)?->logins)->toBe(0)
            ->and(cachedLoginsInRedis())->toBe([1 => 0]);
    });

    expect(Member::cached(1)?->logins)->toBe(1)
        ->and(cachedLoginsInRedis())->toBe([1 => 1]);
});
