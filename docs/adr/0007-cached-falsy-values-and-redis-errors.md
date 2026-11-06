# 0007. Cached falsy values and the Redis error policy

- **Status:** accepted
- **Date:** 2026-10-14

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

| Operation | If it throws | If it reports |
| :--- | :--- | :--- |
| Read | The request fails, although the database could answer | Treated as a miss: the loader runs, the database answers; load moves to the database |
| Write | The request fails, although the value was loaded | The loaded value is returned, just not cached; the next read misses again |
| Invalidation | Invalidation runs **after commit**, so the request fails **after the data was saved**: the user is told it failed, and a retry can write the data **twice**. The stale entry stays until its TTL | The user gets the correct answer; the stale entry stays until its TTL; the failure is visible in logs and the error tracker |

In both invalidation modes the stale entry lives until its TTL: throwing does
not remove it, it only adds a misleading error. Removing it as soon as Redis
is back needs a guaranteed retry, for example a transactional outbox (the
"forget" is recorded in the same database transaction and a worker retries
it), which needs a table and a worker.

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

**Errors: one setting per operation, `throw` or `report`, all `report` by
default.** Config (final key names are fixed with the facade):

```php
'redis_errors' => [
    'read' => 'report',        // miss: run the loader; skip the write that would follow
    'write' => 'report',       // return the loaded value without caching it
    'invalidate' => 'report',  // the stale entry lives until its TTL
],
```

- Read and write report: the database stays the source of truth, so the
  answer is still correct; the cache only speeds it up. This matches the team
  research report (the app stays up when Redis is down).
- Invalidation reports as well: throwing after commit tells the user a saved
  change failed, invites duplicate writes on retry, and does not remove the
  stale entry. The required TTL (ADR 0002) bounds how long it lives. Apps can
  still choose `throw`.
- The policy covers errors from Redis itself, never bugs. At first that was
  connection errors only, phpredis' `RedisException`; the updates below add
  commands Redis rejects and predis' errors. Any other exception, such as an
  unreadable entry, always propagates.
- Tests and CI should set all three to `throw`, so failures are not hidden.

## Consequences

- The facade and `ModelCache` share one value codec; neither handles raw
  Laravel values.
- Caching "not found" means invalidation must also run when a record is
  **created**. `HasCache` invalidates on save (ADR 0006), and Eloquent fires
  `saved` for new records too, so it must not listen to `updated` only.
- Package values cannot use `Cache::increment()` / `decrement()`. If counters
  are needed, they get their own definition type and ADR. [ADR 0013](0013-no-counters-in-v1.md)
  decided: no counters in v1.
- Changing the envelope later turns every existing entry into a miss, like a
  key format change (ADR 0004); upgrade notes must say so.
- The docs must tell users to set `timeout` and `read_timeout` on the cache's
  Redis connection; otherwise, with `report`, every cache call during an
  outage can wait up to 60 seconds before falling back. The package does not
  change the connection itself.
- There is no circuit breaker in v1: during an outage each call pays the
  connection timeout. Revisit if that proves too slow in practice.
- A failed invalidation leaves stale data until the TTL ends. Strict
  consistency is the application's responsibility for now: the docs will
  recommend short TTLs for critical data and a transactional outbox where it
  matters. A guaranteed retry in the package is future work (LRVL-51,
  phase 3 backlog).
- Revisit this ADR if a Laravel release changes any behaviour pinned by the
  characterization tests.

## Update 2026-11-04: commands Redis rejects in `pull()`

The policy covered connection errors only. Since #51, `pull()` on Redis reads
and removes in one command (`GETDEL`, Redis 6.2+), and phpredis answers a
command that Redis rejects with `false`, as for a missing key, keeping the
error aside. Taken for a miss, `pull()` on an older Redis would return the
default every time, never remove the entry and report nothing.

**Decision:** `pull()` checks phpredis' last error and throws it as a
`RedisException`, so a command Redis rejects follows the `read` policy like a
connection error. Any other exception still propagates.

**Still open:** the same phpredis behaviour affects other commands, which
still turn a rejection (`NOPERM` from an ACL, `OOM`, `LOADING`) into a wrong
answer without a report. Checked against Redis 7.4 with phpredis 6.3:

- The `MGET` of the generation counters (`GenerationCounters::current()` and
  `peek()`). On Laravel 11 and 12, `PhpRedisConnection::mget()` maps over
  `false` and throws a `TypeError`, which no policy handles. On Laravel 13 it
  returns nulls: every counter looks missing, `current()` gives a new value
  on every call, so every entry of the definition misses, and
  `inspect --live` reports the counters as "not in Redis yet". Closed by
  the update of 2026-11-06 on a rejected `MGET` below.
- Laravel's `RedisStore::add()`, a Lua script: a rejected script returns
  `false`, so `CacheContract::add()` answers "already cached". ADR 0012
  replaces it with the package's own compare-and-set script, which must check
  the last error too; so must every script the package sends.

## Update 2026-11-04: reports during an outage

