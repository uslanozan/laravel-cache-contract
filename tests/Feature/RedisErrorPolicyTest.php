<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Runtime\CacheContractManager;
use Uslanozan\CacheContract\Tests\Fakes\FailingStore;

beforeEach(function () {
    config()->set('cache-contract.definitions.external.exchange_rates', [
        'purpose' => 'exchange_rates', 'type' => 'array', 'ttl' => 600, 'description' => 'Rates.',
    ]);

    // Cache::extend() rebinds $this inside the closure, so capture the store.
    $store = $this->store = new FailingStore;
    Cache::extend('failing', fn () => Cache::repository($store));
    config()->set('cache.stores.failing', ['driver' => 'failing']);
    config()->set('cache-contract.store', 'failing');

    Exceptions::fake();
});

/** @param array<string, string> $modes */
function redisErrors(array $modes): void
{
    config()->set('cache-contract.redis_errors', $modes);
}

describe('report (default)', function () {
    it('treats a failed read as a miss and skips the write', function () {
        $this->store->fail('get');

        expect(CacheContract::remember('external.exchange_rates', [], fn () => ['usd' => 1]))->toBe(['usd' => 1])
            ->and($this->store->calls)->toBe(['get'])
            ->and(CacheContract::get('external.exchange_rates', [], 'default'))->toBe('default')
            ->and(CacheContract::has('external.exchange_rates'))->toBeFalse();

        Exceptions::assertReported(RedisException::class);
    });

    it('returns the loaded value when the write fails', function () {
        $this->store->fail('put');

        expect(CacheContract::remember('external.exchange_rates', [], fn () => ['usd' => 1]))->toBe(['usd' => 1])
            ->and(CacheContract::refresh('external.exchange_rates', [], fn () => ['usd' => 2]))->toBe(['usd' => 2])
            ->and(CacheContract::put('external.exchange_rates', [], ['usd' => 3]))->toBeFalse();

        Exceptions::assertReported(RedisException::class);
    });

    it('reports a failed invalidation', function () {
        $this->store->fail('forget');

        expect(CacheContract::forget('external.exchange_rates'))->toBeFalse();

        Exceptions::assertReported(RedisException::class);
    });
});

describe('throw', function () {
    it('rethrows for the operations set to throw', function (string $method, Closure $call) {
        redisErrors(['read' => 'throw', 'write' => 'throw', 'invalidate' => 'throw']);
        $this->store->fail($method);

        expect($call)->toThrow(RedisException::class);
        Exceptions::assertNothingReported();
    })->with([
        'read' => ['get', fn () => CacheContract::get('external.exchange_rates')],
        'write' => ['put', fn () => CacheContract::put('external.exchange_rates', [], 1)],
        'invalidate' => ['forget', fn () => CacheContract::forget('external.exchange_rates')],
    ]);

    it('lets each operation have its own mode', function () {
        redisErrors(['read' => 'report', 'write' => 'report', 'invalidate' => 'throw']);
        $this->store->fail('get')->fail('forget');

        expect(CacheContract::get('external.exchange_rates', [], 'default'))->toBe('default')
            ->and(fn () => CacheContract::forget('external.exchange_rates'))->toThrow(RedisException::class);
    });
});

it('always rethrows errors that are not connection errors', function () {
    $this->store->fail('get', new RuntimeException('Unreadable entry'));

    expect(fn () => CacheContract::get('external.exchange_rates'))->toThrow(RuntimeException::class, 'Unreadable entry');
    Exceptions::assertNothingReported();
});

it('rejects an unknown mode', function () {
    redisErrors(['read' => 'ignore']);

    expect(fn () => app(CacheContractManager::class))
        ->toThrow(InvalidConfiguration::class, '"cache-contract.redis_errors.read": expected "throw" or "report", got "ignore"');
});
