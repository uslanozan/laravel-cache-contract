<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Exceptions\ModelNotCachable;
use Uslanozan\CacheContract\Facades\ModelCache;
use Uslanozan\CacheContract\Runtime\GenerationCounters;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Article;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Ledger;
use Uslanozan\CacheContract\Tests\Fixtures\Models\LegacyThing;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Fixtures\Models\MemberStatus;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Note;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Project;
use Uslanozan\CacheContract\Tests\Fixtures\Models\ReservedContextNote;
use Uslanozan\CacheContract\Tests\Fixtures\Models\ThrowingContextNote;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;

/** Number of database queries $operation runs. */
function invalidationQueries(Closure $operation): int
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

function renameMember(int $id, string $name): void
{
    $member = Member::query()->findOrFail($id);
    $member->name = $name;
    $member->save();
}

beforeEach(function () {
    ModelCacheDatabase::create(); // seeded without model events
    ModelCacheDatabase::addMembers(1); // member 2
});

describe('model events', function () {
    it('clears a model\'s entry when it is saved', function () {
        expect(Member::cached(1)?->name)->toBe('Ada');

        renameMember(1, 'Grace');

        expect(Member::cached(1)?->name)->toBe('Grace');
    });

    it('clears a cached "no such row" when the row is created', function () {
        expect(Member::cached(50))->toBeNull();

        Member::create([
            'id' => 50, 'organization_id' => 1, 'name' => 'New', 'password' => 'x', 'settings' => [],
            'is_admin' => false, 'status' => MemberStatus::Active, 'last_login_at' => '2026-10-13 21:00:00',
        ]);

        expect(Member::cached(50)?->name)->toBe('New');
    });

    it('clears a model\'s entry when it is deleted', function () {
        expect(Member::cached(1))->not->toBeNull();

        Member::query()->findOrFail(1)->delete();

        expect(Member::cached(1))->toBeNull();
    });

    it('clears the normal and the withTrashed() entries on soft delete and restore', function () {
        expect(Article::cached(1)?->trashed())->toBeFalse()
            ->and(Article::modelCache()->withTrashed()->find(1)?->trashed())->toBeFalse();

        Article::query()->findOrFail(1)->delete();

        expect(Article::cached(1))->toBeNull()
            ->and(Article::modelCache()->withTrashed()->find(1)?->trashed())->toBeTrue();

        Article::withTrashed()->findOrFail(1)->restore();

        expect(Article::cached(1)?->trashed())->toBeFalse()
            ->and(Article::modelCache()->withTrashed()->find(1)?->trashed())->toBeFalse();
    });

    it('clears after the transaction commits, not before', function () {
        expect(Member::cached(1)?->name)->toBe('Ada');

        DB::transaction(function () {
            renameMember(1, 'Grace');

            // Still the committed value inside the transaction.
            expect(Member::cached(1)?->name)->toBe('Ada');
        });

        expect(Member::cached(1)?->name)->toBe('Grace');
    });

    it('clears nothing when the transaction rolls back', function () {
        expect(Member::cached(1)?->name)->toBe('Ada');

        DB::beginTransaction();
        renameMember(1, 'Grace');
        DB::rollBack();

        expect(invalidationQueries(fn () => expect(Member::cached(1)?->name)->toBe('Ada')))->toBe(0);
    });

    it('clears the previous tenant\'s entry when a model moves to another tenant', function () {
        expect(Project::modelCache()->withContext(['tenant_id' => 7])->find(5)?->name)->toBe('Apollo');
        app()->instance('fixture.tenant', 9);
        expect(Project::modelCache()->withContext(['tenant_id' => 9])->find(5))->toBeNull();

        app()->instance('fixture.tenant', 7);
        $project = Project::query()->findOrFail(5);
        $project->tenant_id = 9;
        $project->save();

        expect(Project::modelCache()->withContext(['tenant_id' => 7])->find(5))->toBeNull();
        app()->instance('fixture.tenant', 9);
        expect(Project::modelCache()->withContext(['tenant_id' => 9])->find(5)?->name)->toBe('Apollo');
    });

    it('registers its listeners once per model class', function () {
        Member::cached(1);
        Member::cachedMany([1, 2]);

        expect(app('events')->getListeners('eloquent.saved: '.Member::class))->toHaveCount(1);
    });
});

