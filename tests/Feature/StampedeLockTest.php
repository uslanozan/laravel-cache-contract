<?php

declare(strict_types=1);

use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Tests\Fakes\FailingStore;
use Uslanozan\CacheContract\Tests\Fakes\NonLockingStore;

// The stampede lock of ADR 0012 on the array store, which can lock. The same
// behaviour against real Redis is in tests/Integration/StampedeLockRedisTest.php.

beforeEach(function () {
    config()->set('cache-contract.definitions', [
        'report' => [
            'totals' => [
                'purpose' => 'totals', 'type' => 'array', 'ttl' => 600, 'description' => 'Totals.',
                'lock' => ['seconds' => 10, 'wait' => 0],
            ],
            'plain' => ['purpose' => 'plain', 'type' => 'array', 'ttl' => 600, 'description' => 'No lock.'],
        ],
    ]);
});

/** Resolved inside each test, after it picks its store: the manager reads the store once. */
function totalsLock(): string
{
    return CacheContract::key('report.totals').'|lock';
}

it('runs the loader with the lock held, and releases it afterwards', function () {
    $heldDuringLoad = null;

    $value = CacheContract::remember('report.totals', [], function () use (&$heldDuringLoad) {
        $heldDuringLoad = ! Cache::lock(totalsLock(), 10)->get();

        return ['sum' => 42];
    });

    expect($value)->toBe(['sum' => 42])
        ->and($heldDuringLoad)->toBeTrue()
        ->and(CacheContract::get('report.totals'))->toBe(['sum' => 42])
        ->and(Cache::lock(totalsLock(), 10)->get())->toBeTrue(); // released
});

it('reads again with the lock: a value written while waiting is returned without loading', function () {
    // The first read misses; before this request takes the lock, the holder caches its value.
    Event::listen(CacheMissed::class, function () {
        Event::forget(CacheMissed::class);
        CacheContract::put('report.totals', [], ['sum' => 'from the holder']);
    });
    $loaded = false;

    $value = CacheContract::remember('report.totals', [], function () use (&$loaded) {
        $loaded = true;

        return ['sum' => 'mine'];
    });

    expect($value)->toBe(['sum' => 'from the holder'])
        ->and($loaded)->toBeFalse();
});

describe('when the wait ends without the lock', function () {
    beforeEach(function () {
        Cache::lock(totalsLock(), 10)->get(); // another request holds it
    });

    it('loads instead of failing, and caches the value', function () {
        expect(CacheContract::remember('report.totals', [], fn () => ['sum' => 'mine']))->toBe(['sum' => 'mine'])
            ->and(CacheContract::get('report.totals'))->toBe(['sum' => 'mine']);
    });

    it('never overwrites what the holder wrote meanwhile', function () {
        $value = CacheContract::remember('report.totals', [], function () {
            CacheContract::put('report.totals', [], ['sum' => 'from the holder']); // the holder finishes first

            return ['sum' => 'mine'];
        });

        expect($value)->toBe(['sum' => 'mine'])
            ->and(CacheContract::get('report.totals'))->toBe(['sum' => 'from the holder']);
    });

    it('does not release the lock it never got', function () {
        CacheContract::remember('report.totals', [], fn () => 'mine');

        expect(Cache::lock(totalsLock(), 10)->get())->toBeFalse();
    });
});

it('releases the lock when the loader throws, and lets the exception out', function () {
    expect(fn () => CacheContract::remember('report.totals', [], fn () => throw new RuntimeException('loader failed')))
        ->toThrow(RuntimeException::class, 'loader failed')
        ->and(Cache::lock(totalsLock(), 10)->get())->toBeTrue();
});

it('takes no lock on a hit, for a definition without lock, or in get(), put() and refresh()', function () {
    $store = new FailingStore;
    Cache::extend('failing', fn () => Cache::repository($store));
    config()->set('cache.stores.failing', ['driver' => 'failing']);
    config()->set('cache-contract.store', 'failing');

    CacheContract::remember('report.plain', [], fn () => 1);
    CacheContract::put('report.totals', [], 1);
    CacheContract::remember('report.totals', [], fn () => 2); // a hit
    CacheContract::get('report.totals');
    CacheContract::refresh('report.totals', [], fn () => 3);

    expect($store->calls)->not->toContain('lock')
        ->and($store->calls)->toContain('put'); // the calls did reach this store
});

describe('a Redis error while taking the lock', function () {
    beforeEach(function () {
        $store = $this->store = (new FailingStore)->fail('lock');
        Cache::extend('failing', fn () => Cache::repository($store));
        config()->set('cache.stores.failing', ['driver' => 'failing']);
        config()->set('cache-contract.store', 'failing');
        Exceptions::fake();
    });

    it('follows the read policy: the loader runs and nothing is written', function () {
        expect(CacheContract::remember('report.totals', [], fn () => 'loaded'))->toBe('loaded')
            ->and($this->store->calls)->not->toContain('put');

        Exceptions::assertReported(RedisException::class);
    });

    it('is thrown when the read policy says so', function () {
        config()->set('cache-contract.redis_errors.read', 'throw');

        expect(fn () => CacheContract::remember('report.totals', [], fn () => 'loaded'))->toThrow(RedisException::class);
    });
});

it('runs without a lock on a store that cannot lock', function () {
    $store = new NonLockingStore;
    Cache::extend('non_locking', fn () => Cache::repository($store));
    config()->set('cache.stores.non_locking', ['driver' => 'non_locking']);
    config()->set('cache-contract.store', 'non_locking');

    expect(CacheContract::remember('report.totals', [], fn () => 'loaded'))->toBe('loaded')
        ->and(CacheContract::get('report.totals'))->toBe('loaded');
});
