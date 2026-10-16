<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Facades\ModelCache;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Fixtures\Models\MemberStatus;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Project;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;

/*
 * The model cache against real Redis (phpredis): the envelope and the
 * attributes payload must survive Redis' own serialization, and a Redis
 * outage must fall back to the database (ADR 0007, ADR 0008).
 */

beforeEach(function () {
    config()->set('database.redis.client', 'phpredis');

    foreach (['model_cache' => (int) env('REDIS_PORT', 6379), 'unreachable' => 1] as $connection => $port) {
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

    config()->set('cache-contract.store', 'model_cache');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));

    ModelCacheDatabase::create();
});

/** Number of database queries $operation runs. */
function modelCacheRedisQueries(Closure $operation): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $operation();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

it('round-trips a model with its casts through Redis', function () {
    $loaded = ModelCache::for(Member::class)->find(1);
    $cached = null;

    expect(modelCacheRedisQueries(function () use (&$cached) {
        $cached = ModelCache::for(Member::class)->find(1);
    }))->toBe(0)
        ->and($cached->getAttributes())->toBe($loaded->getAttributes())
        ->and($cached->is_admin)->toBeTrue()
        ->and($cached->settings)->toBe(['theme' => 'dark'])
        ->and($cached->status)->toBe(MemberStatus::Active)
        ->and($cached->last_login_at)->toBeInstanceOf(Carbon::class);
});

it('stores the raw attributes in the envelope', function () {
    ModelCache::for(Member::class)->find(1);

    $entry = Cache::store('model_cache')->get(ModelCache::for(Member::class)->key(1));

    expect($entry)->toBeArray()->toHaveCount(1)
        ->and($entry[0])->toBeArray()->toHaveKeys(['id', 'name', 'settings'])
        ->and($entry[0]['settings'])->toBe('{"theme":"dark"}');
});

it('caches an ID with no row as null', function () {
    ModelCache::for(Member::class)->find(999);

    expect(Cache::store('model_cache')->get(ModelCache::for(Member::class)->key(999)))->toBe([null])
        ->and(modelCacheRedisQueries(fn () => ModelCache::for(Member::class)->find(999)))->toBe(0);
});

it('keeps tenants apart in Redis', function () {
    expect(ModelCache::for(Project::class)->withContext(['tenant_id' => 7])->find(5)?->name)->toBe('Apollo');

    app()->instance('fixture.tenant', 8);

    expect(ModelCache::for(Project::class)->withContext(['tenant_id' => 8])->find(5))->toBeNull();
});

it('loads from the database when Redis is unreachable', function () {
    config()->set('cache-contract.store', 'unreachable');
    Exceptions::fake();

    expect(ModelCache::for(Member::class)->find(1)?->name)->toBe('Ada');

    Exceptions::assertReported(RedisException::class);
});
