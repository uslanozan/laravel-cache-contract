# 0007. Cached falsy values and the Redis error policy

- **Status:** proposed
- **Date:** 2026-10-13

## Context

The `CacheContract` facade (LRVL-7) and the model cache need two rules before
they can be written:

1. How a cached `null`, `false`, `0` or `[]` is told apart from a miss.
2. What happens when Redis fails during a read, a write or an invalidation.

The roadmap's first principle settles the direction: *"A cache that serves
wrong data (wrong tenant, stale after invalidation, `null` read as a miss) is
worse than no cache."* ADR 0003 rules out Laravel's failover store in the core.

We measured Laravel's Redis store (phpredis, Redis 7.4) instead of relying on
its documentation. The results are pinned by
[`LaravelRedisCacheSemanticsTest`](../../tests/Integration/LaravelRedisCacheSemanticsTest.php),
which CI runs on Laravel 11, 12 and 13:

| Value written | Read back, default settings | Read back, phpredis PHP serializer |
| :--- | :--- | :--- |
| `null` | miss (`has()` is false, `remember` never caches it) | miss |
| `false` | `false` | **miss** |
| `0`, `5`, `1.5`, `1.0` | **`"0"`, `"5"`, `"1.5"`, `"1"` (strings)** | same number |
| `true`, `''`, `[]` | same value | same value |

No Laravel setting round-trips every value. The `array` store used in unit
tests keeps integers as integers, so the difference only shows against real
Redis. Wrapping the value in a one-element array round-trips every value
under both settings.

When Redis cannot be reached, every operation (`get`, `many`, `put`,
`forget`, tag flush, `lock`) throws `RedisException`, and `remember` throws
before its loader runs. When the host does not answer, each call waits for the
connection timeout; with none configured, phpredis waits for PHP's
`default_socket_timeout`, 60 seconds by default. Laravel's default Redis
configuration sets no timeout.

## Options

### Cached values

**A. Follow Laravel.** `null` is always a miss; numbers are cast back using the
definition's `type`.

- Pros: nothing new to store.
- Cons: breaks principle 1 (`null` read as a miss). "Not found" results are
  never cached, so lookups of missing records always reach the database.
  Casting does not help `false` under the PHP serializer.

**B. Envelope.** The package stores every value as `[value]` and unwraps it on
read. An absent key is the only miss.

- Pros: every value round-trips exactly, under any serializer setting.
  "Not found" can be cached for its TTL. `many()` tells a cached `null` from a
  missing key, which bulk model loading needs.
- Cons: a few bytes per entry. Code reading our keys directly sees the
  envelope. Enveloped numbers cannot be changed with Redis `INCR`.

**C. Forbid `null`.** Definitions may not produce `null`.

- Pros: simple.
- Cons: `find()` of a missing record returns `null` by nature; the model cache
  could not cache it.

### Redis errors

Each operation either **throws** or **reports**: passes the exception to
Laravel's `report()` (log, error tracker) and continues.

| Operation | If it reports | Risk |
| :--- | :--- | :--- |
| Read | Treated as a miss: the loader runs, the database answers | Load moves to the database |
| Write | The loaded value is returned, just not cached | Next read misses again |
| Invalidation | The change is already committed; the old entry stays until its TTL | **Stale data**, which principle 1 counts as wrong data |

## Decision

**Values: option B.**

- Everything the package writes (facade, model cache, bulk writes, `flexible`)
  is stored as a one-element array `[value]`, encoded and decoded in one
  internal place.
- A read is a **hit** only for a one-element array with key `0`. An absent key
  or anything else (an entry written by other code or by a future envelope
  format) is a **miss** and is overwritten on the next write.
- `null`, `false`, `0`, `''` and `[]` are ordinary values: cached for the
  definition's TTL and returned exactly as written. `remember` caches `null`;
  `has()` is true for a cached `null`.

**Errors: one setting per operation, `throw` or `report`.** Proposed config
(final key names are fixed with the facade):

```php
'redis_errors' => [
    'read' => 'report',       // miss: run the loader; skip the write that would follow
    'write' => 'report',      // return the loaded value without caching it
    'invalidate' => 'throw',  // a skipped invalidation leaves stale data
],
```

- Read and write report by default: the database stays the source of truth,
  so the answer is still correct; the cache only speeds it up. This matches
  the team research report (the app stays up when Redis is down).
- Invalidation throws by default, following principle 1. Apps that prefer
  availability can set it to `report`; the required TTL (ADR 0002) bounds how
  long stale data can live.
- The policy covers connection errors only: phpredis' `RedisException` (and
  Predis' connection exception if Predis is supported later, D13). Any other
  exception, such as an unreadable entry, always propagates.
- Tests and CI should set all three to `throw`, so failures are not hidden.

## Consequences

- The facade and `ModelCache` share one value codec; neither handles raw
  Laravel values.
- Caching "not found" means invalidation must also run when a record is
  **created**. `HasCache` invalidates on save (ADR 0006), and Eloquent fires
  `saved` for new records too, so it must not listen to `updated` only.
- Package values cannot use `Cache::increment()` / `decrement()`. If counters
  are needed, they get their own definition type and ADR.
- Changing the envelope later turns every existing entry into a miss, like a
  key format change (ADR 0004); upgrade notes must say so.
- The docs must tell users to set `timeout` and `read_timeout` on the cache's
  Redis connection; otherwise, with `report`, every cache call during an
  outage can wait up to 60 seconds before falling back. The package does not
  change the connection itself.
- There is no circuit breaker in v1: during an outage each call pays the
  connection timeout. Revisit if that proves too slow in practice.
- Revisit this ADR if a Laravel release changes any behaviour pinned by the
  characterization tests.
