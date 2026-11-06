<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Runtime;

use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Redis;
use RedisException;
use TypeError;

/**
 * Reads several keys with Repository::many(), so that a rejected MGET is an
 * error and not an answer (LRVL-79, ADR 0007).
 *
 * phpredis answers an MGET that Redis rejects (NOPERM from an ACL, OOM,
 * LOADING on a replica) with false, as for a nil reply, and keeps the error
 * aside. Laravel then either fails with a TypeError (11.44.2 and 12.0.1 map
 * over that false) or answers that every key is missing (later releases),
 * and neither follows the Redis error policy. Here the error is thrown as a
 * RedisException, so it does. predis throws a rejected MGET itself.
 *
 * @internal
 */
final class RedisReads
{
    /**
     * @param  list<string>  $keys
     * @return array<string, mixed> by key, as Repository::many() returns them
     *
     * @throws RedisException when Redis rejects the MGET on phpredis
     */
    public static function many(Repository $repository, array $keys): array
    {
        $client = self::phpRedisClient($repository);

        if ($client === null) {
            /** @var array<string, mixed> */
            return $repository->many($keys);
        }

        $client->clearLastError();

        try {
            /** @var array<string, mixed> $entries */
            $entries = $repository->many($keys);
        } catch (TypeError $e) {
            // Laravel 11.44.2 and 12.0.1: array_map() over the false of a rejected MGET.
            $error = $client->getLastError();

            if (is_string($error)) {
                throw new RedisException($error, 0, $e);
            }

            throw $e;
        }

        $error = $client->getLastError();

        if (is_string($error)) {
            throw new RedisException($error);
        }

        return $entries;
    }

    /** The phpredis client behind the repository's store; null for any other store or client. */
    private static function phpRedisClient(Repository $repository): ?Redis
    {
        $store = $repository->getStore();

        if (! $store instanceof RedisStore) {
            return null;
        }

        $connection = $store->connection();

        return $connection instanceof PhpRedisConnection ? $connection->client() : null;
    }
}
