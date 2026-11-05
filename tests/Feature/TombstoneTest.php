<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Runtime\CacheContractManager;
use Uslanozan\CacheContract\Runtime\StoredEntry;
use Uslanozan\CacheContract\Runtime\Tombstone;

// ADR 0012 on stores other than Redis (here the array store): removals leave a
// tombstone, and loader writes and add() fall back to add(), so a tombstone
// blocks them until it expires. The race tests run against real Redis, in
// tests/Integration/TombstoneRedisTest.php.

beforeEach(function () {
    config()->set('cache-contract.definitions', [
        'user' => [
            'profile' => [
                'purpose' => 'profile', 'type' => 'array', 'params' => ['user_id' => 'int'],
                'ttl' => 300, 'description' => 'Profile page data.',
            ],
        ],
    ]);

    $this->key = 'cc:user:profile:v1:user_id=1';
});

it('leaves a tombstone on forget, which every read treats as a miss', function () {
    CacheContract::put('user.profile', ['user_id' => 1], ['name' => 'Ozan']);

    expect(CacheContract::forget('user.profile', ['user_id' => 1]))->toBeTrue()
        ->and(Tombstone::is(Cache::get($this->key)))->toBeTrue()
        ->and(CacheContract::get('user.profile', ['user_id' => 1], 'default'))->toBe('default')
        ->and(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse();
});

it('returns true from forget when nothing was cached', function () {
    expect(CacheContract::forget('user.profile', ['user_id' => 1]))->toBeTrue();
});

it('writes a new token for every removal', function () {
    CacheContract::forget('user.profile', ['user_id' => 1]);
    $first = Cache::get($this->key);
    CacheContract::forget('user.profile', ['user_id' => 1]);

    expect(Cache::get($this->key))->not->toBe($first);
});

it('does not cache a loaded value while a tombstone lives, outside Redis', function () {
    CacheContract::forget('user.profile', ['user_id' => 1]);
    $calls = 0;
    $loader = function () use (&$calls) {
        $calls++;

        return ['name' => 'Barış'];
    };

    expect(CacheContract::remember('user.profile', ['user_id' => 1], $loader))->toBe(['name' => 'Barış'])
        ->and(CacheContract::remember('user.profile', ['user_id' => 1], $loader))->toBe(['name' => 'Barış'])
        ->and($calls)->toBe(2);

    $this->travel(11)->seconds();
    CacheContract::remember('user.profile', ['user_id' => 1], $loader);

    expect(CacheContract::has('user.profile', ['user_id' => 1]))->toBeTrue()
        ->and($calls)->toBe(3);
});

it('does not write back a value loaded before a forget', function () {
    $value = CacheContract::remember('user.profile', ['user_id' => 1], function () {
        CacheContract::forget('user.profile', ['user_id' => 1]); // the row changes while the loader runs

        return ['name' => 'old'];
    });

    expect($value)->toBe(['name' => 'old'])
        ->and(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse();
});

it('repairs a key that holds a value written by other code', function () {
    Cache::put($this->key, 'not an envelope', 300);

    CacheContract::remember('user.profile', ['user_id' => 1], fn () => ['name' => 'Ozan']);

    expect(CacheContract::get('user.profile', ['user_id' => 1]))->toBe(['name' => 'Ozan']);
});

it('counts a foreign value as cached in add()', function () {
    Cache::put($this->key, 'not an envelope', 300);

    expect(CacheContract::add('user.profile', ['user_id' => 1], ['name' => 'Ozan']))->toBeFalse();
});

it('uses tombstone_ttl from the config', function () {
    config()->set('cache-contract.tombstone_ttl', 3);
    CacheContract::forget('user.profile', ['user_id' => 1]);

    $this->travel(2)->seconds();
    expect(CacheContract::add('user.profile', ['user_id' => 1], 'a'))->toBeFalse();

    $this->travel(1)->seconds();
    expect(CacheContract::add('user.profile', ['user_id' => 1], 'b'))->toBeTrue();
});

it('lets put() and refresh() write over a tombstone at once', function () {
    CacheContract::forget('user.profile', ['user_id' => 1]);
    CacheContract::put('user.profile', ['user_id' => 1], 'put');
    expect(CacheContract::get('user.profile', ['user_id' => 1]))->toBe('put');

    CacheContract::forget('user.profile', ['user_id' => 1]);
    CacheContract::refresh('user.profile', ['user_id' => 1], fn () => 'refreshed');
    expect(CacheContract::get('user.profile', ['user_id' => 1]))->toBe('refreshed');
});

it('deletes on pull outside Redis, leaving no tombstone', function () {
    CacheContract::put('user.profile', ['user_id' => 1], 'once');

    expect(CacheContract::pull('user.profile', ['user_id' => 1]))->toBe('once')
        ->and(Cache::has($this->key))->toBeFalse()
        ->and(CacheContract::add('user.profile', ['user_id' => 1], 'again'))->toBeTrue();
});

it('rejects a tombstone_ttl that is not a whole number of seconds, 1 or more', function (mixed $value, string $got) {
    config()->set('cache-contract.tombstone_ttl', $value);

    expect(fn () => app(CacheContractManager::class))
        ->toThrow(InvalidConfiguration::class, '"cache-contract.tombstone_ttl": expected a whole number of seconds, 1 or more, got '.$got);
})->with([
    'zero' => [0, '0'],
    'negative' => [-5, '-5'],
    'numeric string, with a hint for env()' => ['10', '"10"; a numeric string from env() must be cast: (int) env(...)'],
    'float' => [2.5, 'float'],
]);

it('falls back to 10 seconds when tombstone_ttl is null', function () {
    config()->set('cache-contract.tombstone_ttl', null);
    CacheContract::forget('user.profile', ['user_id' => 1]);

    $this->travel(9)->seconds();
    expect(CacheContract::add('user.profile', ['user_id' => 1], 'a'))->toBeFalse();

    $this->travel(1)->seconds();
    expect(CacheContract::add('user.profile', ['user_id' => 1], 'b'))->toBeTrue();
});

describe('what a read found', function () {
    it('tells the four cases apart', function (mixed $stored, array $expected) {
        $entry = new StoredEntry($stored);

        expect([$entry->isEmpty(), $entry->isHit(), $entry->isTombstone(), $entry->isForeign()])->toBe($expected);
    })->with([
        'nothing' => [null, [true, false, false, false]],
        'a hit' => [[['name' => 'Ozan']], [false, true, false, false]],
        'a cached null' => [[null], [false, true, false, false]],
        'a tombstone' => [Tombstone::PREFIX.'abc', [false, false, true, false]],
        'a foreign string' => ['legacy', [false, false, false, true]],
        'a foreign array' => [['a', 'b'], [false, false, false, true]],
    ]);

    it('gives a different token every time', function () {
        expect(Tombstone::make())->not->toBe(Tombstone::make())
            ->and(Tombstone::is(Tombstone::make()))->toBeTrue();
    });
});
