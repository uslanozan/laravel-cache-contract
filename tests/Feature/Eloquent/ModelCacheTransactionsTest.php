<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Uslanozan\CacheContract\Facades\ModelCache;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Ledger;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Fixtures\Models\MemberStatus;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;

/*
 * While a transaction is open on the model's connection, the model cache
 * reads entries but writes none (LRVL-39): the rows it loads may not be
 * committed, and a rollback would leave them in the cache.
 */

/** Number of database queries $operation runs. */
function transactionQueries(Closure $operation): int
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

/**
 * What RefreshDatabase and DatabaseTransactions do before each test
 * (Illuminate\Foundation\Testing\DatabaseTransactions::beginDatabaseTransaction()):
 * Laravel's testing transactions manager, and a transaction around the test.
 */
function wrapInTestTransaction(): void
{
    $manager = new DatabaseTransactionsManager([ModelCacheDatabase::CONNECTION]);
    app()->instance('db.transactions', $manager);

    $connection = DB::connection(ModelCacheDatabase::CONNECTION);
    $connection->setTransactionManager($manager);
    $connection->beginTransaction();
}

beforeEach(function () {
    ModelCacheDatabase::create(); // seeded without model events
    ModelCacheDatabase::addMembers(1); // member 2
});

describe('inside a transaction on the model\'s connection', function () {
    it('loads from the database and writes nothing', function () {
        DB::transaction(function () {
            expect(ModelCache::for(Member::class)->find(1)?->name)->toBe('Ada')
                ->and(ModelCache::for(Member::class)->findMany([1, 2]))->toHaveCount(2)
                ->and(Cache::has(ModelCache::for(Member::class)->key(1)))->toBeFalse()
                ->and(Cache::has(ModelCache::for(Member::class)->key(2)))->toBeFalse();
        });

        // Written again once no transaction is open.
        ModelCache::for(Member::class)->find(1);
        expect(Cache::has(ModelCache::for(Member::class)->key(1)))->toBeTrue();
    });

    it('still reads cached entries', function () {
        ModelCache::for(Member::class)->findMany([1, 2]);

        DB::transaction(function () {
            expect(transactionQueries(fn () => ModelCache::for(Member::class)->find(1)))->toBe(0)
                ->and(transactionQueries(fn () => ModelCache::for(Member::class)->findMany([1, 2])))->toBe(0);
        });
    });

    it('never caches a row that is rolled back', function () {
        DB::beginTransaction();
        Member::create([
            'id' => 50, 'organization_id' => 1, 'name' => 'Uncommitted', 'password' => 'x', 'settings' => [],
            'is_admin' => false, 'status' => MemberStatus::Active, 'last_login_at' => '2026-10-13 21:00:00',
        ]);
        expect(Member::cached(50)?->name)->toBe('Uncommitted')
            ->and(Member::cachedMany([1, 50]))->toHaveCount(2);
        DB::rollBack();

        expect(Member::cached(50))->toBeNull()
            ->and(Member::cachedMany([1, 50])->modelKeys())->toBe([1]);
    });

    it('writes when the open transaction is on another connection', function () {
        DB::connection(ModelCacheDatabase::SECOND_CONNECTION)->transaction(function () {
            ModelCache::for(Member::class)->find(1);
        });
        DB::transaction(function () {
            ModelCache::for(Ledger::class)->find(1);
        });

        expect(Cache::has(ModelCache::for(Member::class)->key(1)))->toBeTrue()
            ->and(Cache::has(ModelCache::for(Ledger::class)->key(1)))->toBeTrue();
    });
});

describe('in tests that use RefreshDatabase or DatabaseTransactions', function () {
    beforeEach(function () {
        wrapInTestTransaction();
    });

    afterEach(function () {
        DB::connection(ModelCacheDatabase::CONNECTION)->rollBack();
    });

    it('caches as usual: the transaction around the test does not count', function () {
        ModelCache::for(Member::class)->find(1);
        ModelCache::for(Member::class)->findMany([2]);

        expect(Cache::has(ModelCache::for(Member::class)->key(1)))->toBeTrue()
            ->and(transactionQueries(fn () => ModelCache::for(Member::class)->findMany([1, 2])))->toBe(0);
    });

    it('writes nothing inside a transaction the test opens itself', function () {
        DB::transaction(function () {
            ModelCache::for(Member::class)->find(1);
        });

        expect(Cache::has(ModelCache::for(Member::class)->key(1)))->toBeFalse();
    });

    it('writes nothing inside a transaction on a connection the test does not wrap', function () {
        DB::connection(ModelCacheDatabase::SECOND_CONNECTION)->transaction(function () {
            ModelCache::for(Ledger::class)->find(1);
        });

        expect(Cache::has(ModelCache::for(Ledger::class)->key(1)))->toBeFalse();
    });
});
