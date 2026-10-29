<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Uslanozan\CacheContract\CacheContractServiceProvider;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Fixtures\Models\MemberStatus;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Organization;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;
use Uslanozan\CacheContract\Tests\Support\RedisCommandCounter;

/*
 * invalidated_by against real Redis (LRVL-39): a committed change to a listed
 * model gives the definition's counter a new value, so every entry of the
 * definition gets a new key; a rollback changes nothing. A read costs one
 * command for the counters and one for the entry.
 */

beforeEach(function () {
    config()->set('database.redis.client', 'phpredis');
    config()->set('database.redis.invalidated_by', [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'database' => 1,
        'timeout' => 1.0,
        'read_timeout' => 1.0,
        'max_retries' => 0,
    ]);
    config()->set('cache.stores.invalidated_by', ['driver' => 'redis', 'connection' => 'invalidated_by']);
    config()->set('cache-contract.store', 'invalidated_by');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));

    config()->set('cache-contract.definitions', [
        'member' => [
            'active_list' => [
                'purpose' => 'active_list', 'type' => 'array', 'params' => ['organization_id' => 'int'],
                'ttl' => 300, 'description' => 'Names of the active members of an organization.',
                'invalidated_by' => [Member::class],
            ],
        ],
        'organization' => [
            'names' => [
                'purpose' => 'names', 'type' => 'array', 'ttl' => 300, 'description' => 'Organization names.',
                'invalidated_by' => [Organization::class],
            ],
        ],
        'external' => [
            'exchange_rates' => ['purpose' => 'exchange_rates', 'type' => 'array', 'ttl' => 600, 'description' => 'Rates.'],
        ],
    ]);
    // Boot again, as an application would with these definitions.
    app()->register(CacheContractServiceProvider::class, true);

    ModelCacheDatabase::create();
});

function invalidatedByActiveList(): mixed
{
    return CacheContract::remember('member.active_list', ['organization_id' => 1], fn () => Member::query()
        ->where('organization_id', 1)
        ->where('status', MemberStatus::Active)
        ->orderBy('id')
        ->pluck('name')
        ->all());
}

function invalidatedByAddMember(string $name): void
{
    Member::create([
        'organization_id' => 1, 'name' => $name, 'password' => 'x', 'settings' => [],
        'is_admin' => false, 'status' => MemberStatus::Active, 'last_login_at' => '2026-10-13 21:00:00',
    ]);
}

/** @return array<string, int> Redis commands $operation sends on the store's connection */
function invalidatedByCommands(Closure $operation): array
{
    $store = Cache::store('invalidated_by')->getStore();
    assert($store instanceof RedisStore);

    return RedisCommandCounter::count($store->connection(), $operation);
}

it('gives every entry of the definition a new key once the change is committed', function () {
    expect(invalidatedByActiveList())->toBe(['Ada']);
    $key = CacheContract::key('member.active_list', ['organization_id' => 1]);

    DB::transaction(function () use ($key) {
        invalidatedByAddMember('Grace');

        expect(CacheContract::key('member.active_list', ['organization_id' => 1]))->toBe($key);
    });

    expect(CacheContract::key('member.active_list', ['organization_id' => 1]))->not->toBe($key)
        // The old entry is never read again and expires through its TTL.
        ->and(Cache::store('invalidated_by')->has($key))->toBeTrue()
        ->and(invalidatedByActiveList())->toBe(['Ada', 'Grace']);
});

it('keeps the key when the transaction rolls back', function () {
    invalidatedByActiveList();
    $key = CacheContract::key('member.active_list', ['organization_id' => 1]);

    DB::beginTransaction();
    invalidatedByAddMember('Grace');
    DB::rollBack();

    expect(CacheContract::key('member.active_list', ['organization_id' => 1]))->toBe($key)
        ->and(invalidatedByActiveList())->toBe(['Ada']);
});

it('invalidates for a model without HasCache', function () {
    $names = fn () => CacheContract::remember('organization.names', [], fn () => Organization::query()->pluck('name')->all());
    expect($names())->toBe(['Acme']);

    Organization::create(['name' => 'Globex']);

    expect($names())->toBe(['Acme', 'Globex']);
});

it('reads a hit with one command for the counters and one for the entry', function () {
    invalidatedByActiveList();
    CacheContract::remember('external.exchange_rates', [], fn () => ['usd' => 1]);

    expect(invalidatedByCommands(fn () => invalidatedByActiveList()))->toBe(['get' => 1, 'mget' => 1])
        // A definition without tags or invalidated_by reads no counter.
        ->and(invalidatedByCommands(fn () => CacheContract::remember('external.exchange_rates', [], fn () => [])))->toBe(['get' => 1]);
});

it('bumps the counter with one command per change', function () {
    // Organization has no HasCache, so only the invalidated_by counter is written.
    expect(invalidatedByCommands(fn () => Organization::create(['name' => 'Globex'])))->toBe(['setex' => 1]);
});
