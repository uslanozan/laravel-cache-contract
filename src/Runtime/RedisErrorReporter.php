<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Runtime;

use Illuminate\Support\Facades\Date;
use Throwable;
use Uslanozan\CacheContract\Exceptions\RedisErrorsNotReported;

/**
 * Reports Redis connection errors, at most one per interval (ADR 0007, update
 * 2026-11-04): during an outage every cache call fails, and reporting each
 * one would flood the log and the error tracker.
 *
 * The first error is reported, the ones in the next interval are only
 * counted, and the first one after it is reported with those counts. The
 * state belongs to the process, not to a request: it has a fixed size (a
 * timestamp and three counts), and on Octane and queue workers it is meant to
 * outlive the request or job.
 */
final class RedisErrorReporter
{
    /** When the last error was reported (Unix time), or null before the first one. */
    private ?int $reportedAt = null;

    /** @var array<string, int> errors not reported since then, by operation */
    private array $notReported = [];

    /**
     * @param  int  $interval  seconds; 0 reports every error
     */
    public function report(Throwable $e, RedisOperation $operation, int $interval): void
    {
        $now = Date::now()->getTimestamp();

        if ($interval > 0 && $this->reportedAt !== null && $now - $this->reportedAt < $interval) {
            $this->notReported[$operation->value] = ($this->notReported[$operation->value] ?? 0) + 1;

            return;
        }

        $counts = $this->counts();
        $since = $this->reportedAt;
        $this->reportedAt = $now;
        $this->notReported = [];

        report($e);

        if ($counts !== [] && $since !== null) {
            report(RedisErrorsNotReported::since($counts, $now - $since));
        }
    }

    /** @return array<string, int> the counts in the order of RedisOperation */
    private function counts(): array
    {
        $counts = [];

        foreach (RedisOperation::cases() as $operation) {
            if (isset($this->notReported[$operation->value])) {
                $counts[$operation->value] = $this->notReported[$operation->value];
            }
        }

        return $counts;
    }
}
