<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fakes;

use Uslanozan\CacheContract\Contracts\SimilarityEngine;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Similarity\Finding;

/**
 * Returns whatever findings the test gives it and remembers what it was asked.
 * Lets commands and lint be built before the real engine exists (LRVL-9/10).
 */
final class FakeSimilarityEngine implements SimilarityEngine
{
    /** @var list<Definition> candidates passed to findSimilar(), in call order */
    public array $candidates = [];

    /**
     * @param  list<Finding>  $findings
     */
    public function __construct(private array $findings = []) {}

    public function findSimilar(Definition $candidate, array $existing): array
    {
        $this->candidates[] = $candidate;

        $findings = array_values(array_filter(
            $this->findings,
            fn (Finding $finding): bool => $finding->match->id !== $candidate->id,
        ));

        usort($findings, fn (Finding $a, Finding $b): int => $b->score <=> $a->score);

        return $findings;
    }
}
