<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Contracts;

use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Similarity\Finding;

/**
 * Finds existing definitions that collide with or resemble a candidate.
 *
 * Deterministic: the same input always gives the same findings, so the result
 * can be used in CI.
 */
interface SimilarityEngine
{
    /**
     * @param  list<Definition>  $existing  definitions to compare against; the
     *                                      candidate itself may be among them
     *                                      and is never reported against itself
     * @return list<Finding> highest score first
     */
    public function findSimilar(Definition $candidate, array $existing): array;
}
