<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Similarity;

/**
 * How alike two names are: a score, the kinds of resemblance (signals), the
 * parts the score is made of, and human-readable reasons.
 * See docs/similarity-engine.md.
 */
final readonly class NameScore
{
    /**
     * @param  float  $score  combined score, 0.0 to 1.0
     * @param  list<NameSignal>  $signals
     * @param  float  $wordOverlap  soft Jaccard of the word sets, 0.0 to 1.0
     * @param  float  $characterSimilarity  normalized Levenshtein of the normalized names, 0.0 to 1.0
     * @param  array{string, string}|null  $opposite  a pair of opposite words, if any
     * @param  list<string>  $reasons
     */
    public function __construct(
        public float $score,
        public array $signals,
        public float $wordOverlap,
        public float $characterSimilarity,
        public ?array $opposite,
        public array $reasons,
    ) {}

    public function has(NameSignal $signal): bool
    {
        return in_array($signal, $this->signals, true);
    }

    public function hasOppositeMeaning(): bool
    {
        return $this->opposite !== null;
    }
}
