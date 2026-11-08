<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Runtime;

/**
 * What happens when Redis cannot be reached during one kind of operation
 * (ADR 0007).
 */
enum RedisErrorMode: string
{
    /** Rethrow the connection error. */
    case Throw = 'throw';

    /** Pass it to report() (log, error tracker) and continue without the cache. */
    case Report = 'report';
}
