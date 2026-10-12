<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Similarity;

use Uslanozan\CacheContract\Definitions\Definition;

/**
 * One existing definition that resembles a candidate, how serious it is,
 * and why.
 */
final readonly class Finding
{
    /**
     * @param  Definition  $match  the existing definition the candidate resembles
     * @param  float  $score  name score, 0.0 (unrelated) to 1.0 (identical)
     * @param  list<string>  $reasons  human-readable, e.g. "same subject and purpose"
     */
    public function __construct(
        public FindingType $type,
        public Severity $severity,
        public Definition $match,
        public float $score,
        public array $reasons = [],
    ) {}

    public function isError(): bool
    {
        return $this->severity === Severity::Error;
    }
}
