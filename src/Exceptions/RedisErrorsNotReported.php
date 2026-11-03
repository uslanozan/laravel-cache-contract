<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Exceptions;

use RuntimeException;

/**
 * Reported next to a Redis connection error when the errors since the
 * previous report were left out (ADR 0007, update 2026-11-04): how many, per
 * kind of operation. Invalidate errors mean stale entries may be cached.
 */
final class RedisErrorsNotReported extends RuntimeException implements CacheContractException
{
    /**
     * @param  array<string, int>  $counts  by operation ("read", "write", "invalidate")
     */
    private function __construct(string $message, private readonly array $counts, private readonly int $seconds)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, int>  $counts  by operation, none of them zero
     */
    public static function since(array $counts, int $seconds): self
    {
        $parts = [];

        foreach ($counts as $operation => $count) {
            $parts[] = "{$operation}: {$count}";
        }

        return new self(sprintf(
            '%d more Redis connection errors in the last %d seconds were not reported (%s).',
            array_sum($counts),
            $seconds,
            implode(', ', $parts),
        ), $counts, $seconds);
    }

    /**
     * Added to the log entry by Laravel's exception handler.
     *
     * @return array{not_reported: array<string, int>, seconds: int}
     */
    public function context(): array
    {
        return ['not_reported' => $this->counts, 'seconds' => $this->seconds];
    }
}
