<?php

declare(strict_types=1);

use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Runtime\Tombstone;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Support\Concurrently;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;
use Uslanozan\CacheContract\Tests\Support\RedisClient;

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

    config()->set('database.redis.client', RedisClient::name());

    $server = ['host' => env('REDIS_HOST', '127.0.0.1'), 'port' => (int) env('REDIS_PORT', 6379), 'database' => 1, 'max_retries' => 0];
    // read_timeout is phpredis', read_write_timeout predis'.
    config()->set('database.redis.concurrency_store', [...$server, 'timeout' => 1.0, 'read_timeout' => 2.0, 'read_write_timeout' => 2.0]);
    // The signals block up to Concurrently::TIMEOUT seconds: a longer read timeout.
    config()->set('database.redis.concurrency', [...$server, 'timeout' => 1.0, 'read_timeout' => Concurrently::TIMEOUT + 5, 'read_write_timeout' => Concurrently::TIMEOUT + 5]);
    config()->set('cache.stores.concurrency', ['driver' => 'redis', 'connection' => 'concurrency_store']);
    config()->set('cache-contract.store', 'concurrency');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));
    config()->set('cache-contract.tags', ['reports' => 'Every report.']);
    config()->set('cache-contract.definitions', [
        'report' => [
            'totals' => ['purpose' => 'totals', 'type' => 'array', 'ttl' => 600, 'description' => 'Totals.'],
            'guard' => ['purpose' => 'guard', 'type' => 'array', 'params' => ['round' => 'int'], 'ttl' => 600, 'description' => 'Guard.'],
            'locked' => ['purpose' => 'locked', 'type' => 'array', 'ttl' => 600, 'description' => 'Locked.', 'lock' => ['seconds' => 10, 'wait' => 10]],
            'impatient' => ['purpose' => 'impatient', 'type' => 'array', 'ttl' => 600, 'description' => 'Locked, no wait.', 'lock' => ['seconds' => 10, 'wait' => 0]],
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
 * concurrently() for the model cache: each process reopens the shared SQLite file.
 *
 * @param  list<Closure(Concurrently): mixed>  $workers
 * @return list<mixed>
 */
function concurrentlyWithDatabase(array $workers): array
{
    return Concurrently::run($workers, 'concurrency', ['concurrency'], databases: [ModelCacheDatabase::CONNECTION]);
}

/**
 * Right after the first SELECT on members in this process, before the model
 * cache writes what it read: signals "read", then waits for "changed".
 */
function pauseAfterReadingMembers(Concurrently $run): void
{
    $paused = false;
    DB::listen(function (QueryExecuted $query) use ($run, &$paused) {
        if (! $paused && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"members"')) {
            $paused = true;
            $run->signal('read');
            $run->await('changed');
        }
    });
}

function renameMemberOne(Concurrently $run): void
{
    $run->await('read');
    $member = Member::query()->findOrFail(1);
    $member->name = 'Ada Lovelace';
    $member->save(); // HasCache leaves a tombstone: no transaction, so at once
    $run->signal('changed');
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

describe('the stampede lock', function () {
    it('lets one of ten processes load a cold key while the others wait for its value', function () {
        $readers = times(10, function (Concurrently $run): array {
            Event::listen(CacheMissed::class, fn () => $run->signal('missed'));
            $loaded = false;

            $value = CacheContract::remember('report.locked', [], function () use ($run, &$loaded) {
                $loaded = true;
                $run->signal('loading');
                $run->await('finish');

                return ['by' => getmypid()];
            });

            return [$value, $loaded];
        });

        // The loader holds the lock until all ten processes have missed: none of them
        // finds the value by luck, every one of them has to wait for it.
        $results = concurrently([...$readers, function (Concurrently $run): void {
            $run->await('loading');
            for ($i = 0; $i < 10; $i++) {
                $run->await('missed');
            }
            $run->signal('finish');
        }]);
        array_pop($results);

        $values = array_column($results, 0);

        expect(array_filter(array_column($results, 1)))->toHaveCount(1) // the loader ran once
            ->and(array_unique(array_map(serialize(...), $values)))->toHaveCount(1)
            ->and(CacheContract::get('report.locked'))->toBe($values[0]);
    });

    it('lets a request whose wait ends load too, without overwriting the holder', function () {
        [$holder, $waiter] = concurrently([
            function (Concurrently $run) {
                $value = CacheContract::remember('report.impatient', [], function () use ($run) {
                    $run->signal('holding');
                    $run->await('waiter loading');

                    return ['by' => 'holder'];
                });
                $run->signal('holder wrote');

                return $value;
            },
            function (Concurrently $run) {
                $run->await('holding'); // the lock is taken, and "wait" is 0

                return CacheContract::remember('report.impatient', [], function () use ($run) {
                    $run->signal('waiter loading');
                    $run->await('holder wrote');

                    return ['by' => 'waiter'];
                });
            },
        ]);

        expect($holder)->toBe(['by' => 'holder'])
            ->and($waiter)->toBe(['by' => 'waiter'])
            ->and(CacheContract::get('report.impatient'))->toBe(['by' => 'holder']);
    });
});

// The in-memory test database is copied into each process, so these tests share
// a SQLite file that every process reopens.
describe('the model cache', function () {
    beforeEach(function () {
        $this->database = (string) tempnam(sys_get_temp_dir(), 'cc_concurrency_');
        ModelCacheDatabase::create($this->database);
        ModelCacheDatabase::addMembers(1); // member 2
    });

    afterEach(function () {
        @unlink($this->database);
    });

    it('does not cache a row find() read before another process changed it', function () {
        [$read] = concurrentlyWithDatabase([
            function (Concurrently $run) {
                pauseAfterReadingMembers($run);

                return Member::cached(1)?->name;
            },
            fn (Concurrently $run) => renameMemberOne($run),
        ]);

        expect($read)->toBe('Ada')
            ->and(Tombstone::is(Cache::store('concurrency')->get(Member::modelCache()->key(1))))->toBeTrue()
            ->and(Member::cached(1)?->name)->toBe('Ada Lovelace');
    });

    it('does not cache a row findMany() read before another process changed it, and caches the others', function () {
        [$read] = concurrentlyWithDatabase([
            function (Concurrently $run) {
                pauseAfterReadingMembers($run);

                return Member::cachedMany([1, 2])->pluck('name')->all();
            },
            fn (Concurrently $run) => renameMemberOne($run),
        ]);

        expect($read)->toBe(['Ada', 'Member 2'])
            ->and(Tombstone::is(Cache::store('concurrency')->get(Member::modelCache()->key(1))))->toBeTrue()
            ->and(Cache::store('concurrency')->get(Member::modelCache()->key(2))[0]['name'] ?? null)->toBe('Member 2')
            ->and(Member::cachedMany([1, 2])->pluck('name')->all())->toBe(['Ada Lovelace', 'Member 2']);
    });
});
