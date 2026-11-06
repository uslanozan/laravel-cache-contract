<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Support;

use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use Predis\Connection\ConnectionException;
use Predis\Response\ServerException;
use RedisException;
use Throwable;

/**
 * The Redis client the integration tests run on: REDIS_CLIENT, "phpredis"
 * (the default) or "predis". CI runs the suite on both (LRVL-81); locally:
 *
 *     docker compose exec -e REDIS_CLIENT=predis php composer test
 *
 * Tests about one client's own behaviour (phpredis' serializer and
 * compression options, its last error) skip on the other one with
 * skipUnlessPhpRedis(). RedisClientErrorsTest runs on both clients whatever
 * REDIS_CLIENT says.
 */
final class RedisClient
{
    public const CLIENTS = ['phpredis', 'predis'];

    public static function name(): string
    {
        $client = env('REDIS_CLIENT');

        if ($client === null || $client === '') {
            return 'phpredis';
        }

        if (! in_array($client, self::CLIENTS, true)) {
            throw new InvalidArgumentException(sprintf('REDIS_CLIENT must be "phpredis" or "predis", got "%s".', is_string($client) ? $client : get_debug_type($client)));
        }

        return $client;
    }

    public static function isPhpRedis(): bool
    {
        return self::name() === 'phpredis';
    }

    /** Skips the running test on predis: it checks something only phpredis has. */
    public static function skipUnlessPhpRedis(string $why): void
    {
        if (! self::isPhpRedis()) {
            test()->markTestSkipped('phpredis only: '.$why);
        }
    }

    /**
     * What the client throws when Redis cannot be reached.
     *
     * @return class-string<Throwable>
     */
    public static function connectionError(): string
    {
        return self::isPhpRedis() ? RedisException::class : ConnectionException::class;
    }

    /**
     * What the client throws for a command Redis rejects (NOPERM, OOM,
     * WRONGTYPE), and the package for a script that fails inside Redis.
     *
     * @return class-string<Throwable>
     */
    public static function rejectedCommandError(): string
    {
        return self::isPhpRedis() ? RedisException::class : ServerException::class;
    }

    /**
     * Asserts that a command Redis rejected was reported, with this text in its
     * message. The fake matches a closure by its exact parameter type, so the
     * closure is typed for the client.
     */
    public static function assertRejectedCommandReported(string $needle): void
    {
        self::isPhpRedis()
            ? Exceptions::assertReported(fn (RedisException $e): bool => str_contains($e->getMessage(), $needle))
            : Exceptions::assertReported(fn (ServerException $e): bool => str_contains($e->getMessage(), $needle));
    }

    /**
     * A connection to the test server (or to a port nothing listens on), with
     * short timeouts on both clients: read_timeout is phpredis',
     * read_write_timeout predis'.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function connection(bool $reachable = true, array $overrides = []): array
    {
        return [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => $reachable ? (int) env('REDIS_PORT', 6379) : 1,
            'database' => 1,
            'timeout' => 1.0,
            'read_timeout' => 1.0,
            'read_write_timeout' => 1.0,
            'max_retries' => 0,
            ...$overrides,
        ];
    }
}
