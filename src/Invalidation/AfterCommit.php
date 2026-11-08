<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Invalidation;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;

/**
 * Runs invalidation once a change is committed, on the connection that made
 * it. Shared by HasCache and invalidated_by, so both clear at the same moment.
 * The model cache asks it whether a transaction is open before it writes.
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

    /**
     * Whether a transaction is open on $connection, so what it reads may not
     * be committed yet. The transaction RefreshDatabase and
     * DatabaseTransactions wrap each test in does not count, as Laravel's
     * after-commit callbacks do not wait for it either; otherwise nothing
     * would be cached in the application's tests.
     */
    public static function isOpen(Connection $connection): bool
    {
        $level = $connection->transactionLevel();

        if ($level === 0) {
            return false;
        }

        $manager = app()->bound('db.transactions') ? app('db.transactions') : null;

        if (! $manager instanceof DatabaseTransactionsManager) {
            return true;
        }

        $name = $connection->getName();
        $onConnection = static fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $name;

        // Laravel's testing manager leaves the wrapping test transactions out of the applicable ones.
        $wrapping = $manager->getPendingTransactions()->filter($onConnection)->count()
            - $manager->callbackApplicableTransactions()->filter($onConnection)->count();

        return $level > $wrapping;
    }
}
