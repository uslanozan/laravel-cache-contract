# 0009. Group invalidation with generation counters, not cache tags

- **Status:** proposed
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
| Invalidate 10,000 entries | ~65 ms; 10 `ZSCAN` + 10,000 `DEL` + 10,000 `ZREM`, growing with the entries | **one `INCRBY`, ~0.3 ms**, whatever the number of entries |
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
  to prune. Built from Laravel's own cache calls (`get`, `many`, `increment`).
- Cons: every read first reads the counter. Old entries use memory until
  their TTL ends. The counter must never restart at an old value.

## Decision

**Option B.** The package does not use Laravel cache tags.

- Every tag (the centrally declared ones of ADR 0002 and the package's
  reserved `model:*` tags) has a **generation counter** in Redis. "Flushing a
  tag" means incrementing its counter. The definition schema does not change:
  `tags` stays, only the mechanism behind it is different.
- **The key contains the generations of the entry's tags.** This extends the
  key format of ADR 0004 with a generation segment. The placeholder and its
  encoding (one tag: the number; several: a short hash of the numbers) are
  fixed with the key factory.
- An access reads the counters of all the entry's tags in one `many()` call,
  then the entry; a bulk access reads the counters once, then all entries in
  one `MGET`. A loader writes under the generations read **before** it ran.
- **Counters start at a random value, not at 1.** A counter is created with
  an atomic add-if-absent of a random 62-bit integer, so concurrent creators
  agree and a lost counter never restarts at a value it had before. Counters
  have no TTL; they are package internals, not cached values.
- A single entry (one model by ID) is still invalidated with `forget()` on its
  key; per-record counters are not created (no per-record tags, roadmap
  phase 3).

## Consequences

- Each read costs one extra command (the counters). Caching counters for the
  length of a request could save it, but must see the request's own
  invalidations; it is not part of v1.
- After an invalidation the old entries stay in Redis until their TTL ends.
  Memory is bounded by the required TTL (ADR 0002), not freed at once.
- `cache:prune-stale-tags` is not needed for package entries; the docs do not
  have to schedule it.
- Stale writes after a group invalidation are handled by design. A stale
  write after a single-entry `forget()` is not: phase 3 still needs
  protection for that case (LRVL-41).
- If counters are evicted, entries simply miss and are reloaded. Apps should
  still prefer an eviction policy that spares keys without a TTL
  (`volatile-*`); the docs say so.
- On Redis Cluster, counters and entries may live in different hash slots,
  so a multi-key read across them needs care. This is decided together with
  cluster support (D13).
