<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Support;

use Closure;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Runs closures in parallel processes (pcntl_fork) against real Redis, for
 * the races that one process cannot show (LRVL-43).
 *
 *     [$a, $b] = Concurrently::run([
 *         fn (Concurrently $run) => CacheContract::remember('report.totals', [], function () use ($run) {
 *             $run->signal('loaded');
 *             $run->await('forgotten');
 *
 *             return 'old';
 *         }),
 *         function (Concurrently $run) {
 *             $run->await('loaded');
 *             CacheContract::forget('report.totals');
 *             $run->signal('forgotten');
 *         },
 *     ]);
 *
 * The order between processes is set with signals through Redis, never with
 * sleep(): a slow CI machine cannot change it, so a test fails only when the
 * code is wrong. Every wait has a timeout, so a stuck process fails the test
 * with a message instead of hanging it.
 *
 * All processes start together. Each one opens its own Redis connections
 * (sharing the parent's socket would mix their replies), sends back what its
 * closure returned or threw, and ends with SIGKILL, so it runs no PHPUnit
 * shutdown code of its own.
 */
final class Concurrently
{
    /** Seconds a process waits for a signal, by default; the parent waits longer for the results. */
    public const TIMEOUT = 10;

    private function __construct(
        private readonly Connection $redis,
        private readonly string $run,
        private readonly int $timeout,
    ) {}

    public static function supported(): bool
    {
        return function_exists('pcntl_fork') && function_exists('posix_kill');
    }

    /**
     * @param  list<Closure(self): mixed>  $workers
     * @param  string  $connection  a Redis connection whose read_timeout exceeds $timeout + 5
     * @param  list<string>  $stores  cache stores to rebuild in each process
     * @param  int  $timeout  seconds a process waits for a signal
     * @return list<mixed> what each closure returned, in order
     */
    public static function run(array $workers, string $connection = 'concurrency', array $stores = [], int $timeout = self::TIMEOUT): array
    {
        $run = 'cc_concurrency:'.bin2hex(random_bytes(6));
        $count = count($workers);
        $pids = [];

        foreach ($workers as $index => $worker) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork() failed');
            }

            if ($pid === 0) {
                self::child($worker, $index, $count, $run, $connection, $stores, $timeout); // never returns
            }

            $pids[] = $pid;
        }

        $redis = self::connection($connection);

        try {
            // Every process is ready: let them all go at once.
            // Longer than a process waits, so its own timeout is what the test reports.
            $parentTimeout = $timeout + 5;
            self::pop($redis, "{$run}:ready", $count, $parentTimeout);
            $redis->rpush("{$run}:go", ...array_fill(0, $count, '1'));

            $results = [];
            foreach (array_keys($workers) as $index) {
                [$reply] = self::pop($redis, "{$run}:result:{$index}", 1, $parentTimeout);
                $result = unserialize($reply);

                if (is_array($result) && array_key_exists('error', $result)) {
                    throw new RuntimeException(sprintf('Process %d failed: %s', $index, (string) $result['error']));
                }

                $results[] = $result['value'] ?? null;
            }

            return $results;
        } finally {
            foreach ($pids as $pid) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }

            $redis->del(...array_map(
                fn (string $suffix): string => "{$run}:{$suffix}",
                ['ready', 'go', ...array_map(fn (int $i): string => "result:{$i}", array_keys($workers))],
            ));
        }
    }

    /** Tells the processes waiting for $name that it happened, $times times. */
    public function signal(string $name, int $times = 1): void
    {
        $this->redis->rpush("{$this->run}:signal:{$name}", ...array_fill(0, $times, '1'));
    }

    /** Waits until $name is signalled; fails after the run's timeout. */
    public function await(string $name): void
    {
        self::pop($this->redis, "{$this->run}:signal:{$name}", 1, $this->timeout, $name);
    }

    /**
     * Waits until $count processes reached this point, then lets them all go.
     */
    public function barrier(string $name, int $count): void
    {
        if ((int) $this->redis->incr("{$this->run}:barrier:{$name}") === $count) {
            $this->signal("barrier:{$name}", $count);
        }

        $this->await("barrier:{$name}");
    }

    /**
     * @param  Closure(self): mixed  $worker
     * @param  list<string>  $stores
     */
    private static function child(Closure $worker, int $index, int $count, string $run, string $connection, array $stores, int $timeout): never
    {
        try {
            // New connections for this process: the parent's sockets stay the parent's.
            app()->forgetInstance('redis');
            foreach ($stores as $store) {
                Cache::forgetDriver($store);
            }

            $redis = self::connection($connection);
            $self = new self($redis, $run, $timeout);

            $redis->rpush("{$run}:ready", '1');
            self::pop($redis, "{$run}:go", 1, $timeout + 5, 'go');

            try {
                $reply = serialize(['value' => $worker($self)]);
            } catch (Throwable $e) {
                $reply = serialize(['error' => $e::class.': '.$e->getMessage()]);
            }

            $redis->rpush("{$run}:result:{$index}", $reply);
        } finally {
            posix_kill((int) posix_getpid(), SIGKILL);
        }

        exit(1); // not reached
    }

    private static function connection(string $name): Connection
    {
        /** @var Connection */
        return app('redis')->connection($name);
    }

    /**
     * Pops $count items from $key, waiting up to $timeout seconds for each.
     *
     * @return list<string>
     */
    private static function pop(Connection $redis, string $key, int $count, int $timeout, ?string $what = null): array
    {
        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $reply = $redis->blpop($key, $timeout);

            if (! is_array($reply) || ! isset($reply[1])) {
                throw new RuntimeException(sprintf('Timed out after %d seconds waiting for "%s"', $timeout, $what ?? $key));
            }

            $items[] = (string) $reply[1];
        }

        return $items;
    }
}
