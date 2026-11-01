# 0012. Stale writes after a forget, and stampede locks

- **Status:** accepted
- **Date:** 2026-10-31

## Context

Phase 3 asks for two protections, both with tests against real Redis
(roadmap, LRVL-41):

**Stale writes after a single forget.** ADR 0009 handles a slow loader after a
*group* invalidation: it writes under the old generation, which is never read
again. A single entry is still removed with `forget()` on its key, and the
review of #43 reproduced the race (with a `DB::listen()` hook that
interleaves a read and an update):

1. A runs `Member::cached(1)`: a miss, so it loads "Ada" from the database.
2. B renames member 1 to "Ada Lovelace" and commits; `HasCache` forgets the
   entry.
3. A writes "Ada" to the cache.
4. Every `Member::cached(1)` returns "Ada" until the TTL ends.

The model cache has it on every single-entry forget: `ModelCache::forget()`,
`forgetCache()` and the clearing after a change. `CacheContract` has the same
race between `forget()` and a `remember()` that loaded before it.
`flushCache()`, `flushTag()` and `invalidated_by` do not, since they bump a
counter. Laravel's own cache has the same "delete, then the old value is
written back" race.

**Stampede.** When a popular entry expires, every request that misses at that
moment runs the loader at once. For an expensive loader (a report, a call to
another service) that is a burst of identical work exactly when the cache is
cold. Laravel's atomic locks can serialize it.

Facts the decision builds on, checked in the Laravel 11.44 to 13 source and
measured on Redis 7.4 with phpredis 6.3:

- `RedisStore::add()` is one Lua script, `EXISTS` then `SETEX`: it writes only
  when the key is absent, atomically, and fires no `KeyWritten` event. The
  Memcached, database and file stores have a native `add()` too. The array
  store does not: `Repository::add()` then reads, and `put()`s on `null`,
  which is not atomic and fires `KeyWritten`.
- `RedisStore::lock($name)` uses the key `{store prefix}{name}`, the same
  namespace as the entries, on `lock_connection`, which falls back to the
  cache connection when it is not set. `acquire()` is `SET NX EX`;
  `release()` is a Lua script that deletes the lock only for its owner;
  `block($seconds)` retries every 250 ms and throws `LockTimeoutException`.
- `RedisStore::putMany()` sends `MULTI`, one `SETEX` per key, `EXEC`.
  phpredis waits for each `QUEUED` reply, so it is one round trip per key.
  Writing 1,000 entries took 66 ms that way and 75 ms as 1,000 `add()`
  calls (local Docker, 0.06 ms per round trip); a pipeline took 1.5 ms
  (`SETEX`) and 5.5 ms (`add()`).
- A tombstone-sized entry (a short string) uses 104 bytes for a 21-character
  key (`MEMORY USAGE`); real keys are longer.
- A read treats anything that is not an ADR 0007 envelope as a miss.

## Options

### Stale writes

**A. Tombstone, and loader writes with `add()`.** A forget writes a short-lived
marker instead of deleting the key. Reads treat it as a miss (it is not an
envelope), and a value a loader produced is written with `add()`, which fails
while the marker exists. In the race above, A's write in step 3 fails.

- Pros: closes the race for any loader faster than the tombstone's lifetime;
  built from Laravel's own `add()`, no new Redis code; no queue, no timer.
- Cons: while a tombstone lives, fresh data cannot be cached either, so those
  reads go to the database. A loader slower than the tombstone's lifetime
  still writes stale data. Each tombstone is a key in Redis for its lifetime.

**B. Delete twice:** forget now, and again after a short delay.

- Pros: writes stay `put` / `putMany`.
- Cons: needs a queue or a deferred callback that can be lost; the second
  delete races just like the first, only later.

**C. A counter per record**, as ADR 0009 does for groups.

- Pros: a stale write lands under an old generation and is never read.
- Cons: ADR 0009 and the roadmap rule out per-record counters: one extra key
  and one extra read per record.

### Stampede

**A. A lock on every miss.**

- Pros: no definition can stampede.
- Cons: three extra Redis commands on every miss (acquire, re-read,
  release), for the many definitions whose loader is cheap.

**B. A lock per definition, off by default** (`'lock' => true`).

- Pros: the cost is paid only where a stampede is expensive; the developer
  who knows the loader decides.
- Cons: a definition that needs it can be left without it.

## Decision

**Stale writes: option A, for every single-entry forget**, in the model cache
and in `CacheContract`, in the shared remove and write paths.

- **Which forgets write a tombstone:** `CacheContract::forget()`,
  `ModelCache::forget()`, `forgetCache()`, and the clearing after a model
  is updated, deleted or restored (the normal and the `withTrashed()`
  entry). A model that was **created** is forgotten with a plain delete, as
  today: nothing can have cached it except a "no such row", and a tombstone
  per created row would cost a key per insert. A save that changed nothing
  clears nothing, as for `invalidated_by`.
- **The tombstone** is a fixed, non-null string, not an envelope, so every
  read treats it as a miss, and the `Repository::add()` fallback of stores
  without a native `add()` does not mistake it for an empty key. It lives
  for `tombstone_ttl`, **10 seconds** by default, configurable.
