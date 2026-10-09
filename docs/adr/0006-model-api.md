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
