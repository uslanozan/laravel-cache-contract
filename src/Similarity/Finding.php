<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Similarity;

use Uslanozan\CacheContract\Definitions\Definition;

/**
 * One existing definition that resembles a candidate, and why.
 */
final readonly class Finding
{
    /**
     * @param  Definition  $match  the existing definition the candidate resembles
     * @param  float  $score  0.0 (unrelated) to 1.0 (identical)
     * @param  list<string>  $reasons  human-readable, e.g. "same subject and purpose"
     */
    public function __construct(
        public FindingType $type,
        public Definition $match,
        public float $score,
        public array $reasons = [],
    ) {}
}