With `report`, every failing call passed its error to `report()`. During an
outage every cache call fails, so a busy application flooded its log and
error tracker with the same `RedisException` (LRVL-52, raised in the review
of #18). Laravel's `throttle()` for reported exceptions does not help: its
`RateLimiter` keeps the count in the cache, usually the same Redis, and when
that fails Laravel reports the exception anyway.

**Decision:** the package reports the first connection error, then at most
one per `redis_errors.report_interval`, **60 seconds** by default; `0`
reports every error, as before.

- One interval for reads, writes and invalidations: an outage hits all three
  at once, and three intervals would triple the reports.
- The errors left out are counted per operation. The first error reported
  after the interval is followed by a `RedisErrorsNotReported` report with
  the counts (`read: 2000, invalidate: 41`): invalidate errors mean stale
  entries may be cached. The original `RedisException` is reported
  unchanged, so error trackers keep grouping it as before.
- The state (when the last error was reported, three counts) belongs to the
  process, not to a request: it has a fixed size and lives in a
  `RedisErrorReporter` singleton, resolved at boot so that every Octane
  request of a worker shares it. The policy itself stays immutable. Time
  comes from `Date`, so tests can move the clock. A clock that steps back
  (NTP) counts as an elapsed interval, so reports are not held back until
  it catches up.
- `throw` is not limited: every error is thrown.

**Consequences:**

- **The interval holds only in long-running processes** (Octane, queue
  workers, `schedule:work`), each with its own. Under PHP-FPM the
  application is built again for every request, so the reporter starts
  empty each time and the limit is one report per request: a page with 30
  failing calls gives 1 report instead of 30, but 1,000 requests a minute
  still give about 1,000 reports a minute. A limit across FPM processes
  needs state they share without Redis (APCu, where it is installed); that
  is a separate decision. The guide says so.
- The counts of the last interval are lost when no error follows it, for
  example when Redis comes back. Accepted, and in the guide.
- Any `RedisException` counts, also one that is not about the connection
  (`WRONGTYPE`, or a command Redis rejects in `pull()`): after an outage
  report, such an error is counted, not reported, until the interval ends.
- **Still no circuit breaker.** This changes only how errors are reported;
  every call still tries Redis and pays the connection timeout. Skipping
  Redis for a while after errors changes how the package behaves, so it
  stays a separate decision.

## Update 2026-11-06: predis

The package supports predis `^2.3` next to phpredis (ADR 0003, update
2026-11-06). Checked against Redis 7.4 with predis 2.4:

| Failure | phpredis | predis |
| :--- | :--- | :--- |
| Redis cannot be reached, a timeout | `RedisException` | `ConnectionException` (a `CommunicationException`) |
| A plain command Redis rejects (`NOPERM`, `OOM`) | `RedisException` | `ServerException` |
| A script that fails inside Redis (`WRONGTYPE`, a command the ACL forbids) | `false`; the package throws the last error as a `RedisException` | `ServerException` |
| A rejected `MGET` | `false`, which Laravel turns into a `TypeError` or nulls; the package throws the last error as a `RedisException` (update below) | `ServerException` |
| A command the client does not know | `Error` | `ClientException` |

**Decision:** on predis, a `CommunicationException` (the connection, its
timeouts and the protocol) and a `ServerException` (a command Redis rejects)
follow the policy, as a `RedisException` does on phpredis, so both clients
give the same result. A `ClientException` is a bug in the calling code and
propagates. The predis classes are checked with `instanceof`, so the package
still needs neither client installed.

On predis, the `MGET` of the generation counters is covered by this: a
rejection is thrown and follows the policy. On phpredis, see the next update.

## Update 2026-11-06: a rejected `MGET` on phpredis

The last item of "Still open" above: phpredis answers an `MGET` that Redis
rejects (`NOPERM` from an ACL, `OOM`, `LOADING` on a replica) with `false`
and keeps the error aside. Laravel's `PhpRedisConnection::mget()` then maps
over that `false` and throws a `TypeError` (11.44.2 and 12.0.1), or answers
that every key is missing (later releases). Neither follows the policy: the
first fails every call to a definition with `tags` or `invalidated_by`, the
second makes every generation counter look missing, so `current()` creates a
new value on every call and every entry misses, unreported. `findMany()` reads
its entries with an `MGET` too and had the same problem.

**Decision:** every `MGET` the package sends goes through one helper,
`RedisReads::many()`. On phpredis it clears the last error, calls
`Repository::many()`, and throws the last error as a `RedisException` when
there is one, also in place of Laravel's `TypeError`. A `TypeError` with no
error aside is a bug and propagates. So the generation counters and
`findMany()` follow the `read` policy: with `report` the counters are not
created or overwritten, `findMany()` loads from the database and caches
nothing, and `inspect --live` fails with one line.

What stays open on phpredis: a plain command that hits a key of the wrong
type (`WRONGTYPE`, a key other code wrote as a hash, for example) answers
`false` instead of throwing, so nothing is reported. The package's own
scripts check the last error, so this concerns only plain reads of such a
key.
