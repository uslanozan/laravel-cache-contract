<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Contracts;

use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;

/**
 * Builds the physical cache key for a definition (ADR 0004).
 *
 * The same definition, parameters and context always give the same key;
 * a different tenant, locale or other context always gives a different one.
 */
interface KeyFactory
{
    /**
     * @param  array<string, mixed>  $params  a value for every declared parameter, nothing more
     * @param  array<string, scalar>  $context  values that change the result but are not
     *                                          parameters of the definition (tenant, locale…)
     *
     * @throws InvalidParameters when a parameter is missing, unexpected or of the wrong type
     */
    public function make(Definition $definition, array $params, array $context = []): string;
}
