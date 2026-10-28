<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Invalidation;

use Closure;
use Illuminate\Database\Connection;

/**
 * Runs invalidation once a change is committed, on the connection that made
 * it. Shared by HasCache and invalidated_by, so both clear at the same moment.
 */
final class AfterCommit
{
    /**
     * Runs $callback after the current transaction on $connection commits,
     * or now when there is none; never on rollback.
     */
    public static function on(Connection $connection, Closure $callback): void
    {
        // afterCommit() alone attaches it to the last open transaction on any connection.
        // shortcut: a transaction on another connection opened inside one on this connection still gets it; register per connection if that case shows up.
        if ($connection->transactionLevel() === 0) {
            $callback();
        } else {
            $connection->afterCommit($callback);
        }
    }
}
