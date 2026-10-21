# 0009. Group invalidation with generation counters, not cache tags

- **Status:** accepted
- **Date:** 2026-10-15

## Context

Some changes must clear many entries at once: every cached `Member` after
`Member::flushCache()`, a definition's entries when a model in its
`invalidated_by` changes, or every entry that carries a declared tag (ADR 0002).
The roadmap asks for this strategy to be chosen with a spike between:

- **(a) Laravel cache tags:** each write is recorded under its tags; a flush
  deletes every recorded key.
- **(b) Generation counters:** a number kept in Redis is part of the key; a
  flush increments it, so new reads and writes use new keys and the old
  entries expire through their TTL.

The spike (LRVL-35) ran on PHP 8.3, Laravel 13.36, phpredis and Redis 7.4,
with 10,000 entries for the flush and expiry rows. Times are single-machine
measurements; command counts and behaviour are pinned by
[`TagsVersusGenerationCountersTest`](../../tests/Integration/TagsVersusGenerationCountersTest.php),
which CI runs on Laravel 11, 12 and 13.

| | (a) Tags | (b) Counter |
| :--- | :--- | :--- |
| Read one entry | 1 command | 2 (counter, then entry) |
| Read 100 entries (`findMany`) | **100 `GET`s**: tagged `many()` reads key by key, never `MGET` | 2 (counter, one `MGET`) |
| Write 100 entries | **100 `SETEX` + 100 `ZADD`** | one `MULTI` … `EXEC` with 100 `SETEX` |
| Invalidate 10,000 entries | ~65 ms; 10 `ZSCAN` + 10,000 `DEL` + 10,000 `ZREM`, growing with the entries | **one write to the counter, ~0.3 ms**, whatever the number of entries |
| Left behind | expired entries stay in the tag's sorted set until `cache:prune-stale-tags` runs: 1.46 MB for 10,000 | old entries expire through their TTL; only the counter stays |
| `flexible` | works; a flush clears it | works; a bump moves it to a new key |
| Slow loader writes after an invalidation | **the stale value is kept and served** | it writes under the old generation, which is never read again |

Laravel 11.57 flushes tags differently (`DEL` in chunks of 1,000 and then the
whole set, instead of a Lua script that also `ZREM`s), but reads and writes
tagged entries the same way.

The table also shows the counter's trap: if the counter is lost (evicted under
memory pressure, or deleted) and restarts at a value it had before, old entries
become readable again. The tests pin this as well.

## Options

### A. Laravel cache tags

- Pros: built into Laravel (principle 2); a flush frees memory at once.
- Cons: a stale write after a flush is served until the TTL ends, which
  principle 1 counts as wrong data, so phase 3 would need extra protection.
  Tags are an index of every written key, kept with an extra write per entry
  and its own cleanup job, which ADR 0005 rules out. Bulk reads and writes
  lose `MGET` (ADR 0006 bulk loading). A flush costs one command per entry.

### B. Generation counters

- Pros: invalidation is one command, whatever the size. Stale writes after a
  group invalidation are never read. Bulk reads stay at two commands. Nothing
  to prune. Built from Laravel's own cache calls (`many`, `add`, `put`).
- Cons: every read of a tagged entry first reads the counter. Old entries use
  memory until their TTL ends. The counter must never restart at an old value.

## Decision

**Option B.** The package does not use Laravel cache tags.

- **Which counters an entry depends on:**
  - every tag it carries: the centrally declared tags of ADR 0002 and the
    package's reserved `model:*` tags (model entries carry their model's);
  - for a definition with `invalidated_by`, an implicit counter of its own
    (for example `def:company.summary`). A change to a listed model bumps the
    counters of every definition that lists it.

  A definition with no tags and no `invalidated_by` (for example
  `external.exchange_rates`) depends on no counter and pays no extra command.
  The definition schema does not change: only the mechanism behind `tags` and
  `invalidated_by` is new.
- **A counter value is a random 62-bit integer, never a sequence.**
  - It is created with `add($key, $random, $ttl)`. With a TTL, Laravel's
    `add()` runs one atomic Lua script (`EVAL`), so concurrent creators agree;
    without a TTL it falls back to `get()` then `put()`, which is not atomic.
  - **A bump writes a new random value** (`put`), not `INCRBY`. `INCRBY` on a
    missing counter starts from 0 and would give `1`, a value the counter may
    have had before. Only "different from every earlier value" matters, not
    the order; with 62 bits a repeat is not a practical concern, and
    concurrent bumps are harmless.
  - Counters have a long TTL (the exact value is fixed with the
    implementation). An expired or evicted counter is re-created at a new
    random value: its entries miss once and are reloaded; old data never
    comes back.
- **The key carries the generations, appended by the key factory.** This
  extends ADR 0004: the generation segment is **not** a `key_format`
  placeholder, because a custom format that left it out would make every
  invalidation silently do nothing. The key factory always appends it, the
  way it appends context (`@tenant_id=7`), for example
  `cc:user:profile:v2:user_id=42:~g8123`. The segment is `~g` and the
  counter values ordered by counter name, joined by `.` (`~g8123`,
  `~g9907.8123`): one shape for any number of counters and no hash, so a new
  value can never give an old key. The names are not written; the key
  factory checks that the given generations are exactly the definition's
  counters (ADR 0004, "generation segment"; LRVL-58).
- **The `KeyFactory` stays pure** and never talks to Redis: the caller reads
  the counters and passes the generations in, like context, so
  `cache-contract:inspect` can still build a key without a Redis connection.
- An access reads all the counters it needs in one `many()` call, then the
  entry; a bulk access reads the counters once, then all entries in one
  `MGET`. A loader writes under the generations read **before** it ran.
- A single entry (one model by ID) is still invalidated with `forget()` on its
  key; per-record counters are not created (no per-record tags, roadmap
  phase 3).

## Consequences

- Each read of an entry with counters costs one extra command. Caching
  counters for the length of a request could save it, but must see the
  request's own invalidations; it is not part of v1.
- After an invalidation the old entries stay in Redis until their TTL ends.
  Memory is bounded by the required TTL (ADR 0002), not freed at once.
- `cache:prune-stale-tags` is not needed for package entries; the docs do not
  have to schedule it.
- Stale writes after a group invalidation are handled by design. A stale
  write after a single-entry `forget()` is not: phase 3 still needs
  protection for that case (LRVL-41).
- If a counter expires or is evicted, its entries miss once and are reloaded.
  This is safe under any eviction policy, but costs a burst of database
  queries; the long counter TTL keeps it rare.
- On Redis Cluster, counters and entries may live in different hash slots,
  so a multi-key read across them needs care. This is decided together with
  cluster support (D13).
