<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Facades\ModelCache;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;
use Uslanozan\CacheContract\Tests\Support\QueryCounter;

/*
 * A shorter TTL for a cached null, "not found" (LRVL-60): a definition's
 * optional null_ttl, and the model cache's models.null_ttl (60 s by default)
 * with per-model overrides. Only null: false, 0 and [] are values like any
 * other (ADR 0007).
 */

beforeEach(function () {
    config()->set('cache-contract.definitions', [
        'license' => [
            'status' => [
                'purpose' => 'status', 'type' => 'array', 'params' => ['key' => 'string'],
                'ttl' => '1 hour', 'null_ttl' => '1 minute', 'description' => 'License status by key.',
            ],
            'plain' => [
                'purpose' => 'plain', 'type' => 'array', 'params' => ['key' => 'string'],
                'ttl' => '1 hour', 'description' => 'No null_ttl.',
            ],
        ],
    ]);
});

/** Inserts member $id the way model events do not see: a raw insert, as from another service. */
function insertMemberQuietly(int $id): void
{
    DB::table('members')->insert([
        'id' => $id, 'organization_id' => 1, 'name' => "Member {$id}", 'password' => 'x', 'settings' => '[]',
        'is_admin' => false, 'status' => 'active', 'last_login_at' => '2026-10-13 21:00:00',
    ]);
}

describe('a definition with null_ttl', function () {
    it('keeps a cached null for null_ttl, then loads again', function () {
        $calls = 0;
        $load = function () use (&$calls) {
            $calls++;

            return null; // not found yet
        };

        CacheContract::remember('license.status', ['key' => 'abc'], $load);
        $this->travel(59)->seconds();
        CacheContract::remember('license.status', ['key' => 'abc'], $load);
        expect($calls)->toBe(1);

        $this->travel(2)->seconds();
        CacheContract::remember('license.status', ['key' => 'abc'], $load);
        expect($calls)->toBe(2);
    });

    it('keeps a found value for the whole ttl', function () {
        CacheContract::remember('license.status', ['key' => 'abc'], fn () => ['valid' => true]);
        $this->travel(59)->minutes();

        expect(CacheContract::has('license.status', ['key' => 'abc']))->toBeTrue();
    });

    it('applies to every write of a null, and only of a null', function (Closure $write, bool $cachedAfterTwoMinutes) {
        $write();
        $this->travel(2)->minutes();

        expect(CacheContract::has('license.status', ['key' => 'abc']))->toBe($cachedAfterTwoMinutes);
    })->with([
        'put(null)' => [fn () => CacheContract::put('license.status', ['key' => 'abc'], null), false],
        'add(null)' => [fn () => CacheContract::add('license.status', ['key' => 'abc'], null), false],
        'refresh() to null' => [fn () => CacheContract::refresh('license.status', ['key' => 'abc'], fn () => null), false],
        'false is a value' => [fn () => CacheContract::put('license.status', ['key' => 'abc'], false), true],
        '0 is a value' => [fn () => CacheContract::put('license.status', ['key' => 'abc'], 0), true],
        'an empty array is a value' => [fn () => CacheContract::put('license.status', ['key' => 'abc'], []), true],
    ]);

    it('changes nothing for a definition without one', function () {
        CacheContract::put('license.plain', ['key' => 'abc'], null);
        $this->travel(59)->minutes();

        expect(CacheContract::has('license.plain', ['key' => 'abc']))->toBeTrue();
    });

    it('is shown by inspect', function () {
        Artisan::call('cache-contract:inspect', ['id' => 'license.status']);
        $withNullTtl = Artisan::output();
        Artisan::call('cache-contract:inspect', ['id' => 'license.plain']);

        expect($withNullTtl)->toMatch('/Null TTL\s.*60 s \(1 minute\)/')
            ->and(Artisan::output())->toMatch('/Null TTL\s.*- \(as the TTL\)/');
    });
});

describe('the model cache', function () {
    beforeEach(function () {
        ModelCacheDatabase::create(); // seeded without model events
    });

    it('keeps "no such row" for 60 seconds by default, so a row created unseen shows up', function () {
        expect(Member::cached(2))->toBeNull();
        insertMemberQuietly(2);

        $this->travel(59)->seconds();
        expect(Member::cached(2))->toBeNull(); // still the cached "no such row"

        $this->travel(2)->seconds();
        expect(Member::cached(2)?->name)->toBe('Member 2');
    });

    it('keeps a row for the whole TTL', function () {
        Member::cached(1);
        $this->travel(59)->minutes();

        expect(QueryCounter::count(fn () => Member::cached(1)))->toBe(0);
    });

    it('does the same in findMany()', function () {
        Member::cachedMany([1, 2]);
        insertMemberQuietly(2);
        $this->travel(61)->seconds();

        expect(Member::cachedMany([1, 2])->pluck('name')->all())->toBe(['Ada', 'Member 2']);
    });

    it('takes models.null_ttl, a per-model override, and null for as long as a row', function (array $models, int $expiresAfter) {
        config()->set('cache-contract.models', $models + ['ttl' => 3600]);

        ModelCache::for(Member::class)->find(2);
        insertMemberQuietly(2);

        $this->travel($expiresAfter - 1)->seconds();
        expect(ModelCache::for(Member::class)->find(2))->toBeNull();

        $this->travel(2)->seconds();
        expect(ModelCache::for(Member::class)->find(2)?->name)->toBe('Member 2');
    })->with([
        'models.null_ttl' => [['null_ttl' => 300], 300],
        'an override' => [['overrides' => [Member::class => ['null_ttl' => 10]]], 10],
        'never longer than an override\'s TTL' => [['overrides' => [Member::class => ['ttl' => 30]]], 30],
        'null: as long as a row' => [['null_ttl' => null], 3600],
    ]);
});
