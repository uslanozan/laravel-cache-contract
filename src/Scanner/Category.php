<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Scanner;

/**
 * What a usage is, as ADR 0011 sorts them: it decides whether lint flags it.
 *
 * @internal prototype (LRVL-18), the public scanner API is designed in phase 5
 */
enum Category: string
{
    /** The cache API: what the contract replaces. */
    case Cache = 'cache';

    /** Direct Redis, not the cache API. */
    case Redis = 'redis';

    /** The framework keeping its own keys in the cache store: RateLimiter, throttle middleware. Never linted. */
    case Indirect = 'indirect';

    /** A call to a configured wrapper class. Never linted: the cache call inside the wrapper is. */
    case Wrapper = 'wrapper';
}
