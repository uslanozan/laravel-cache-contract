<?php

declare(strict_types=1);

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Uslanozan\CacheContract\CacheContractServiceProvider;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Runtime\Tombstone;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;

/*
 * increment() and decrement() on a model fire "updated" but never "saved", so
 * both the model cache (HasCache) and invalidated_by clear on "updated"; "saved"
 * clears only an insert, so a save() is cleared once (LRVL-78).
 */

beforeEach(function () {
    ModelCacheDatabase::create(); // seeded without model events
    config()->set('cache-contract.definitions', [
        'member' => [
            'logins' => [
                'purpose' => 'logins', 'type' => 'array', 'ttl' => 300, 'description' => 'Logins per member.',
                'invalidated_by' => [Member::class],
            ],
        ],
    ]);
    app()->register(CacheContractServiceProvider::class, true); // listeners come from the config at boot
});

/** @return array<int, int> logins by member ID, through a definition with invalidated_by */
function cachedLogins(): array
{
    /** @var array<int, int> */
    return CacheContract::remember('member.logins', [], fn () => Member::query()->pluck('logins', 'id')->all());
}

/** What happens to member 1 when $change runs, through both kinds of invalidation. */
dataset('changes that fire only "updated"', [
    'increment()' => [fn (Member $member) => $member->increment('logins'), 1],
    'increment() by 5' => [fn (Member $member) => $member->increment('logins', 5), 5],
    'decrement() after a raw +3' => [function (Member $member) {
        Member::query()->whereKey(1)->update(['logins' => 3]); // no events, like a background job
        $member->refresh();
        $member->decrement('logins');
    }, 2],
]);

it('clears the model cache after a change that fires only "updated"', function (Closure $change, int $expected) {
    expect(Member::cached(1)?->logins)->toBe(0);

    $change(Member::query()->findOrFail(1));

    expect(Member::cached(1)?->logins)->toBe($expected);
})->with('changes that fire only "updated"');

it('invalidates definitions with the model in invalidated_by after a change that fires only "updated"', function (Closure $change, int $expected) {
    expect(cachedLogins())->toBe([1 => 0]);

    $change(Member::query()->findOrFail(1));

    expect(cachedLogins())->toBe([1 => $expected]);
})->with('changes that fire only "updated"');

it('clears after incrementEach() and decrementEach() too', function () {
    Member::cached(1);
    cachedLogins();
    $member = Member::query()->findOrFail(1);

    $member->incrementEach(['logins' => 4]);
    expect(Member::cached(1)?->logins)->toBe(4)
        ->and(cachedLogins())->toBe([1 => 4]);

    $member->decrementEach(['logins' => 1]);
    expect(Member::cached(1)?->logins)->toBe(3)
        ->and(cachedLogins())->toBe([1 => 3]);
})->skip(! method_exists(Model::class, 'incrementEach'), 'this Laravel release has no Model::incrementEach() (added after 12.0.1; not in 11.x)');

it('clears an increment() inside a transaction after the commit, and nothing on rollback', function () {
    Member::cached(1);

    DB::transaction(function () {
        Member::query()->findOrFail(1)->increment('logins');

        expect(Member::cached(1)?->logins)->toBe(0); // the old entry stays until the commit
    });

    expect(Member::cached(1)?->logins)->toBe(1);

    DB::beginTransaction();
    Member::query()->findOrFail(1)->increment('logins');
    DB::rollBack();

    expect(Member::cached(1)?->logins)->toBe(1);
});

it('clears a save() once, not on both "updated" and "saved"', function () {
    Member::cached(1);
    cachedLogins();
    $tombstones = 0;
    $bumps = 0;
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$tombstones, &$bumps) {
        if (Tombstone::is($event->value)) {
            $tombstones++;
        } elseif (str_contains($event->key, 'def:member.logins')) {
            $bumps++;
        }
    });

    $member = Member::query()->findOrFail(1);
    $member->name = 'Grace';
    $member->save();

    expect($tombstones)->toBe(1)
        ->and($bumps)->toBe(1);
});

it('clears an insert once, with the plain delete of a created row', function () {
    Member::cached(2); // "no such row"
    $tombstones = 0;
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$tombstones) {
        $tombstones += Tombstone::is($event->value) ? 1 : 0;
    });

    Member::create([
        'id' => 2, 'organization_id' => 1, 'name' => 'Grace', 'password' => 'x', 'settings' => [],
        'is_admin' => false, 'status' => 'active', 'last_login_at' => '2026-10-13 21:00:00',
    ]);

    expect($tombstones)->toBe(0)
        ->and(Member::cached(2)?->name)->toBe('Grace');
});

it('sees nothing from incrementQuietly(), which fires no events', function () {
    Member::cached(1);

    Member::query()->findOrFail(1)->incrementQuietly('logins');

    expect(Member::cached(1)?->logins)->toBe(0); // documented: like saveQuietly()
});
