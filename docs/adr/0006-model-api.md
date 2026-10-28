# 0006. Model API: a service does the work, a trait is the shortcut

- **Status:** accepted
- **Date:** 2026-10-09

## Context

The package must cache Eloquent models (single and bulk) without a cache class
per model. Two calling styles were proposed:

- **Trait on the model:** `User::cached(5)`, `User::cachedMany([1, 2, 3])`.
  Natural to read; the trait can also listen to model events to invalidate.
- **Service:** `ModelCache::for(User::class)->find(5)`. Needs nothing on the
  model; works when the class is only known at runtime
  (`ModelCache::for($class)`); easy to mock in tests.

## Decision

Both, with one implementation:

- **`ModelCache` service** holds all the logic: key generation, single and
  bulk reads, filling misses with one query, invalidation.
- **`HasCache` trait** is a thin layer that delegates to the service and hooks
  model events:

```php
class User extends Model
{
    use HasCache;
}

User::cached(5);                // = ModelCache::for(User::class)->find(5)
User::cachedMany([1, 2, 3]);    // = ModelCache::for(User::class)->findMany([1, 2, 3])
$user->forgetCache();           // = ModelCache::for(User::class)->forget($user->id)
User::flushCache();             // = ModelCache::for(User::class)->flush()
```

- By-id caching of a model is a **convention key**: it needs no definition in
  the config; template, `model:*` tag and default TTL are derived. Custom data
  about a model (profile, lists, statistics) is a normal definition with
  `subject` pointing at the model (ADR 0002).
- Automatic invalidation on save/delete only happens for models using the
  trait, and runs **after commit**.

Class and method names here are working names; final names are fixed with
the Phase 2 implementation.

## Consequences

- One code path to test; the trait cannot drift from the service.
- Developers pick the style that reads best; most will use the trait.
- Models without the trait can still be read through the service, but get no
  automatic invalidation; lint warns when a definition's `subject` model does
  not use the trait.
- Mass updates (`User::query()->update(...)`) do not fire model events;
  documentation must point to `flushCache()` for those.

## Update 2026-10-25: final names and the cache context (LRVL-38)

The working names above are now final: `ModelCache::for($class)` with
`find()`, `findMany()`, `forget()` and `flush()`, and the `HasCache` trait with
`cached()`, `cachedMany()`, `modelCache()`, `forgetCache()` and
`flushCache()`. `flushCache()` gives the model's generation counter
`model:{connection}:{database}:{table}` a new value (ADR 0009).

**Cache context.** A model whose global scopes need a context (any scope
other than `SoftDeletes` and its `safe_scopes`) caches its rows under that
context, so a change can only clear the right entries if the model gives its
context. Such a model with `HasCache` implements `ProvidesCacheContext`:

```php
public function cacheContext(): array
{
    return ['tenant_id' => $this->tenant_id];
}
```

- It returns the same values reads pass to `withContext()`.
- On save, delete and restore, entries are cleared after commit for the
  context and key of the current values and of the values before the change,
  so a row moved to another tenant leaves no stale entry.
- A missing `ProvidesCacheContext` fails on the first read with a context,
  where developers see it. A save never fails because of it: the problem is
  reported, and nothing is cleared, since every read of such a model throws
  before it caches anything.
- More generally, a save never fails because of the cache setup: the
  package's own errors on this path (`CacheContractException`) are reported,
  before and after the commit, so the commit and the transaction's other
  after-commit callbacks still run. An exception from the model's own
  `cacheContext()` is reported as well, and the model's counter is bumped
  after the commit, since the context is unknown. Unlike a loader (ADR 0007),
  clearing after a save is side work the caller did not ask for.
  `forgetCache()`, called directly, still throws.
- The after-commit callback goes to the model's own connection: with no
  transaction open there, entries are cleared at once, because
  `afterCommit()` alone would attach it to the last transaction on any
  connection.

It is an interface rather than a plain method, so the package can tell
whether a model provides it and static analysis checks the signature.
