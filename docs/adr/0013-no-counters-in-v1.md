# 0013. No counters in v1

- **Status:** accepted
- **Date:** 2026-11-04

## Context

Laravel's cache has `increment()` and `decrement()`. The public scan
([cache-usage-in-the-wild.md](../research/cache-usage-in-the-wild.md)) found them in one of the scanned
projects, Pixelfed: 6 `increment` and 3 `decrement` calls, next to a
hand-written rate limiter in an API controller. LRVL-63 added `pull()` and
`add()` to the facade (#51) and left counters to this ADR.

Counters do not fit the package as it is:

- **The envelope.** Every value is stored as `[value]` (ADR 0007), so a cached
  `null` is a hit. Redis `INCR` works on a plain integer string only, so a
  counter would have to be stored without the envelope: an exception to
  ADR 0007.
- **The TTL.** Every definition has a TTL (ADR 0002). `INCR` on a missing key
  creates it without one, so the TTL needs a second command, and with it a
  rule for when it is set: on the first increment only (a fixed window), or
  on every one (a sliding window that never ends for a busy key).
- **Redis errors.** With `report`, the default, a failed call carries on
  without the cache (ADR 0007). That is safe for a copy that can be loaded
  again. A counter is the data itself: a lost view count is acceptable, a
  lost failed-login count is not, and the package cannot tell them apart.
  A failed increment would be reported and skipped, and the caller would
  never know the count is off.
- **Invalidation.** `forget()`, `flushTag()` and `invalidated_by` throw entries
  away because they can be rebuilt. For a counter, the same calls reset data
  that cannot be rebuilt.

Laravel already offers better homes for counted data:

- **Rate limits and attempt counts:** `RateLimiter` (`hit()`, `attempts()`,
  `tooManyAttempts()`) keeps its own keys and windows. It stores its counts
  in the same cache store, so eviction, `cache:clear` or a Redis restart
  without persistence loses them too. The difference is the error behaviour:
  when Redis fails, `RateLimiter` throws instead of carrying on, so a
  failure is never silent. The scanner lists it as `indirect`, and lint never
  flags it (ADR 0011).
- **Counts that must not be lost:** the database (`increment()` on a model or
  a query), where the count is durable and transactional.
- **High-volume counters and data structures:** Redis directly, which the
  scanner lists as `redis`, off for lint unless turned on (ADR 0011). `INCR`
  creates a missing key without a TTL, so the caller sets the expiry, as
  ADR 0002's rule against entries that never expire asks.

An `increment()` on an Eloquent model fires `updated` but not `saved`, so
`HasCache` and `invalidated_by` do not see it today (LRVL-78). The guide
warns about it until that is fixed.

## Options

### A. A `counter` definition type

`'type' => 'counter'`, stored without the envelope, with `increment()` and
`decrement()` on the facade, a TTL set with the first increment, and its own
error rule.

- Pros: counters get a definition, a key format and a place in the catalogue,
  like every other cache value.
- Cons: an exception to ADR 0007's envelope and error policy, and to how
  ADR 0009's invalidation treats entries; a TTL rule to choose and document;
  a second write path to test against real Redis. ADR 0012 makes `forget()`
  write a tombstone, so an `INCR` after a `forget()` fails with "value is
  not an integer": a counter would also need its own removal path. Every
  user then has to decide whether losing counts is acceptable, which the
  package cannot check.

### B. No counters in v1

The facade has no `increment()` or `decrement()`. The guide points to
`RateLimiter`, the database and Redis directly.

- Pros: the envelope, the mandatory TTL and the error policy keep one meaning
  for every value; nothing to add for the rare project that needs counters.
- Cons: a project that counts in the cache keeps those calls outside the
  contract.

## Decision

**Option B.** v1 has no counters. `CacheContract` stores copies that can be
loaded again; counted data belongs to `RateLimiter`, the database or Redis
directly.

The questions on LRVL-63 are answered by that, not by a design:

- **A `counter` type:** none in v1.
- **TTL:** not needed; `RateLimiter` sets its own window.
- **Increments lost on a Redis error:** not the package's to decide; with the
  database they are not lost.
- **Reset, and ADR 0009's generation counters:** those counters are internal
  to invalidation and are not exposed to users; nothing changes there.

## Consequences

- The facade gets no `increment()` or `decrement()`.
- The guide says counters are not supported and where to keep them instead.
- Moving a project that counts in the cache means moving those calls to
  `RateLimiter`, the database or Redis, not into a definition. The scanner
  still lists `increment` and `decrement` as `cache` calls, so lint will flag
  them outside the allowed paths, as it does every cache call.
- Revisit if several projects need counters that fit no option above. The
  new type would then get its own ADR, starting from option A.
