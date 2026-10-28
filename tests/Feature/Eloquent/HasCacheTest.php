<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Uslanozan\CacheContract\Eloquent\ModelCache as ModelCacheService;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Exceptions\ModelNotCachable;
use Uslanozan\CacheContract\Facades\ModelCache;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Project;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;

/** Number of database queries $operation runs. */
function hasCacheQueries(Closure $operation): int
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

beforeEach(function () {
    ModelCacheDatabase::create();
    ModelCacheDatabase::addMembers(2); // members 2 and 3
});

it('caches a model with cached() under the same entry as ModelCache', function () {
    expect(Member::cached(1)?->name)->toBe('Ada')
        ->and(hasCacheQueries(fn () => expect(ModelCache::for(Member::class)->find(1)?->name)->toBe('Ada')))->toBe(0)
        ->and(Member::cached(999))->toBeNull();
});

it('loads many models with cachedMany(), in the order asked for', function () {
    ModelCache::for(Member::class)->find(3);
    $members = null;

    expect(hasCacheQueries(function () use (&$members) {
        $members = Member::cachedMany([3, 1, 2]);
    }))->toBe(1)
        ->and($members)->toBeInstanceOf(Collection::class)
        ->and($members->pluck('name')->all())->toBe(['Member 3', 'Ada', 'Member 2']);
});

it('gives the model cache for withContext() and withTrashed() through modelCache()', function () {
    $cache = Project::modelCache();

    expect($cache)->toBeInstanceOf(ModelCacheService::class)
        ->and($cache->withContext(['tenant_id' => 7])->key(5))
        ->toBe(ModelCache::for(Project::class)->withContext(['tenant_id' => 7])->key(5))
        ->and(Project::modelCache()->withContext(['tenant_id' => 7])->find(5)?->name)->toBe('Apollo');
});

it('keeps the guards of the model cache', function () {
    expect(fn () => Project::cached(5))->toThrow(ModelNotCachable::class)
        ->and(fn () => Project::cachedMany([5]))->toThrow(ModelNotCachable::class)
        ->and(fn () => Member::cached('abc'))->toThrow(InvalidParameters::class);
});
