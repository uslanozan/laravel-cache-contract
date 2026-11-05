<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Runtime;

use Closure;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Redis\Connections\PhpRedisConnection;
use RedisException;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;

/**
 * The shared remove and write paths of ADR 0012: a removal leaves a
 * tombstone, and a loader writes only if the key still holds what its read
 * found, so a value loaded before a removal is never written back after it.
 *
 * On Redis the compare and the write are one Lua script, so they are atomic.
 * Other stores fall back to add(): a tombstone then blocks a loader's write
 * until it expires.
 *
 * Every method lets errors out; the caller applies the Redis error policy.
 *
 * @internal
 */
final readonly class EntryStore
{
    public const DEFAULT_TOMBSTONE_TTL = 10;

    /**
     * Writes the value if the key holds what the read found: nothing
     * (ARGV[3] empty) or the same tombstone (ARGV[3], packed as the store
     * packs it). Returns 1 when it wrote.
     *
     * KEYS[1] the key, ARGV[1] the packed value, ARGV[2] the TTL in seconds,
     * ARGV[3] the packed tombstone the read found, or ''.
     */
    private const COMPARE_AND_SET = <<<'LUA'
local current = redis.call('get', KEYS[1])
if (ARGV[3] == '' and current == false) or (ARGV[3] ~= '' and current == ARGV[3]) then
    redis.call('setex', KEYS[1], ARGV[2], ARGV[1])
    return 1
end
return 0
LUA;

    /**
     * Returns the value and leaves a tombstone in its place; an empty key
     * stays empty.
     *
     * KEYS[1] the key, ARGV[1] the packed tombstone, ARGV[2] its TTL.
     */
    private const TAKE = <<<'LUA'
local current = redis.call('get', KEYS[1])
if current == false then
    return false
end
redis.call('setex', KEYS[1], ARGV[2], ARGV[1])
return current
LUA;

    /**
     * @param  int  $tombstoneTtl  seconds a tombstone lives
     */
    public function __construct(public int $tombstoneTtl = self::DEFAULT_TOMBSTONE_TTL) {}

    /**
     * @throws InvalidConfiguration when "tombstone_ttl" is not a whole number of seconds, 1 or more
     */
    public static function fromConfig(mixed $tombstoneTtl): self
    {
        $tombstoneTtl ??= self::DEFAULT_TOMBSTONE_TTL;

        if (is_int($tombstoneTtl) && $tombstoneTtl >= 1) {
            return new self($tombstoneTtl);
        }

        throw InvalidConfiguration::forKey('tombstone_ttl', sprintf(
            'expected a whole number of seconds, 1 or more, got %s%s',
            is_string($tombstoneTtl) ? '"'.$tombstoneTtl.'"' : (is_int($tombstoneTtl) ? (string) $tombstoneTtl : get_debug_type($tombstoneTtl)),
            is_string($tombstoneTtl) && is_numeric($tombstoneTtl) ? '; a numeric string from env() must be cast: (int) env(...)' : '',
        ));
    }

    public function read(Repository $repository, string $key): StoredEntry
    {
        return new StoredEntry($repository->get($key));
    }

    /**
     * Leaves a new tombstone at the key, for tombstone_ttl seconds. This is
     * the removal: it does not tell whether an entry was there.
     */
    public function bury(Repository $repository, string $key): bool
    {
        return $repository->put($key, Tombstone::make(), $this->tombstoneTtl);
    }

    /**
     * A loader's write: only if the key still holds what $seen found. A
     * foreign value is overwritten, so such a key is repaired instead of
     * blocking every write until it expires (ADR 0012).
     *
     * @param  array{0: mixed}  $envelope
     * @return bool true when the value was written
     */
    public function writeIfUnchanged(Repository $repository, string $key, StoredEntry $seen, array $envelope, int $ttl): bool
    {
        if ($seen->isForeign()) {
            return $repository->put($key, $envelope, $ttl);
        }

        $store = $repository->getStore();

        if (! $store instanceof RedisStore) {
            return $repository->add($key, $envelope, $ttl);
        }

        $connection = $store->connection();

        return (int) $this->evaluate($store, self::COMPARE_AND_SET, $key, [
            $this->pack($store, $envelope, $connection),
            max(1, $ttl),
            $seen->isTombstone() ? $this->pack($store, $seen->stored, $connection) : '',
        ]) === 1;
    }

    /**
     * Caches the value when nothing or (on Redis) a tombstone is cached; on
     * other stores a tombstone blocks it until it expires. An envelope or a
     * foreign value counts as cached. Of two concurrent calls on Redis, one
     * writes.
     *
     * @param  array{0: mixed}  $envelope
     * @return bool true when this call wrote the value
     */
    public function add(Repository $repository, string $key, array $envelope, int $ttl): bool
    {
        $seen = $this->read($repository, $key);

        return ($seen->isEmpty() || $seen->isTombstone()) && $this->writeIfUnchanged($repository, $key, $seen, $envelope, $ttl);
    }

    /**
     * Reads the entry and leaves a new tombstone in its place, in one script.
     * An empty key stays empty.
     */
    public function take(RedisStore $store, string $key): StoredEntry
    {
        $connection = $store->connection();
        $raw = $this->evaluate($store, self::TAKE, $key, [
            $this->pack($store, Tombstone::make(), $connection),
            $this->tombstoneTtl,
        ]);

        return new StoredEntry($raw === false || $raw === null ? null : $this->unpack($store, $raw, $connection));
    }

    /**
     * Runs a script on the store's own connection. When a command inside the
     * script fails (one the ACL forbids, OOM, WRONGTYPE), phpredis answers
     * with false, as for a nil reply, and keeps the error aside; that error
     * is thrown, so it follows the Redis error policy instead of being taken
     * for an answer. A rejected EVAL itself is thrown by phpredis already.
     *
     * @param  list<mixed>  $arguments
     *
     * @throws RedisException when Redis rejects the script
     */
    private function evaluate(RedisStore $store, string $script, string $key, array $arguments): mixed
    {
        $connection = $store->connection();
        $key = $store->getPrefix().$key;

        if (! $connection instanceof PhpRedisConnection) {
            // Predis (not supported yet, D13) throws a failed script itself.
            return $connection->command('eval', [$script, 1, $key, ...$arguments]);
        }

        $client = $connection->client();
        $client->clearLastError();

        $result = $connection->eval($script, 1, $key, ...$arguments);

        if ($result === false && is_string($error = $client->getLastError())) {
            throw new RedisException($error);
        }

        return $result;
    }

    /**
     * The bytes RedisStore::put() would store for $value, with the
     * connection's serializer and compression: what a script writes and
     * compares. RedisStore::add() packs its value the same way.
     */
    private function pack(RedisStore $store, mixed $value, mixed $connection): mixed
    {
        /** @var Closure(mixed, mixed): mixed $pack */
        $pack = Closure::bind(
            // Protected in RedisStore, in every supported Laravel release (11.44.2+).
            fn (mixed $value, mixed $connection): mixed => $this->pack($value, $connection),
            $store,
            RedisStore::class,
        );

        return $pack($value, $connection);
    }

    /**
     * Decodes bytes a script returned, as RedisStore::get() decodes a value:
     * phpredis applies its serializer and compression to GET replies but not
     * to a script's, so they are undone here first.
     */
    private function unpack(RedisStore $store, mixed $raw, mixed $connection): mixed
    {
        if ($connection instanceof PhpRedisConnection && ($connection->serialized() || $connection->compressed())) {
            $raw = $connection->client()->_unpack($raw);

            if ($connection->serialized()) {
                return $raw;
            }
        }

        /** @var Closure(mixed): mixed $unserialize */
        $unserialize = Closure::bind(
            // Protected in RedisStore, in every supported Laravel release (11.44.2+).
            fn (mixed $value): mixed => $this->unserialize($value),
            $store,
            RedisStore::class,
        );

        return $unserialize($raw);
    }
}
