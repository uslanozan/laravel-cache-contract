<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Project;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;

/*
 * HasCache invalidation against real Redis (LRVL-38): entries are cleared
 * after the transaction commits, never on rollback; a row moved to another
 * tenant leaves no stale entry; flushCache() moves the generation counter.
 */

beforeEach(function () {
    config()->set('database.redis.client', 'phpredis');
    config()->set('database.redis.invalidation', [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'database' => 1,
        'timeout' => 1.0,
        'read_timeout' => 1.0,
        'max_retries' => 0,
    ]);
    config()->set('cache.stores.invalidation', ['driver' => 'redis', 'connection' => 'invalidation']);
    config()->set('cache-contract.store', 'invalidation');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));

    ModelCacheDatabase::create(); // the current tenant is 7
});

function invalidationRedisRename(string $name): void
{
    $member = Member::query()->findOrFail(1);
    $member->name = $name;
    $member->save();
}

it('clears the entry in Redis after the commit', function () {
    $key = Member::modelCache()->key(1);
    expect(Member::cached(1)?->name)->toBe('Ada')
        ->and(Cache::store('invalidation')->has($key))->toBeTrue();

    DB::transaction(function () use ($key) {
        invalidationRedisRename('Grace');

        expect(Cache::store('invalidation')->has($key))->toBeTrue();
    });

    expect(Cache::store('invalidation')->has($key))->toBeFalse()
        ->and(Member::cached(1)?->name)->toBe('Grace');
});

it('keeps the entry in Redis when the transaction rolls back', function () {
    $key = Member::modelCache()->key(1);
    Member::cached(1);

    DB::beginTransaction();
    invalidationRedisRename('Grace');
    DB::rollBack();

    expect(Cache::store('invalidation')->has($key))->toBeTrue()
        ->and(Member::cached(1)?->name)->toBe('Ada');
});

it('clears both tenants\' entries when a row moves to another tenant', function () {
    $tenant7 = Project::modelCache()->withContext(['tenant_id' => 7]);
    $tenant9 = Project::modelCache()->withContext(['tenant_id' => 9]);
    $tenant7->find(5);
    app()->instance('fixture.tenant', 9);
    $tenant9->find(5); // caches "no such row" for tenant 9

    app()->instance('fixture.tenant', 7);
    $project = Project::query()->findOrFail(5);
    $project->tenant_id = 9;
    $project->save();

    expect(Cache::store('invalidation')->has($tenant7->key(5)))->toBeFalse()
        ->and(Cache::store('invalidation')->has($tenant9->key(5)))->toBeFalse();
});

it('makes every entry of the model unreachable with flushCache()', function () {
    Member::cached(1);
    $old = Member::modelCache()->key(1);

    Member::flushCache();

    expect(Member::modelCache()->key(1))->not->toBe($old)
        ->and(Cache::store('invalidation')->has(Member::modelCache()->key(1)))->toBeFalse();
});
