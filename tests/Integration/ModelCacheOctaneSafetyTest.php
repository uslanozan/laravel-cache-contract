<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Uslanozan\CacheContract\Eloquent\ModelCacheFactory;
use Uslanozan\CacheContract\Exceptions\ModelNotCachable;
use Uslanozan\CacheContract\Tests\Fixtures\Jobs\FindTenantProject;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Project;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;

/*
 * Model-side safety for long-running processes (LRVL-38), against real Redis.
 * Octane and queue workers keep one application, and its singletons, across
 * requests and jobs: tenant 7's cached model must never reach tenant 8.
 * Project 5 belongs to tenant 7; its TenantScope reads the current tenant.
 */

beforeEach(function () {
    config()->set('database.redis.client', 'phpredis');
    config()->set('database.redis.octane_safety', [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'database' => 1,
        'timeout' => 1.0,
        'read_timeout' => 1.0,
        'max_retries' => 0,
    ]);
    config()->set('cache.stores.octane_safety', ['driver' => 'redis', 'connection' => 'octane_safety']);
    config()->set('cache-contract.store', 'octane_safety');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));

    ModelCacheDatabase::create(); // the current tenant is 7
});

/** Number of database queries $operation runs. */
function octaneSafetyQueries(Closure $operation): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $operation();
    } finally {
        DB::disableQueryLog();
    }

    return count(DB::getQueryLog());
}

/** What Octane does between two requests: scoped instances are dropped, singletons stay. */
function octaneSafetyNextRequest(int $tenant): void
{
    app()->forgetScopedInstances();
    app()->instance('fixture.tenant', $tenant);
}

it('never serves a tenant\'s cached model to the next request\'s tenant', function () {
    // Request 1, tenant 7: the model is loaded and cached.
    $factory = app(ModelCacheFactory::class);
    expect(Project::modelCache()->withContext(['tenant_id' => 7])->find(5)?->name)->toBe('Apollo');

    // Request 2, tenant 8, same process and same singleton: it holds no tenant.
    octaneSafetyNextRequest(8);
    expect(app(ModelCacheFactory::class))->toBe($factory)
        ->and(Project::modelCache()->withContext(['tenant_id' => 8])->find(5))->toBeNull()
        // Forgetting the context is refused, never answered from tenant 7's entry.
        ->and(fn () => Project::cached(5))->toThrow(ModelNotCachable::class);

    // Request 3, tenant 7 again: still its own entry, read from Redis.
    octaneSafetyNextRequest(7);
    expect(octaneSafetyQueries(fn () => expect(Project::modelCache()->withContext(['tenant_id' => 7])->find(5)?->name)->toBe('Apollo')))
        ->toBe(0);

    // Two entries in Redis: tenant 7's row, and tenant 8's "no such row".
    $store = Cache::store('octane_safety');
    expect($store->get(Project::modelCache()->withContext(['tenant_id' => 7])->key(5))[0]['name'] ?? null)->toBe('Apollo')
        ->and($store->get(Project::modelCache()->withContext(['tenant_id' => 8])->key(5)))->toBe([null]);
});

it('keeps tenants apart between jobs on the same worker', function () {
    expect(Bus::dispatchSync(new FindTenantProject(7)))->toBe('Apollo')
        ->and(Bus::dispatchSync(new FindTenantProject(8)))->toBeNull()
        ->and(octaneSafetyQueries(fn () => expect(Bus::dispatchSync(new FindTenantProject(7)))->toBe('Apollo')))->toBe(0);
});
