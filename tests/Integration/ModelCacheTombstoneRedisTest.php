<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Facades\ModelCache;
use Uslanozan\CacheContract\Runtime\Tombstone;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Article;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Fixtures\Models\MemberStatus;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;
use Uslanozan\CacheContract\Tests\Support\QueryCounter;

/*
 * ADR 0012 in the model cache against real Redis: the stale write found in
 * #43 (a read loads a row, a change clears the entry, the read writes the old
 * row back) is closed for find() and findMany().
 *
 * The client comes from REDIS_CLIENT (phpredis by default), as in
 * TombstoneRedisTest.
 */

beforeEach(function () {
    $client = env('REDIS_CLIENT', 'phpredis');
    config()->set('database.redis.client', is_string($client) && $client !== '' ? $client : 'phpredis');
    config()->set('database.redis.model_tombstones', [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'database' => 1,
        'timeout' => 1.0,
        'read_timeout' => 1.0,
        'max_retries' => 0,
    ]);
    config()->set('cache.stores.model_tombstones', ['driver' => 'redis', 'connection' => 'model_tombstones']);
    config()->set('cache-contract.store', 'model_tombstones');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));

    ModelCacheDatabase::create(); // seeded without model events
});

/**
 * Renames member $id right after the first SELECT on members that $operation
 * runs: after the read's query, before its write, as in #43.
 */
function renameDuringRead(int $id, string $name, Closure $operation): mixed
{
    $changed = false;
    DB::listen(function (QueryExecuted $query) use (&$changed, $id, $name) {
        if (! $changed && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"members"')) {
            $changed = true;
            $member = Member::query()->findOrFail($id);
            $member->name = $name;
            $member->save();
        }
    });

    return $operation();
}

function modelTombstonesStore(): mixed
{
    return Cache::store('model_tombstones');
}

it('does not write back a row find() read before a change', function () {
    $read = renameDuringRead(1, 'Ada Lovelace', fn () => Member::cached(1));

    expect($read?->name)->toBe('Ada')
        ->and(Tombstone::is(modelTombstonesStore()->get(Member::modelCache()->key(1))))->toBeTrue()
        ->and(Member::cached(1)?->name)->toBe('Ada Lovelace')
        ->and(QueryCounter::count(fn () => Member::cached(1)))->toBe(0); // the fresh row is cached
});

it('does not write back a row findMany() read before a change, and caches the others', function () {
    $ids = [1, ...ModelCacheDatabase::addMembers(2)];

    renameDuringRead(1, 'Ada Lovelace', fn () => Member::cachedMany($ids));

    expect(Tombstone::is(modelTombstonesStore()->get(Member::modelCache()->key(1))))->toBeTrue()
        ->and(modelTombstonesStore()->get(Member::modelCache()->key(2))[0]['name'] ?? null)->toBe('Member 2')
        ->and(modelTombstonesStore()->get(Member::modelCache()->key(3))[0]['name'] ?? null)->toBe('Member 3')
        ->and(Member::cachedMany($ids)->pluck('name')->all())->toBe(['Ada Lovelace', 'Member 2', 'Member 3']);
});

it('caches at once a row read after the change', function () {
    Member::cached(1);
    $member = Member::query()->findOrFail(1);
    $member->name = 'Grace';
    $member->save();

    expect(Member::cached(1)?->name)->toBe('Grace')
        ->and(QueryCounter::count(fn () => Member::cached(1)))->toBe(0);
});

it('leaves tombstones on the normal and the withTrashed() entry', function () {
    Article::cached(1);
    Article::modelCache()->withTrashed()->find(1);

    Article::query()->findOrFail(1)->delete();

    expect(Tombstone::is(modelTombstonesStore()->get(Article::modelCache()->key(1))))->toBeTrue()
        ->and(Tombstone::is(modelTombstonesStore()->get(Article::modelCache()->withTrashed()->key(1))))->toBeTrue()
        ->and(Article::cached(1))->toBeNull()
        ->and(Article::modelCache()->withTrashed()->find(1)?->trashed())->toBeTrue();
});

it('clears a created row with a plain delete', function () {
    expect(Member::cached(3))->toBeNull(); // caches "no such row"

    Member::create([
        'id' => 3, 'organization_id' => 1, 'name' => 'New', 'password' => 'HASHED-SECRET',
        'settings' => [], 'is_admin' => false, 'status' => MemberStatus::Active, 'last_login_at' => '2026-10-13 21:00:00',
    ]);

    /** @var RedisStore $store */
    $store = modelTombstonesStore()->getStore();
    /** @var Connection $connection */
    $connection = $store->connection();

    expect($connection->exists($store->getPrefix().Member::modelCache()->key(3)))->toBe(0)
        ->and(Member::cached(3)?->name)->toBe('New');
});

// A command that fails inside the compare-and-set script answers false in
// phpredis, with the error in getLastError() (see TombstoneRedisTest).
it('reports a write script Redis rejects, and caches nothing', function () {
    if (config('database.redis.client') !== 'phpredis') {
        $this->markTestSkipped('phpredis only: a failed script answers false, with the error in getLastError()');
    }

    Member::modelCache()->key(1); // creates the generation counter while SETEX is allowed

    /** @var RedisStore $store */
    $store = modelTombstonesStore()->getStore();
    $user = 'cc_nosetex_'.bin2hex(random_bytes(4));
    $store->connection()->command('acl', ['SETUSER', $user, 'on', '>secret', '~*', '+@all', '-setex']);

    try {
        config()->set('database.redis.model_tombstones.username', $user);
        config()->set('database.redis.model_tombstones.password', 'secret');
        app('redis')->purge('model_tombstones');
        app()->forgetInstance('redis');
        Cache::forgetDriver('model_tombstones');
        Exceptions::fake();

        expect(ModelCache::for(Member::class)->find(1)?->name)->toBe('Ada')
            ->and(ModelCache::for(Member::class)->findMany([1])->pluck('name')->all())->toBe(['Ada']);

        Exceptions::assertReported(fn (RedisException $e): bool => str_contains($e->getMessage(), 'ACL failure in script'));
        expect(modelTombstonesStore()->get(Member::modelCache()->key(1)))->toBeNull();
    } finally {
        config()->set('database.redis.model_tombstones.username', null);
        config()->set('database.redis.model_tombstones.password', null);
        app('redis')->purge('model_tombstones');
        app()->forgetInstance('redis');
        Cache::forgetDriver('model_tombstones');
        modelTombstonesStore()->getStore()->connection()->command('acl', ['DELUSER', $user]);
    }
});
