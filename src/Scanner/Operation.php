<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Scanner;

/**
 * What a cache call does, grouped the way the roadmap inventory reports it.
 *
 * @internal prototype (LRVL-18), the public scanner API is designed in phase 5
 */
enum Operation: string
{
    case Read = 'read';
    case Write = 'write';

    /** A read, plus a write on a miss. */
    case Remember = 'remember';
    case Forget = 'forget';

    /** Anything else: locks, Redis data structures, unknown methods. */
    case Other = 'other';

    public static function fromMethod(string $method): self
    {
        // pull() reads and forgets; it is counted as a read.
        return match (strtolower($method)) {
            'get', 'has', 'missing', 'many', 'getmultiple', 'pull', 'exists', 'mget' => self::Read,
            'put', 'set', 'add', 'forever', 'putmany', 'setmultiple', 'increment', 'decrement',
            'incr', 'decr', 'setex', 'setnx' => self::Write,
            'remember', 'rememberforever', 'sear', 'flexible' => self::Remember,
            'forget', 'delete', 'deletemultiple', 'flush', 'clear', 'del' => self::Forget,
            default => self::Other,
        };
    }
}
