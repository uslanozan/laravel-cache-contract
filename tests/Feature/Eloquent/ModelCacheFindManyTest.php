<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Exceptions\ModelNotCachable;
use Uslanozan\CacheContract\Facades\ModelCache;
use Uslanozan\CacheContract\Tests\Fakes\FailingStore;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Article;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Project;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;

/** Number of database queries $operation runs. */
function findManyQueries(Closure $operation): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $operation();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

/** Routes the package's cache through a FailingStore and returns it. */
function findManyFailingStore(): FailingStore
{
    $store = new FailingStore;
    Cache::extend('failing', fn () => Cache::repository($store));
    config()->set('cache.stores.failing', ['driver' => 'failing']);
    config()->set('cache-contract.store', 'failing');

    return $store;
}

/**
 * @param  Collection<int, Member>  $members
 * @return list<string>
 */
function findManyNames(Collection $members): array
{
    return $members->map(fn (Member $member) => (string) $member->name)->values()->all();
}

beforeEach(function () {
    ModelCacheDatabase::create();
    ModelCacheDatabase::addMembers(3); // members 2, 3 and 4
});

it('returns the models in the order asked for, loading only the misses with one query', function () {
    ModelCache::for(Member::class)->find(2);
    ModelCache::for(Member::class)->find(4);
    $members = null;

    expect(findManyQueries(function () use (&$members) {
        $members = ModelCache::for(Member::class)->findMany([4, 1, 3, 2]);
    }))->toBe(1)
        ->and(findManyNames($members))->toBe(['Member 4', 'Ada', 'Member 3', 'Member 2']);
});

it('serves every model from the cache the second time', function () {
    ModelCache::for(Member::class)->findMany([1, 2, 3]);

    expect(findManyQueries(fn () => expect(findManyNames(ModelCache::for(Member::class)->findMany([3, 2, 1])))
        ->toBe(['Member 3', 'Member 2', 'Ada'])))->toBe(0);
});

it('leaves out an ID with no row and caches it as null', function () {
    expect(findManyNames(ModelCache::for(Member::class)->findMany([1, 999, 2])))->toBe(['Ada', 'Member 2'])
        ->and(findManyQueries(fn () => ModelCache::for(Member::class)->findMany([999])))->toBe(0)
        ->and(ModelCache::for(Member::class)->find(999))->toBeNull();
});

it('returns a repeated ID once and treats "1" as 1', function () {
    expect(findManyNames(ModelCache::for(Member::class)->findMany([1, '1', 2, 1])))->toBe(['Ada', 'Member 2']);
});

it('does no I/O for an empty list', function () {
    $store = findManyFailingStore();

    expect(findManyQueries(fn () => expect(ModelCache::for(Member::class)->findMany([]))->toBeEmpty()))->toBe(0)
        ->and($store->calls)->toBe([]);
});

it('returns an Eloquent collection, so relations load for all models in one query', function () {
    ModelCache::for(Member::class)->find(1);
    $members = ModelCache::for(Member::class)->findMany([1, 2, 3]);

    expect($members)->toBeInstanceOf(Collection::class)
        ->and(findManyQueries(fn () => $members->load('organization')))->toBe(1)
        ->and($members->every(fn (Member $member) => $member->relationLoaded('organization')))->toBeTrue();
});

it('checks every ID before any I/O and stops at an invalid one', function () {
    $store = findManyFailingStore();

    expect(findManyQueries(fn () => expect(fn () => ModelCache::for(Member::class)->findMany([1, 'abc', 3]))
        ->toThrow(InvalidParameters::class)))->toBe(0)
        ->and($store->calls)->toBe([]);
});

it('splits large lists into chunks for the reads and the query', function () {
    config()->set('cache-contract.models.chunk_size', 2);
    ModelCacheDatabase::addMembers(1); // member 5

    expect(findManyQueries(fn () => expect(ModelCache::for(Member::class)->findMany([5, 4, 3, 2, 1]))->toHaveCount(5)))
        ->toBe(3)
        ->and(findManyQueries(fn () => ModelCache::for(Member::class)->findMany([1, 2, 3, 4, 5])))->toBe(0);
});

it('rejects a chunk size that is not a positive integer', function () {
    config()->set('cache-contract.models.chunk_size', 0);

    expect(fn () => ModelCache::for(Member::class))->toThrow(InvalidConfiguration::class, 'models.chunk_size');
});

it('applies global scopes and their context', function () {
    expect(fn () => ModelCache::for(Project::class)->findMany([5]))->toThrow(ModelNotCachable::class);

    expect(ModelCache::for(Project::class)->withContext(['tenant_id' => 7])->findMany([5])->pluck('name')->all())->toBe(['Apollo']);

    app()->instance('fixture.tenant', 8);
    expect(ModelCache::for(Project::class)->withContext(['tenant_id' => 8])->findMany([5]))->toBeEmpty();
});

it('leaves out trashed models unless asked for them', function () {
    Article::create(['title' => 'Second']);
    Article::query()->findOrFail(1)->delete();

    expect(ModelCache::for(Article::class)->findMany([1, 2])->pluck('title')->all())->toBe(['Second'])
        ->and(ModelCache::for(Article::class)->withTrashed()->findMany([1, 2])->pluck('title')->all())->toBe(['Hello', 'Second']);
});

describe('Redis errors', function () {
    beforeEach(function () {
        $this->store = findManyFailingStore();
        Exceptions::fake();
    });

    it('loads everything from the database and writes nothing when the read fails', function () {
        $this->store->fail('get');

        expect(findManyNames(ModelCache::for(Member::class)->findMany([2, 1])))->toBe(['Member 2', 'Ada'])
            ->and($this->store->calls)->not->toContain('put');

        Exceptions::assertReported(RedisException::class);
    });

    it('returns the models when the write fails', function () {
        $this->store->fail('put');

        expect(findManyNames(ModelCache::for(Member::class)->findMany([2, 1])))->toBe(['Member 2', 'Ada']);

        Exceptions::assertReported(RedisException::class);
    });

    it('throws for the operations set to throw', function (string $operation, string $method) {
        config()->set("cache-contract.redis_errors.{$operation}", 'throw');
        $this->store->fail($method);

        expect(fn () => ModelCache::for(Member::class)->findMany([1, 2]))->toThrow(RedisException::class);
    })->with([
        'read' => ['read', 'get'],
        'write' => ['write', 'put'],
    ]);
});
