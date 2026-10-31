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

It affects every single-entry forget of the model cache:
`ModelCache::forget()`, `forgetCache()` and the clearing after a model
change. `flushCache()` does not have it, since it bumps the model's counter.
The window is short, but on rows that change often it is real. Laravel's own
cache has the same "delete, then the old value is written back" race.

**Stampede.** When a popular entry expires, every request that misses at that
moment runs the loader at once. For an expensive loader (a report, a call to
another service) that is a burst of identical work exactly when the cache is
cold. Laravel's atomic locks can serialize it: on Redis, `lock()` is
`SET NX EX` and `release()` a Lua script that deletes the lock only for its
owner; `block($seconds)` retries every 250 ms and throws
`LockTimeoutException` when time runs out.

Facts the decision builds on (Laravel 11.44 to 13, phpredis):

- `Repository::add()` on Redis is one Lua script, `EXISTS` then `SETEX`: it
  writes only when the key is absent, atomically. It fires no `KeyWritten`
  event.
- A read treats anything that is not an ADR 0007 envelope as a miss.

## Options

### Stale writes

**A. Tombstone, and loader writes with `add()`.** A forget writes a short-lived
marker instead of deleting the key. Reads treat it as a miss (it is not an
envelope), and the model cache writes what it loaded with `add()`, which
fails while the marker exists. In the race above, A's write in step 3 fails.

- Pros: closes the race for any loader faster than the tombstone's lifetime;
  built from Laravel's own `add()`, no new Redis code; no queue, no timer.
- Cons: while a tombstone lives, fresh data cannot be cached either, so those
  reads go to the database. `findMany()` writes its misses with one `add()`
  per key instead of one `MULTI`. A loader slower than the tombstone's
  lifetime still writes stale data.

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

**Stale writes: option A, for the model cache.**

- Every single-entry forget of the model cache (`ModelCache::forget()`,
  `forgetCache()` and the clearing after a change, both the normal and the
  `withTrashed()` entry) writes a tombstone instead of deleting the key.
- The tombstone lives for `models.tombstone_ttl`, **10 seconds** by default,
  configurable. It is a marker, not an envelope, so every read treats it as
  a miss.
- `find()` and `findMany()` write what they loaded with `add()`, one key at
  a time, so a tombstone, or an entry written meanwhile, is never
  overwritten. No Redis-specific pipeline code: correctness first; the cost
  is measured, and a pipeline considered, only if it shows up.
- Writing the tombstone is the invalidation: a failure follows the
  `invalidate` policy (ADR 0007). The `add()` writes follow the `write`
  policy.
- `flushCache()` keeps bumping the counter and writes no tombstone.

**Stampede: option B, for `CacheContract::remember()`.**

- A definition opts in with `'lock' => true`, a new optional field (ADR 0002).
  The default is off.
- On a miss, `remember()` takes a lock named after the entry's key (so a new
  generation gets a new lock), for `lock.seconds` (10 by default), waiting up
  to `lock.wait` seconds (5 by default) for it. With the lock, it reads the
  entry again: another request may have filled it while this one waited.
  Only then does it run the loader, write with `put()` and release the lock.
- **Lock timeout:** when the wait ends without the lock, `remember()` does
  not fail. It runs the loader, returns the value and writes it with
  `add()`, so it never overwrites what the lock holder wrote.
- A loader slower than `lock.seconds` lets the lock expire, and a second
  request may load too: duplicate work, never wrong data. A lock is released
  only by its owner, so the first request cannot remove the second one's.
- A loader that throws releases the lock, and its exception propagates
  (ADR 0007).
- A Redis error while taking the lock follows the `read` policy: with
  `report`, the loader runs and nothing is written, as for a failed read.
- A store that cannot lock (not a `LockProvider`) runs `remember()` without
  a lock.
- `get()`, `put()`, `refresh()` and the model cache take no lock.

**Not in this ADR:** `flexible` (stale-while-revalidate). It needs a new
definition field (ADR 0002), and Laravel's `Cache::flexible()` refreshes in a
deferred callback outside our Redis error policy, so it gets its own
decision.

## Consequences

- **The race from #43 is closed** for loaders faster than
  `models.tombstone_ttl`. A test against real Redis reproduces it with the
  same `DB::listen()` interleaving and checks the stale value is not written.
- **For a few seconds after a change, reads of that model go to the
  database:** while the tombstone lives, nothing can be cached for that key.
  A row that changes every few seconds is effectively not cached; that is
  the price of not serving stale data. The guide says so, next to the
  setting.
- **`findMany()` writes cost more on a cold cache:** 100 misses are 100
  `EVAL` commands instead of one `MULTI`. Reads are unchanged (counter plus
  one `MGET`), and warm reads write nothing. The model cache guide's command
  counts are updated with the implementation.
- **`add()` fires no `KeyWritten` event**, so a dev-time listener on cache
  events (LRVL-42) sees no model cache writes.
- **A locked definition costs three extra commands per miss** (acquire,
  re-read, release); waiting requests retry the lock every 250 ms. Hits cost
  nothing extra.
- **Locks use Laravel's lock connection** (`lock_connection` of the Redis
  store), as any Laravel lock does.
- New settings: `models.tombstone_ttl`, `lock.seconds`, `lock.wait`, and the
  definition field `lock`. Each is validated, documented in the guide and
  added to `config/cache-contract.php` with its implementation.
- Tests against real Redis: the stale write race, lock contention (one
  loader runs), lock timeout (the waiter loads and does not overwrite), and
  Redis down while locking.
- **Not covered:** the same race on `CacheContract::forget()` followed by
  `remember()`. Definitions are mostly invalidated through their counters
  (tags, `invalidated_by`), which have no such race. Revisit if a single
  forget of a definition shows the problem.
- Revisit the tombstone lifetime if reads after changes put measurable load
  on the database, and the per-key `add()` if cold `findMany()` calls show
  up in profiles.
