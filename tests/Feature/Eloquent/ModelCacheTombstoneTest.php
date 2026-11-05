<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Uslanozan\CacheContract\Facades\ModelCache;
use Uslanozan\CacheContract\Runtime\Tombstone;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Article;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Fixtures\Models\MemberStatus;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;

// ADR 0012 in the model cache, on the array store: removals leave tombstones,
// a created row is cleared with a plain delete, and a save that changed
// nothing clears nothing. The races run against real Redis in
// tests/Integration/ModelCacheTombstoneRedisTest.php.

beforeEach(function () {
    ModelCacheDatabase::create(); // seeded without model events
});

/** What the cache holds under the model cache key of this member. */
function memberEntry(int $id): mixed
{
    return Cache::get(Member::modelCache()->key($id));
}

/** Creates member $id with model events, so HasCache sees the insert. */
function createMember(int $id): Member
{
    return Member::create([
        'id' => $id,
        'organization_id' => 1,
        'name' => "Member {$id}",
        'password' => 'HASHED-SECRET',
        'settings' => [],
        'is_admin' => false,
        'status' => MemberStatus::Active,
        'last_login_at' => '2026-10-13 21:00:00',
    ]);
}

describe('removals', function () {
    it('leaves a tombstone on forget, and returns true when nothing was cached', function () {
        expect(ModelCache::for(Member::class)->forget(1))->toBeTrue()
            ->and(Tombstone::is(memberEntry(1)))->toBeTrue();
    });

    it('leaves a tombstone for the withTrashed() entry too', function () {
        Article::cached(1);
        Article::modelCache()->withTrashed()->find(1);

        Article::modelCache()->forget(1);

        expect(Tombstone::is(Cache::get(Article::modelCache()->key(1))))->toBeTrue()
            ->and(Tombstone::is(Cache::get(Article::modelCache()->withTrashed()->key(1))))->toBeTrue();
    });

    it('leaves a tombstone when a model is updated, deleted or restored', function () {
        Member::cached(1);
        $member = Member::query()->findOrFail(1);
        $member->name = 'Grace';
        $member->save();
        expect(Tombstone::is(memberEntry(1)))->toBeTrue();

        $this->travel(11)->seconds();
        Member::cached(1);
        Member::query()->findOrFail(1)->delete();
        expect(Tombstone::is(memberEntry(1)))->toBeTrue();

        $article = Article::query()->findOrFail(1);
        $article->delete();
        $this->travel(11)->seconds();
        Article::modelCache()->withTrashed()->find(1);
        $article->restore();
        expect(Tombstone::is(Cache::get(Article::modelCache()->withTrashed()->key(1))))->toBeTrue();
    });

    it('leaves a tombstone from forgetCache()', function () {
        Member::cached(1);

        expect(Member::query()->findOrFail(1)->forgetCache())->toBeTrue()
            ->and(Tombstone::is(memberEntry(1)))->toBeTrue();
    });
});

describe('a created row', function () {
    it('is cleared with a plain delete, without a tombstone', function () {
        expect(Member::cached(3))->toBeNull(); // caches "no such row"

        createMember(3);

        expect(Cache::has(Member::modelCache()->key(3)))->toBeFalse()
            ->and(Member::cached(3)?->name)->toBe('Member 3')
            ->and(memberEntry(3)[0]['name'] ?? null)->toBe('Member 3'); // cached at once
    });

    it('gets a tombstone when the same instance is updated later', function () {
        $member = createMember(3);
        Member::cached(3);

        $member->name = 'Grace';
        $member->save();

        expect(Tombstone::is(memberEntry(3)))->toBeTrue();
    });
});

it('clears nothing on a save that changed nothing', function () {
    Member::cached(1);

    Member::query()->findOrFail(1)->save();

    expect(memberEntry(1)[0]['name'] ?? null)->toBe('Ada');
});

it('does not write back a row read before a change', function () {
    // Right after the read's query, before its write: another request renames the member.
    $changed = false;
    DB::listen(function (QueryExecuted $query) use (&$changed) {
        if (! $changed && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"members"')) {
            $changed = true;
            $member = Member::query()->findOrFail(1);
            $member->name = 'Ada Lovelace';
            $member->save();
        }
    });

    expect(Member::cached(1)?->name)->toBe('Ada')
        ->and(Tombstone::is(memberEntry(1)))->toBeTrue();
});
