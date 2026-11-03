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
race between `forget()` or `pull()` and a `remember()` that loaded before it.
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
  when the key is absent, atomically, and fires no `KeyWritten` event. It
  packs the value with `RedisStore::pack()`, which applies the connection's
  serializer and compression options. The Memcached, database and file stores
  have a native `add()` too. The array store does not: `Repository::add()`
  then reads, and `put()`s on `null`, which is not atomic and fires
  `KeyWritten`.
- `RedisStore::lock($name)` uses the key `{store prefix}{name}`, the same
  namespace as the entries, on `lock_connection`, which falls back to the
  cache connection when it is not set. `acquire()` is `SET NX EX`;
  `release()` is a Lua script that deletes the lock only for its owner;
  `block($seconds)` retries every 250 ms and throws `LockTimeoutException`.
- `CacheContract::pull()` is one `GETDEL` on Redis (#51).
- `RedisStore::putMany()` sends `MULTI`, one `SETEX` per key, `EXEC`.
  phpredis waits for each `QUEUED` reply, so it is one round trip per key.
  Writing 1,000 entries took 66 ms that way and 75 ms as 1,000 `add()`
  calls (local Docker, 0.06 ms per round trip); a pipeline took 1.5 ms
  (`SETEX`) and 5.5 ms (`add()`).
- A tombstone-sized entry (a short string) uses 104 bytes for a 21-character
  key (`MEMORY USAGE`); real keys are longer.
- A read treats anything that is not an ADR 0007 envelope as a miss.
- Eloquent keeps `wasRecentlyCreated` true for the lifetime of the model
  instance, and an insert does not fill `getChanges()`: the insert's own
  `saved` event is `wasRecentlyCreated && ! wasChanged()`.

## Options

### Stale writes

**A. Tombstone, and loader writes with `add()`.** A forget writes a short-lived
marker instead of deleting the key. Reads treat it as a miss (it is not an
envelope), and a value a loader produced is written with `add()`, which fails
while the marker exists. In the race above, A's write in step 3 fails.

- Pros: closes the race for any loader faster than the tombstone's lifetime;
  built from Laravel's own `add()`, no Redis code of our own.
- Cons: while a tombstone lives, fresh data cannot be cached either, so those
  reads go to the database. It defeats the stampede lock right after a
  forget, when a hot entry goes cold: the lock holder loads, its `add()`
  fails on the tombstone, the next waiter finds a miss and loads again, one
  at a time, until the rest time out together. A loader slower than the
  tombstone's lifetime still writes stale data.

**B. Tombstone with a token, and loader writes as compare-and-set.** As A, but
every forget writes a tombstone with a new random token, and a loader writes
only if the key still holds what its read found: nothing, or the same
tombstone. A loader that read tombstone T started after that forget, so its
value is fresh, and it may replace T; a second forget writes another token,
which no earlier loader can replace.

- Pros: closes the same race as A, and a loader that started after the forget
  caches its value at once, so the lock keeps working right after a forget.
- Cons: one small Lua script of our own (`GET`, compare, `SET EX`), which must
  pack the tombstone exactly as `RedisStore` does. A loader slower than the
  tombstone's lifetime still writes stale data. Each tombstone is a key in
  Redis for its lifetime.

**C. Delete twice:** forget now, and again after a short delay.

- Pros: writes stay `put` / `putMany`.
- Cons: needs a queue or a deferred callback that can be lost; the second
  delete races just like the first, only later.

**D. A counter per record**, as ADR 0009 does for groups.

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

**Stale writes: option B, for every single-entry removal**, in the model cache
and in `CacheContract`, in the shared remove and write paths.

- **Which removals write a tombstone:** `CacheContract::forget()` and
  `pull()`, `ModelCache::forget()`, `forgetCache()`, and the clearing after a
  model is updated, deleted or restored (the normal and the `withTrashed()`
  entry). A model that was **created** is forgotten with a plain delete, as
  today: nothing can have cached it except a "no such row", and a tombstone
  per created row would cost a key per insert. "Created" is the insert's own
  `saved` event, `wasRecentlyCreated && ! wasChanged()`, so a later update of
  the same instance writes a tombstone. A save that changed nothing clears
  nothing, as for `invalidated_by`.
- **A gap for created rows, accepted:** a read that runs its query before the
  insert commits finds no row, and if its "no such row" lands in the cache
  after the plain delete, it stays until its TTL ends. A write that lands
  before the delete is removed by it. Auto-increment IDs make this rare,
  since nobody asks for an ID before the insert; with IDs the application
  generates (UUIDs, ULIDs), a job dispatched early or a polling client can.
  The model cache behaves like this today, so it is a gap, not a regression.
- **The tombstone** is a fixed string followed by a random token, new for
  every removal. It is not null and not an envelope, so every read treats it
  as a miss, and the `Repository::add()` fallback of stores without a native
  `add()` does not mistake it for an empty key. It lives for
  `tombstone_ttl`, **10 seconds** by default, configurable. A loader that
  started after the removal replaces it at once, so a longer lifetime would
  only cost memory, and in a mass change the number of tombstones alive at
  once grows with it: 60 seconds keeps six times as many keys in a shared
  Redis as 10. Loaders slower than 10 seconds are rare, and an application
  that has them can raise the setting.
- **Loader writes** (a `remember()` miss, `find()`, `findMany()`) are a
  compare-and-set, one key at a time: one Lua script writes the entry only if
  the key still holds what the loader's read found.
  - The read found nothing: write only if the key is still empty, as
    `add()` does. A tombstone written meanwhile blocks the write.
  - The read found tombstone T: write only if the key still holds T. The
    loader started after that removal, so its value is fresh and is cached at
    once. A later removal writes a new token, so a loader that read T cannot
    overwrite it.
  - The read found a value that is neither an envelope nor a tombstone
    (written by other code, or an older format): write with `put()`, so such
    a key is repaired, as today, instead of blocking every write until it
    expires. A removal between that read and the `put()` is overwritten. It
    needs a foreign value at the key, so it is rare, and it is accepted: the
    foreign value's bytes need not come back the same after decoding, and a
    compare that fails would keep the key broken.
  - The script compares the tombstone as `RedisStore` stores it, packed with
    the connection's serializer and compression options.
- **Explicit writes:** `put()` and `refresh()` still use `put()`: the caller
  asked for that value, after any removal. `add()` caches when nothing or a
  tombstone is cached; a foreign value counts as cached. It reads, then
  writes with the same compare-and-set, so of two concurrent `add()` calls
  one wins.
- **`pull()`** replaces the value with a new tombstone instead of deleting it:
  on Redis one Lua script returns the value and writes the tombstone in its
  place, in place of `GETDEL`. A loader that read before the pull cannot
  write the consumed value back, and a tombstone already there is replaced,
  never removed. An empty key stays empty.
- **Return value:** `forget()`, `ModelCache::forget()` and `forgetCache()`
  return `true` when the tombstone was written and `false` only when Redis
  could not be reached. Writing a tombstone does not tell whether an entry
  was there, and reading it back would cost a round trip. The docblocks and
  the guide change with the implementation.
- **Error policy (ADR 0007):** writing a tombstone is the invalidation and
  follows the `invalidate` policy. The compare-and-set writes and `add()`
  follow `write`. `pull()` follows `read`, as it does today.
- **The guarantee is for Redis**, where the scripts are atomic; the race tests
  run against real Redis. On other stores loader writes and `add()` fall back
  to `add()`, as in option A: a tombstone blocks writes until it expires. On
  the array store that fallback is not atomic. `pull()` keeps its read and
  delete there, and writes no tombstone: only the delete tells which of two
  concurrent pulls removed the entry, so only that one gets the value.
- No pipeline in this change: the measurements above show that one script per
  key costs about what `putMany()` costs today. Pipelining both is a separate
  optimization.

**Stampede: option B, for `CacheContract::remember()`.**

- A definition opts in with `'lock' => true`, a new optional field (ADR 0002),
  off by default. `'lock' => ['seconds' => 30, 'wait' => 20]` overrides the
  defaults for one definition.
- **The lock is named `{key}|lock`**, never the entry's key: with no separate
  `lock_connection`, a lock under the entry's key would overwrite the entry.
  `|` never appears in a generated key: parameter values are URL-encoded,
  section, definition and parameter names are snake_case, the key format
  allows only `[a-z0-9:]` around its placeholders, and the `prefix` setting
  rejects it. A new generation gives a new key, and so a new lock.
- **Defaults:** `lock.seconds` 10 (how long a lock lives) and `lock.wait` 10
  (how long a request waits for it). `seconds` should cover the loader's
  slowest run, and `wait` its usual run: a waiter that gives up while the
  holder is still loading loads too.
- On a miss, `remember()` takes the lock, waiting up to `wait`. With the
  lock, it reads the entry again (another request may have filled it), and
  only on a miss runs the loader, writes, and releases the lock.
- **Right after a removal**, the holder reads the tombstone, loads, and its
  compare-and-set replaces the tombstone, so the waiters find the value: the
  lock works when a hot entry has just gone cold.
- **Lock timeout:** when the wait ends without the lock, `remember()` does
  not fail. It reads the entry once more, and on a miss runs the loader,
  returns the value and writes it with the compare-and-set against that
  read, so it never overwrites what the holder wrote.
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
  `tombstone_ttl`, in the model cache and in `CacheContract`, for `forget()`
  and `pull()`. A test against real Redis reproduces it with the same
  `DB::listen()` interleaving and checks the stale value is not written.
- **Two gaps remain, both accepted:** a "no such row" read just before an
  insert can outlive the plain delete (more likely with UUIDs or ULIDs), and
  the foreign-value repair can overwrite a removal. Neither is new.
- **Fresh data is cached right after a change:** only a loader that read
  before the removal is blocked. On stores other than Redis, a tombstone
  blocks every write to its key until it expires, so reads of that entry go
  to the database for up to `tombstone_ttl`.
- **Tombstones take memory for their lifetime:** one key per updated,
  deleted or restored row (two for a soft-deletable model), about 100 bytes
  plus the key, until a loader replaces it or it expires. A mass change
  through model events, say a million updates within ten seconds, keeps up
  to that many keys alive at once. Creates and saves that change nothing
  write none.
- **Loader writes cost about the same:** one script per key is one round
  trip, as each `SETEX` in today's `putMany()` already is (75 ms for 1,000
  `add()` calls against 66 ms, measured locally). `add()` costs two commands
  instead of one. The model cache guide's command counts are updated with
  the implementation.
- **The package runs its own Lua scripts** for loader writes and `pull()`, on
  the cache store's connection. They must pack values as `RedisStore` does,
  so their tests also run with phpredis serialization and compression
  enabled. `pull()` no longer uses `GETDEL`; the Redis 6.2 floor
  (ADR 0003) stays.
- **On Redis, loader writes fire no `KeyWritten` event** (neither does
  Laravel's `add()`), so a dev-time listener on cache events (LRVL-42) does
  not see them there.
- **`forget()` returns `true` even when nothing was cached.** The package is
  not released yet, so no caller depends on the old meaning.
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
  definition field `lock`; `prefix` rejects `|`. Each is validated,
  documented in the guide and added to `config/cache-contract.php` with its
  implementation.
- Tests against real Redis: the stale write race (model cache,
  `CacheContract::forget()` and `pull()`), a second removal while a loader
  runs, `add()` after a removal, a foreign value repaired, lock contention
  (one loader runs), a locked definition right after a removal, lock timeout
  (the waiter re-reads, loads and does not overwrite), and Redis down while
  locking.
- Revisit the tombstone lifetime if slow loaders still write stale data, and
  pipelining loader writes if cold `findMany()` calls show up in profiles.