describe('forgetCache() and flushCache()', function () {
    it('forgets one model\'s entries', function () {
        Member::cachedMany([1, 2]);

        expect(Member::query()->findOrFail(1)->forgetCache())->toBeTrue()
            ->and(invalidationQueries(fn () => Member::cached(1)))->toBe(1)
            ->and(invalidationQueries(fn () => Member::cached(2)))->toBe(0);
    });

    it('invalidates every entry of the model at once', function () {
        Member::cachedMany([1, 2]);
        $key = ModelCache::for(Member::class)->key(1);

        expect(Member::flushCache())->toBeTrue()
            ->and(ModelCache::for(Member::class)->key(1))->not->toBe($key)
            ->and(invalidationQueries(fn () => Member::cachedMany([1, 2])))->toBe(1);
    });
});

describe('a model that needs a context but gives none', function () {
    it('fails on the first read with a context', function () {
        expect(fn () => Note::modelCache()->withContext(['tenant_id' => 7])->find(1))
            ->toThrow(ModelNotCachable::class, 'ProvidesCacheContext');
    });

    it('refuses forgetCache()', function () {
        expect(fn () => Note::query()->findOrFail(1)->forgetCache())
            ->toThrow(ModelNotCachable::class, 'ProvidesCacheContext');
    });

    it('still saves and reports it, without writing to the cache', function () {
        Exceptions::fake();
        $counter = 'model:testing::memory::notes';
        $before = app(GenerationCounters::class)->currentOrFail([$counter])[$counter];

        $note = Note::query()->findOrFail(1);
        $note->body = 'Changed';
        $note->save();

        // Such a model never has entries, so there is nothing to invalidate.
        expect(Note::query()->findOrFail(1)->body)->toBe('Changed')
            ->and(app(GenerationCounters::class)->currentOrFail([$counter])[$counter])->toBe($before);

        Exceptions::assertReported(ModelNotCachable::class);
    });
});

describe('a save never fails because of the cache', function () {
    it('saves a model the model cache cannot handle and reports why', function () {
        Exceptions::fake();

        LegacyThing::create(['name' => 'x']);

        expect(LegacyThing::query()->count())->toBe(1);
        Exceptions::assertReported(fn (ModelNotCachable $e) => str_contains($e->getMessage(), 'snake_case'));
    });

    it('commits, and runs the transaction\'s other callbacks, when clearing throws after the commit', function () {
        Exceptions::fake();
        $ran = false;

        DB::transaction(function () use (&$ran) {
            $note = ReservedContextNote::query()->findOrFail(1);
            $note->body = 'Changed';
            $note->save();

            DB::afterCommit(function () use (&$ran) {
                $ran = true;
            });
        });

        expect(Note::query()->findOrFail(1)->body)->toBe('Changed')
            ->and($ran)->toBeTrue();
        Exceptions::assertReported(InvalidParameters::class);
    });

    it('saves when cacheContext() throws, reports it and invalidates every entry of the model after the commit', function () {
        Exceptions::fake();
        $counter = 'model:testing::memory::notes';
        $current = fn (): int => app(GenerationCounters::class)->currentOrFail([$counter])[$counter];
        $before = $current();

        DB::transaction(function () use ($current, $before) {
            $note = ThrowingContextNote::query()->findOrFail(1);
            $note->body = 'Changed';
            $note->save();

            expect($current())->toBe($before);
        });

        expect(Note::query()->findOrFail(1)->body)->toBe('Changed')
            ->and($current())->not->toBe($before);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'No tenant');
    });

    it('still throws from forgetCache() when cacheContext() throws, since the caller asked for it', function () {
        expect(fn () => ThrowingContextNote::query()->findOrFail(1)->forgetCache())
            ->toThrow(RuntimeException::class, 'No tenant');
    });
});

describe('connections', function () {
    it('clears right away when the model\'s connection has no transaction, even if another one rolls back', function () {
        expect(Ledger::cached(1)?->name)->toBe('Old');

        try {
            DB::connection(ModelCacheDatabase::CONNECTION)->transaction(function () {
                $ledger = Ledger::query()->findOrFail(1);
                $ledger->name = 'New';
                $ledger->save(); // stored at once: no transaction on its own connection

                throw new RuntimeException('roll back the other connection');
            });
        } catch (RuntimeException) {
        }

        expect(Ledger::cached(1)?->name)->toBe('New');
    });

    it('waits for a transaction on the model\'s own connection', function () {
        expect(Ledger::cached(1)?->name)->toBe('Old');

        DB::connection(ModelCacheDatabase::SECOND_CONNECTION)->beginTransaction();
        $ledger = Ledger::query()->findOrFail(1);
        $ledger->name = 'New';
        $ledger->save();
        DB::connection(ModelCacheDatabase::SECOND_CONNECTION)->rollBack();

        expect(Ledger::cached(1)?->name)->toBe('Old');
    });
});
