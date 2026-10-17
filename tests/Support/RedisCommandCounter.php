<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Support;

use Closure;
use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;

/**
 * Counts the Redis commands one connection sends, from the client side.
 *
 * The server-wide counter (CONFIG RESETSTAT, INFO commandstats) also counts
 * other clients, such as the CI service health check's PING, and made these
 * tests flaky (LRVL-65). Laravel fires CommandExecuted for every command a
 * connection sends, so listening on it counts this connection only.
 *
 * Commands Redis runs inside a Lua script are not sent by the client, so an
 * EVAL counts as one command.
 */
final class RedisCommandCounter
{
    /**
     * Calls per command (lowercase) that $connection sends while $operation runs.
     *
     * @return array<string, int>
     */
    public static function count(Connection $connection, Closure $operation): array
    {
        $calls = [];
        $previous = $connection->getEventDispatcher();

        // A dispatcher of its own: no listener outlives the measurement.
        $connection->setEventDispatcher(new Dispatcher);
        $connection->listen(function (CommandExecuted $event) use (&$calls) {
            $command = strtolower($event->command);
            $calls[$command] = ($calls[$command] ?? 0) + 1;
        });

        try {
            $operation();
        } finally {
            $previous === null ? $connection->unsetEventDispatcher() : $connection->setEventDispatcher($previous);
        }

        ksort($calls);

        return $calls;
    }
}