- **Loader writes** (a `remember()` miss, `find()`, `findMany()`) use
  `add()`, one key at a time, so a tombstone, or an entry written meanwhile,
  is never overwritten. When the read found a value that is neither an
  envelope nor a tombstone (written by other code, or an older format), the
  loader writes with `put()` instead, so such a key is repaired, as today,
  instead of blocking every write until it expires.
- **Explicit writes** (`put()`, `refresh()`) still use `put()`: the caller
  asked for that value, after any forget.
- Writing the tombstone is the invalidation: a failure follows the
  `invalidate` policy (ADR 0007). The `add()` writes follow the `write`
  policy.
- **The guarantee is for Redis**, where `add()` is atomic; the race tests run
  against real Redis. Other stores with a native `add()` should behave the
  same; on the array store the fallback is not atomic.
- No pipeline in this change: the measurements above show that `add()` per
  key costs about what `putMany()` costs today. Pipelining both is a separate
  optimization.

**Stampede: option B, for `CacheContract::remember()`.**

- A definition opts in with `'lock' => true`, a new optional field (ADR 0002),
  off by default. `'lock' => ['seconds' => 30, 'wait' => 20]` overrides the
  defaults for one definition.
- **The lock is named `{key}:lock`**, never the entry's key: with no separate
  `lock_connection`, a lock under the entry's key would overwrite the entry.
  The key factory never builds a key that ends with a bare `lock` segment
  (every segment is `name=value`, `@name=value`, `~g…` or `-`), so the name
  cannot collide with an entry. A new generation gives a new key, and so a
  new lock.
- **Defaults:** `lock.seconds` 10 (how long a lock lives) and `lock.wait` 10
  (how long a request waits for it). `seconds` should cover the loader's
  slowest run, and `wait` its usual run: a waiter that gives up while the
  holder is still loading loads too.
- On a miss, `remember()` takes the lock, waiting up to `wait`. With the
  lock, it reads the entry again (another request may have filled it), and
  only on a miss runs the loader, writes, and releases the lock.
- **Lock timeout:** when the wait ends without the lock, `remember()` does
  not fail. It reads the entry once more, and on a miss runs the loader,
  returns the value and writes it with `add()`, so it never overwrites what
  the holder wrote.
- A loader slower than `seconds` lets the lock expire, and a second request
  may load too: duplicate work, never wrong data. A lock is released only by
  its owner, so the first request cannot remove the second one's.
- A loader that throws releases the lock, and its exception propagates
  (ADR 0007).
- A Redis error while taking the lock follows the `read` policy: with
  `report`, the loader runs and nothing is written, as for a failed read.
- A store that cannot lock (not a `LockProvider`) runs `remember()` without a
  lock.
- `get()`, `put()`, `refresh()` and the model cache take no lock.

**Not in this ADR:** `flexible` (stale-while-revalidate). It needs a new
definition field (ADR 0002), and Laravel's `Cache::flexible()` refreshes in a
deferred callback outside our Redis error policy, so it gets its own
decision.

## Consequences

- **The race from #43 is closed** on Redis for loaders faster than
  `tombstone_ttl`, in the model cache and in `CacheContract`. A test against
  real Redis reproduces it with the same `DB::listen()` interleaving and
  checks the stale value is not written.
- **For a few seconds after a change, reads of that entry go to the
  database:** while the tombstone lives, nothing can be cached for that key.
  A row that changes every few seconds is effectively not cached; that is
  the price of not serving stale data. The guide says so, next to the
  setting.
- **Tombstones take memory for their lifetime:** one key per updated,
  deleted or restored row (two for a soft-deletable model), about 100 bytes
  plus the key. A mass change through model events, say a million updates
  within ten seconds, keeps that many keys alive at once. Creates and saves
  that change nothing write none.
- **Loader writes cost about the same:** `add()` per key is one round trip,
  as each `SETEX` in today's `putMany()` already is (75 ms against 66 ms for
  1,000 entries, measured locally). The model cache guide's command counts
  are updated with the implementation.
- **On Redis, `add()` fires no `KeyWritten` event**, so a dev-time listener
  on cache events (LRVL-42) does not see loader writes there.
- **A locked definition costs three extra commands per miss** (acquire,
  re-read, release), plus one `SET NX` per 250 ms for each waiting request,
  and one `GET` after a timeout. Hits cost nothing extra.
- **Waiting requests hold a worker:** with 50 concurrent misses, 49 PHP
  workers sleep for up to `wait` seconds. That is the trade a lock makes:
  idle workers instead of 49 duplicate loads. Size `wait` and the worker pool
  together; the guide says so.
- **Locks use Laravel's lock connection** (`lock_connection` of the Redis
  store), as any Laravel lock does.
- New settings: `tombstone_ttl`, `lock.seconds`, `lock.wait`, and the
  definition field `lock`. Each is validated, documented in the guide and
  added to `config/cache-contract.php` with its implementation.
- Tests against real Redis: the stale write race (model cache and
  `CacheContract`), a foreign value repaired, lock contention (one loader
  runs), lock timeout (the waiter re-reads, loads and does not overwrite),
  and Redis down while locking.
- Revisit the tombstone lifetime if reads after changes put measurable load
  on the database, and pipelining loader writes if cold `findMany()` calls
  show up in profiles.
