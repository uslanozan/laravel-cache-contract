<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Runtime;

/**
 * The kinds of cache operation the "redis_errors" setting has a mode for
 * (ADR 0007).
 */
enum RedisOperation: string
{
    case Read = 'read';

    case Write = 'write';

    case Invalidate = 'invalidate';
}
