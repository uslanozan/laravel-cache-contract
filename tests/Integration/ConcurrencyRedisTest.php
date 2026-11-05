<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Runtime\Tombstone;
use Uslanozan\CacheContract\Tests\Support\Concurrently;

/*
 * Races between processes against real Redis (LRVL-43): parallel calls on the
 * same key, and an invalidation while a loader runs (ADR 0009, ADR 0012).
 * The processes are ordered with signals through Redis, never with sleep(),
 * so the tests give the same result on every run (see Concurrently).
 *
 * The client comes from REDIS_CLIENT (phpredis by default).
 */

beforeEach(function () {
    if (! Concurrently::supported()) {
        $this->markTestSkipped('needs the pcntl and posix extensions');
    }

    $client = env('REDIS_CLIENT', 'phpredis');
    config()->set('database.redis.client', is_string($client) && $client !== '' ? $client : 'phpredis');

    $server = ['host' => env('REDIS_HOST', '127.0.0.1'), 'port' => (int) env('REDIS_PORT', 6379), 'database' => 1, 'max_retries' => 0];
    config()->set('database.redis.concurrency_store', [...$server, 'timeout' => 1.0, 'read_timeout' => 2.0]);
    // The signals block up to Concurrently::TIMEOUT seconds: a longer read timeout.
    config()->set('database.redis.concurrency', [...$server, 'timeout' => 1.0, 'read_timeout' => Concurrently::TIMEOUT + 5]);
    config()->set('cache.stores.concurrency', ['driver' => 'redis', 'connection' => 'concurrency_store']);
    config()->set('cache-contract.store', 'concurrency');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));
    config()->set('cache-contract.tags', ['reports' => 'Every report.']);
    config()->set('cache-contract.definitions', [
        'report' => [
            'totals' => ['purpose' => 'totals', 'type' => 'array', 'ttl' => 600, 'description' => 'Totals.'],
            'guard' => ['purpose' => 'guard', 'type' => 'array', 'params' => ['round' => 'int'], 'ttl' => 600, 'description' => 'Guard.'],
            'tagged' => ['purpose' => 'tagged', 'type' => 'array', 'ttl' => 600, 'description' => 'Tagged.', 'tags' => ['reports']],
        ],
    ]);
});

/**
 * @param  list<Closure(Concurrently): mixed>  $workers
 * @return list<mixed>
 */
function concurrently(array $workers): array
{
    return Concurrently::run($workers, 'concurrency', ['concurrency']);
}

/**
 * @return list<Closure(Concurrently): mixed>
 */
function times(int $count, Closure $worker): array
{
    return array_fill(0, $count, $worker);
}

describe('parallel calls on the same key', function () {
    it('lets exactly one of ten add() calls write', function (int $round) {
        // The window between a read and a write inside add() is too short to open with
        // signals, so atomicity is tested by numbers: ten processes, in five rounds.
        $results = concurrently(times(10, function (Concurrently $run) use ($round): array {
            $run->barrier('adding', 10);

            return [getmypid(), CacheContract::add('report.guard', ['round' => $round], ['by' => getmypid()])];
        }));

        $winners = array_values(array_filter($results, fn (array $result): bool => $result[1]));

        expect($winners)->toHaveCount(1)
            ->and(CacheContract::get('report.guard', ['round' => $round]))->toBe(['by' => $winners[0][0]]);
    })->with([1, 2, 3, 4, 5]);

    it('hands a pulled value to exactly one of ten pull() calls', function () {
        CacheContract::put('report.totals', [], ['code' => 'once']);

        $results = concurrently(times(10, fn () => CacheContract::pull('report.totals', [], 'taken')));

        // Which process wins changes from run to run; how many get what does not.
        expect(array_count_values(array_map(serialize(...), $results)))->toEqualCanonicalizing([
            serialize(['code' => 'once']) => 1,
            serialize('taken') => 9,
        ])->and(Tombstone::is(Cache::store('concurrency')->get(CacheContract::key('report.totals'))))->toBeTrue();
    });

    it('runs the loader in every process on a cold key without a lock, and caches one value', function () {
        // All ten loaders are running at the same time before any of them returns:
        // without a lock, a cold key is loaded once per request (the stampede).
        $results = concurrently(times(10, fn (Concurrently $run) => CacheContract::remember('report.totals', [], function () use ($run) {
            $run->barrier('loading', 10);

            return ['by' => getmypid()];
        })));

        $cached = CacheContract::get('report.totals');

        expect(array_unique(array_map(fn (array $value): int => $value['by'], $results)))->toHaveCount(10)
            ->and($results)->toContain($cached); // one of them won the write
    });
});

describe('an invalidation while a loader runs', function () {
    it('does not cache the old value after a forget()', function () {
        [$loaded] = concurrently([
            fn (Concurrently $run) => CacheContract::remember('report.totals', [], function () use ($run) {
                $run->signal('loading');
                $run->await('forgotten');

                return ['sum' => 'old'];
            }),
            function (Concurrently $run) {
                $run->await('loading');
                CacheContract::forget('report.totals');
                $run->signal('forgotten');
            },
        ]);

        expect($loaded)->toBe(['sum' => 'old'])
            ->and(CacheContract::has('report.totals'))->toBeFalse()
            ->and(CacheContract::remember('report.totals', [], fn () => ['sum' => 'new']))->toBe(['sum' => 'new']);
    });

    it('does not cache a value pulled while the loader ran', function () {
        concurrently([
            fn (Concurrently $run) => CacheContract::remember('report.totals', [], function () use ($run) {
                $run->signal('loading');
                $run->await('pulled');

                return ['code' => 'old'];
            }),
            function (Concurrently $run) {
                $run->await('loading');
                CacheContract::put('report.totals', [], ['code' => 'issued']);
                CacheContract::pull('report.totals');
                $run->signal('pulled');
            },
        ]);

        expect(CacheContract::has('report.totals'))->toBeFalse();
    });

    it('keeps a value loaded across a flushTag() under the old generation, where nobody reads it', function () {
        $before = CacheContract::key('report.tagged');

        concurrently([
            fn (Concurrently $run) => CacheContract::remember('report.tagged', [], function () use ($run) {
                $run->signal('loading');
                $run->await('flushed');

                return ['sum' => 'old'];
            }),
            function (Concurrently $run) {
                $run->await('loading');
                CacheContract::flushTag('reports');
                $run->signal('flushed');
            },
        ]);

        expect(Cache::store('concurrency')->get($before))->toBe([['sum' => 'old']]) // written, under the old key
            ->and(CacheContract::key('report.tagged'))->not->toBe($before)
            ->and(CacheContract::has('report.tagged'))->toBeFalse();
    });

    it('caches a value loaded after the forget() at once', function () {
        CacheContract::put('report.totals', [], ['sum' => 'old']);

        concurrently([
            function (Concurrently $run) {
                CacheContract::forget('report.totals');
                $run->signal('forgotten');
            },
            function (Concurrently $run) {
                $run->await('forgotten');

                return CacheContract::remember('report.totals', [], fn () => ['sum' => 'new']);
            },
        ]);

        expect(CacheContract::get('report.totals'))->toBe(['sum' => 'new']);
    });
});

it('fails with a message instead of hanging when a process waits for a signal nobody sends', function () {
    expect(fn () => Concurrently::run([fn (Concurrently $run) => $run->await('never')], 'concurrency', ['concurrency'], timeout: 1))
        ->toThrow(RuntimeException::class, 'Process 0 failed: RuntimeException: Timed out after 1 seconds waiting for "never"');
});
