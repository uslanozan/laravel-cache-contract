<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Exceptions\RedisErrorsNotReported;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Runtime\CacheContractManager;
use Uslanozan\CacheContract\Runtime\RedisErrorPolicy;
use Uslanozan\CacheContract\Runtime\RedisErrorReporter;
use Uslanozan\CacheContract\Runtime\RedisOperation;
use Uslanozan\CacheContract\Tests\Fakes\FailingStore;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Company;

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

/** @param array<string, mixed> $modes */
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

describe('add and pull', function () {
    it('checks the parameters before touching the store', function (Closure $call) {
        expect($call)->toThrow(InvalidParameters::class)
            ->and($this->store->calls)->toBe([]);
    })->with([
        'add' => [fn () => CacheContract::add('external.exchange_rates', ['region' => 'eu'], 'x')],
        'pull' => [fn () => CacheContract::pull('external.exchange_rates', ['region' => 'eu'])],
    ]);

    it('treats a failed add as not written', function () {
        // The array store has no atomic add, so Laravel reads, then writes.
        $this->store->fail('put');

        expect(CacheContract::add('external.exchange_rates', [], ['usd' => 1]))->toBeFalse();
        Exceptions::assertReported(RedisException::class);
    });

    it('treats a failed read in pull as a miss', function () {
        $this->store->fail('get');

        expect(CacheContract::pull('external.exchange_rates', [], 'default'))->toBe('default');
        Exceptions::assertReported(RedisException::class);
    });

    it('does not hand out a value it could not remove', function () {
        CacheContract::put('external.exchange_rates', [], ['usd' => 1]);
        $this->store->fail('forget');

        expect(CacheContract::pull('external.exchange_rates', [], 'default'))->toBe('default');
        Exceptions::assertReported(RedisException::class);
    });

    it('rethrows a failed add when write is set to throw', function () {
        redisErrors(['read' => 'report', 'write' => 'throw', 'invalidate' => 'report']);
        $this->store->fail('put');

        expect(fn () => CacheContract::add('external.exchange_rates', [], ['usd' => 1]))->toThrow(RedisException::class);
    });

    it('rethrows a failed delete in pull when invalidate is set to throw', function () {
        redisErrors(['read' => 'report', 'write' => 'report', 'invalidate' => 'throw']);
        CacheContract::put('external.exchange_rates', [], ['usd' => 1]);
        $this->store->fail('forget');

        expect(fn () => CacheContract::pull('external.exchange_rates'))->toThrow(RedisException::class);
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

describe('a definition with generation counters', function () {
    beforeEach(function () {
        config()->set('cache-contract.definitions.company.summary', [
            'purpose' => 'summary', 'type' => 'array', 'params' => ['company_id' => 'int'], 'ttl' => 300,
            'description' => 'Company summary.', 'invalidated_by' => [Company::class],
        ]);
    });

    it('treats a failed counter read like a failed read of the entry, and writes nothing', function () {
        $this->store->fail('get');
        $params = ['company_id' => 3];

        expect(CacheContract::remember('company.summary', $params, fn () => 'loaded'))->toBe('loaded')
            ->and(CacheContract::refresh('company.summary', $params, fn () => 'refreshed'))->toBe('refreshed')
            ->and(CacheContract::get('company.summary', $params, 'default'))->toBe('default')
            ->and(CacheContract::has('company.summary', $params))->toBeFalse()
            ->and(CacheContract::put('company.summary', $params, 'value'))->toBeFalse()
            ->and(CacheContract::forget('company.summary', $params))->toBeFalse()
            ->and(array_unique($this->store->calls))->toBe(['get']);

        Exceptions::assertReported(RedisException::class);
    });

    it('follows the mode of the operation for a failed counter read', function (string $operation, Closure $call) {
        redisErrors([$operation => 'throw']);
        $this->store->fail('get');

        expect($call)->toThrow(RedisException::class);
    })->with([
        'read' => ['read', fn () => CacheContract::get('company.summary', ['company_id' => 3])],
        'write' => ['write', fn () => CacheContract::put('company.summary', ['company_id' => 3], 1)],
        'invalidate' => ['invalidate', fn () => CacheContract::forget('company.summary', ['company_id' => 3])],
    ]);

    it('checks the parameters before reading the counters', function () {
        expect(fn () => CacheContract::remember('company.summary', ['company_id' => '3'], fn () => 1))->toThrow(InvalidParameters::class)
            ->and($this->store->calls)->toBe([]);
    });

    it('checks the parameters before reading the counters in key() too', function () {
        $this->store->fail('get');

        expect(fn () => CacheContract::key('company.summary', ['company_id' => '3']))->toThrow(InvalidParameters::class)
            ->and($this->store->calls)->toBe([]);
    });

    it('lets key() fail whatever the mode, since it cannot show a key without the counters', function () {
        $this->store->fail('get');

        expect(fn () => CacheContract::key('company.summary', ['company_id' => 3]))->toThrow(RedisException::class);
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

describe('reports during an outage', function () {
    beforeEach(function () {
        $this->store->fail('get')->fail('put')->fail('forget');
    });

    it('reports the first error and only counts the others for a minute', function () {
        for ($i = 0; $i < 1000; $i++) {
            CacheContract::get('external.exchange_rates');
        }

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(RedisException::class);
    });

    it('shares one interval between reads, writes and invalidations', function () {
        CacheContract::get('external.exchange_rates');
        CacheContract::put('external.exchange_rates', [], 1);
        CacheContract::forget('external.exchange_rates');

        Exceptions::assertReportedCount(1);
    });

    it('reports again after the interval, with the errors it left out per operation', function () {
        CacheContract::get('external.exchange_rates');

        for ($i = 0; $i < 5; $i++) {
            CacheContract::get('external.exchange_rates');
        }

        CacheContract::put('external.exchange_rates', [], 1);
        CacheContract::forget('external.exchange_rates');
        CacheContract::forget('external.exchange_rates');

        $this->travel(59)->seconds();
        CacheContract::get('external.exchange_rates');
        Exceptions::assertReportedCount(1);

        $this->travel(2)->seconds();
        CacheContract::get('external.exchange_rates');

        Exceptions::assertReportedCount(3);
        Exceptions::assertReported(fn (RedisErrorsNotReported $e): bool => $e->getMessage() === '9 more Redis connection errors in the last 61 seconds were not reported (read: 6, write: 1, invalidate: 2).'
            && $e->context() === ['not_reported' => ['read' => 6, 'write' => 1, 'invalidate' => 2], 'seconds' => 61]);
    });

    it('adds no summary when nothing was left out', function () {
        CacheContract::get('external.exchange_rates');
        $this->travel(61)->seconds();
        CacheContract::get('external.exchange_rates');

        Exceptions::assertReportedCount(2);
        Exceptions::assertNotReported(RedisErrorsNotReported::class);
    });

    it('uses report_interval from the config', function () {
        redisErrors(['report_interval' => 10]);

        CacheContract::get('external.exchange_rates');
        $this->travel(9)->seconds();
        CacheContract::get('external.exchange_rates');
        Exceptions::assertReportedCount(1);

        $this->travel(1)->seconds();
        CacheContract::get('external.exchange_rates');

        Exceptions::assertReportedCount(3); // two errors and the summary of the one left out
    });

    it('does not hold reports back when the clock steps back', function () {
        CacheContract::get('external.exchange_rates');
        CacheContract::get('external.exchange_rates');
        Exceptions::assertReportedCount(1);

        // NTP moves the clock back: the error is reported at once, not 30 seconds later.
        $this->travelTo(now()->subSeconds(30));
        CacheContract::get('external.exchange_rates');

        Exceptions::assertReportedCount(3); // the error and the summary of the one left out
        Exceptions::assertReported(fn (RedisErrorsNotReported $e): bool => $e->context() === ['not_reported' => ['read' => 1], 'seconds' => 0]);

        // A new interval starts from the new time.
        $this->travel(59)->seconds();
        CacheContract::get('external.exchange_rates');
        Exceptions::assertReportedCount(3);
    });

    it('reports every error with report_interval 0', function () {
        redisErrors(['report_interval' => 0]);

        for ($i = 0; $i < 5; $i++) {
            CacheContract::get('external.exchange_rates');
        }

        Exceptions::assertReportedCount(5);
        Exceptions::assertNotReported(RedisErrorsNotReported::class);
    });

    it('never holds back an error the policy throws', function () {
        redisErrors(['read' => 'throw']);

        CacheContract::put('external.exchange_rates', [], 1);

        expect(fn () => CacheContract::get('external.exchange_rates'))->toThrow(RedisException::class)
            ->and(fn () => CacheContract::get('external.exchange_rates'))->toThrow(RedisException::class);
        Exceptions::assertReportedCount(1);
    });

    it('keeps the interval of the process when the policy is built again', function () {
        // On Octane, the policy can be built again in every request; the reporter,
        // resolved at boot, is the same for all of them.
        expect(app()->resolved(RedisErrorReporter::class))->toBeTrue();

        $error = new RedisException('Connection refused');
        app(RedisErrorPolicy::class)->handle($error, RedisOperation::Read);
        app()->forgetInstance(RedisErrorPolicy::class);
        app(RedisErrorPolicy::class)->handle($error, RedisOperation::Read);

        Exceptions::assertReportedCount(1);
    });
});

it('rejects a report_interval that is not a whole number of seconds, 0 or more', function (mixed $value, string $got) {
    redisErrors(['report_interval' => $value]);

    expect(fn () => app(CacheContractManager::class))
        ->toThrow(InvalidConfiguration::class, '"cache-contract.redis_errors.report_interval": expected a whole number of seconds, 0 or more, got '.$got);
})->with([
    'negative' => [-1, '-1'],
    'numeric string, with a hint for env()' => ['60', '"60"; a numeric string from env() must be cast: (int) env(...)'],
    'float' => [1.5, 'float'],
    'text' => ['one minute', '"one minute"'],
]);

it('gives the env() hint only for a numeric string', function () {
    redisErrors(['report_interval' => 'one minute']);

    try {
        app(CacheContractManager::class);
        $this->fail('Expected InvalidConfiguration.');
    } catch (InvalidConfiguration $e) {
        expect($e->getMessage())->not->toContain('env()');
    }
});
