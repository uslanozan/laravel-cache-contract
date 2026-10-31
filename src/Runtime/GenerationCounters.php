<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Runtime;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Throwable;
use Uslanozan\CacheContract\Definitions\Ttl;

/**
 * Generation counters (ADR 0009): a group of entries is invalidated by giving
 * its counter a new value, which every dependent key contains.
 *
 * - A counter's value is a random 62-bit integer, never a sequence: a lost or
 *   expired counter is re-created at a new random value, so old entries are
 *   never read again.
 * - It is created with add() and a TTL, which is atomic, so concurrent
 *   creators agree; a bump writes a new random value instead of INCRBY.
 * - Values are stored in the ADR 0007 envelope, so Redis returns them as ints.
 *
 * Not tied to the model cache: tags and "def:{id}" counters use it too.
 * Holds no request state, so it is safe as a singleton.
 */
final readonly class GenerationCounters
{
    /** Longer than the longest entry TTL, so a counter outlives its entries. */
    public const TTL = Ttl::MAX_SECONDS + 30 * 86_400;

    /**
     * @param  string  $prefix  the package key prefix ("cc"); counters live under "{prefix}:gen:{name}"
     * @param  string|null  $store  cache store name; null uses the default store
     */
    public function __construct(
        private CacheFactory $cache,
        private RedisErrorPolicy $errors,
        private string $prefix,
        private ?string $store = null,
    ) {}

    /**
     * The current value of each counter, creating the missing ones.
     *
     * @param  list<string>  $names
     * @return array<string, int>|false by name, or false when Redis could not be reached
     *                                  (handled with $mode, ADR 0007)
     */
    public function current(array $names, RedisErrorMode $mode): array|false
    {
        try {
            return $this->read($names);
        } catch (Throwable $e) {
            $this->errors->handle($e, $mode);

            return false;
        }
    }

    /**
     * Like current(), but every error propagates. For tools that need the real
     * value or nothing, such as showing a key.
     *
     * @param  list<string>  $names
     * @return array<string, int>
     */
    public function currentOrFail(array $names): array
    {
        return $this->read($names);
    }

    /**
     * The current value of each counter, without creating the missing ones:
     * null for a counter that does not exist (or holds no counter). Writes
     * nothing, and every error propagates. For tools that only look, such as
     * cache-contract:inspect --live.
     *
     * @param  list<string>  $names
     * @return array<string, int|null>
     */
    public function peek(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $entries = $this->repository()->many(array_map($this->key(...), $names));
        $values = [];

        foreach ($names as $name) {
            $values[$name] = $this->valueOf($entries[$this->key($name)] ?? null);
        }

        return $values;
    }

    /**
     * Gives each counter a new random value, invalidating every entry that
     * depends on it. A failure follows the "invalidate" policy (ADR 0007).
     *
     * @param  list<string>  $names
     * @return bool false when Redis could not be reached
     */
    public function bump(array $names): bool
    {
        try {
            foreach ($names as $name) {
                $this->repository()->put($this->key($name), Envelope::wrap($this->fresh()), self::TTL);
            }

            return true;
        } catch (Throwable $e) {
            $this->errors->handle($e, $this->errors->invalidate);

            return false;
        }
    }

    /**
     * @param  list<string>  $names
     * @return array<string, int>
     */
    private function read(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $keys = array_map($this->key(...), $names);
        $entries = $this->repository()->many($keys);
        $values = [];

        foreach ($names as $name) {
            $key = $this->key($name);
            $value = $this->valueOf($entries[$key] ?? null);

            if ($value === null) {
                $value = $this->create($key, ($entries[$key] ?? null) !== null);
            }

            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * @param  bool  $unreadable  an entry exists but is not a counter: replace it
     */
    private function create(string $key, bool $unreadable): int
    {
        $fresh = $this->fresh();

        if ($unreadable) {
            $this->repository()->put($key, Envelope::wrap($fresh), self::TTL);

            return $fresh;
        }

        if ($this->repository()->add($key, Envelope::wrap($fresh), self::TTL)) {
            return $fresh;
        }

        // Another process created it first: use its value.
        $theirs = $this->valueOf($this->repository()->get($key));

        if ($theirs !== null) {
            return $theirs;
        }

        $this->repository()->put($key, Envelope::wrap($fresh), self::TTL);

        return $fresh;
    }

    private function valueOf(mixed $entry): ?int
    {
        if (! Envelope::isHit($entry)) {
            return null;
        }

        $value = Envelope::unwrap($entry);

        return is_int($value) ? $value : null;
    }

    private function key(string $name): string
    {
        return $this->prefix.':gen:'.$name;
    }

    private function fresh(): int
    {
        return random_int(1, PHP_INT_MAX >> 1);
    }

    private function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
