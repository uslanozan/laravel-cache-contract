<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Contracts;

use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;

/**
 * Builds the physical cache key for a definition (ADR 0004).
 *
 * The same definition, parameters and context always give the same key;
 * a different tenant, locale or other context always gives a different one.
 * A new value of any generation counter gives a new key, which is how a
 * group of entries is invalidated at once (ADR 0009).
 */
interface KeyFactory
{
    /**
     * @param  array<string, mixed>  $params  a value for every declared parameter, nothing more
     * @param  array<string, scalar>  $context  values that change the result but are not
     *                                          parameters of the definition (tenant, locale…)
     * @param  array<string, int>  $generations  the current value of every generation counter the
     *                                           entry depends on, by counter name: exactly
     *                                           Definition::generationCounters() (ADR 0009); read
     *                                           by the caller, the factory never talks to Redis
     *
     * @throws InvalidParameters when a parameter is missing, unexpected or of the wrong type,
     *                           or the generations are not ints for exactly the definition's counters
     * @throws InvalidConfiguration when a secret parameter is used without APP_KEY
     */
    public function make(Definition $definition, array $params, array $context = [], array $generations = []): string;
}
