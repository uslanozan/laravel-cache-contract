# Prior art: model caching and key templates

What two existing packages do in the areas the model cache owns, and what we
do differently. Input for ADR 0007–0009, `ModelCache` / `HasCache` (phases 2–3)
and key generation (ADR 0004). Owner: @ayselinaydogdu. Issue: LRVL-32.

> Read on 2026-10-13: [`genealabs/laravel-model-caching`][gl] **13.1.11** (now
> published as `mike-bronner/laravel-model-caching`) and [`iak/keys`][ik]
> **v1.0.0**. Source was read, not run, except where marked *checked*. No code
> is copied. Links point to the exact lines of those versions.

## genealabs/laravel-model-caching

Caches **every Eloquent query** of a model that uses the trait, not only reads by ID.

| Question | What it does |
| :--- | :--- |
| How is the trait set up? | `use Cachable;` pulls in three traits, one from a separate pivot-events package ([Cachable.php][gl-cachable]). It overrides `newEloquentBuilder()` so every query of the model runs through a caching builder ([ModelCaching.php][gl-builder]), and also overrides `all()`, `destroy()`, `__get` / `__set` and `__wakeup`. |
| How are keys built? | From the query state: table, columns, wheres, joins, orders, bindings, eager loads… ([CacheKey.php][gl-key-make]), then hashed with sha1 ([ModelCacheRepository.php][gl-item-key]). The authors note that `groupBy`, joins, unions and `distinct` were each missing from the key at some point, and each time **another query's rows were returned** ([CacheKey.php][gl-key-history]). Separators inside values are percent-encoded to keep keys unambiguous ([CacheKey.php][gl-key-escape]). The prefix contains the connection and database name by default ([CachePrefixing.php][gl-prefix]). |
| Where is invalidation triggered? | Listeners on `created`, `saved`, `deleted` and pivot events ([ModelCaching.php][gl-boot]), plus builder writes (`update`, `delete`, `increment`, …). Each one flushes **every cached query tagged with the model** ([Caching.php][gl-flush]). Entries have **no TTL**: only `rememberForever` / `forever` are used ([ModelCacheRepository.php][gl-forever]), and the README confirms there is no per-query TTL ([README][gl-readme-ttl]). |
| What happens in a transaction? | Nothing specific: `src/` never mentions transactions or commits. Invalidation runs at `saved`, before commit. The README says the cache "is not automatically flushed when a transaction commits; call `flushCache()` manually" ([README][gl-readme-tx]). The README lists such queries under "not cached", but no read path checks for an open transaction ([Caching.php][gl-cachable-check]), so a read inside a transaction that later rolls back can be cached, with no TTL (inferred from the code). |

Details for our own decisions:

- **Miss vs cached `null`.** Query results are stored in an envelope
  `{key, value}` ([CachedValueRetrievable.php][gl-envelope]), so a `find()` that
  found nothing is cached and read back as a hit. The stored key is also compared
  on read to detect sha1 collisions ([CachedValueRetrievable.php][gl-collision]).
- **Serialization.** Whole models and collections go through `serialize()` with
  a versioned marker (`genealabs:lmc:v1:serialized:`) and
  `allowed_classes => true` ([ModelCacheRepository.php][gl-serialize]).
  `__wakeup()` re-runs the model constructor ([Cachable.php][gl-cachable]).
- **Redis errors.** By default cache exceptions are thrown. With
  `fallback-to-database` (default `false`), connection errors are logged and
  the database is used ([Caching.php][gl-fallback], [config][gl-config]). The same
  switch covers invalidation: a failed flush is logged and skipped
  ([ModelCaching.php][gl-destroy]). Without a TTL, the stale entries then stay
  until the next successful flush of that model (inferred from the code).
- **Generation counters already exist here.** DynamoDB has no tags, so every tag
  gets a version key and invalidation writes a new UUID
  ([ModelCacheRepository.php][gl-versions]). This is option (b) of our
  invalidation spike, at the cost of one extra read per tag on each access.
- **Clear-all.** `modelCache:clear` deletes by `SCAN` on the store prefix, but
  **flushes the whole store when that prefix is empty**
  ([ModelCacheRepository.php][gl-clear-all]).
- Queries with a row lock (`lockForUpdate()`) are never cached ([Caching.php][gl-cachable-check]).
- **Laravel 11 is no longer supported.** 13.1.11 (2026-09-19) requires
  Laravel `^12.0 || ^13.0` ([composer.json][gl-composer]); the last release for
  Laravel 11 is 13.1.10 (2026-09-09). Our floor is Laravel 11 (ADR 0003), and
  the team research report still says "Laravel 11–13".

## iak/keys

Central string templates in `config/keys.php`, rendered by a static class:
`Key::cache('product-data', productId: 123)`.

- **What it validates** ([Key.php][ik-get]): an unknown key, missing
  parameters and extra parameters each throw `InvalidArgumentException`.
  Parameters can be named, an array, or positional, where only the count is
  checked ([Key.php][ik-modes], [Key.php][ik-positional]).
- **What it does not validate:**
  - **Types.** Values are inserted as they are: `null` and `false` become an
    empty string, `true` becomes `1`. `user:{id}:{flag}:{t}` renders as
    `user:::1` (*checked* with PHP in our container).
  - **Separators.** With `k:{a}:{b}`, `a="x:y", b="z"` and `a="x", b="y:z"`
    produce the same key `k:x:y:z` (*checked*).
  - **Uniqueness.** Each key has its own free-form template, so there is no
    project-wide format, and two IDs can render the same key unnoticed.
  - **Templates themselves.** A template is parsed only when it is used, so a
    broken one is found at runtime.
- Keys only: no TTL, description, tags or invalidation.

## What we do differently

| Topic | Prior art | Us | Status |
| :--- | :--- | :--- | :--- |
| Key source | Derived from the query (genealabs); free template per key (iak) | Generated from definition ID, version and typed params with one `key_format`. By-id model keys are convention keys with a small fixed input. We do not cache arbitrary queries, so the "key misses part of the query" bug class does not apply. | Decided: ADR 0002, 0004, 0006 |
| Parameters | Missing / extra checked; no types, no escaping (iak) | Keep the missing / extra checks; add types; encode separators; hash complex values. The key factory in LRVL-6 already does this, and the cases above make good tests. | Decided: ADR 0004 |
| Readable keys | sha1 of the whole key (genealabs) | Readable; only complex parameters are hashed. | Decided: ADR 0004 |
| TTL | None; stale until the next flush (genealabs) | Required on every definition, default for convention keys, so a missed invalidation has a time limit. | Decided: ADR 0002 |
| When to invalidate | At `saved`, inside the transaction (genealabs) | After commit; nothing on rollback. Tested against real Redis. | Decided: ADR 0006; built in phase 3 |
| What to invalidate | Every query of the model on any write (genealabs) | Proposal: `forget` the single key for by-id entries; tags or counters only for lists and `invalidated_by`. | Open: ADR 0009 |
| Miss vs `null` | Envelope `{key, value}` (genealabs) | Candidate so that `null`, `false`, `0` and `[]` round-trip. | Open: ADR 0007 |
| Redis errors | One switch for reads and invalidation; a failed flush is skipped (genealabs) | Separate policies for read, write and invalidation; a failed invalidation is never silent. | Open: ADR 0007 |
| Serialization | Whole object, `allowed_classes => true` (genealabs) | Spike: whole model vs attributes. Either way, restrict `allowed_classes` and keep a versioned payload marker. | Open: ADR 0008 |
| Clearing everything | Whole-store `flush()` when the prefix is empty (genealabs) | Never `Cache::flush()`; only our own tags or prefix. | Decided: AGENTS.md |

Open questions this raises for `ModelCache`:

- Should it skip cache writes while a database transaction is open, so
  uncommitted rows are never cached? genealabs does not check.
- Should convention keys include the connection name, as the genealabs prefix
  does, for apps that use the same model class on two connections?

[gl]: https://github.com/GeneaLabs/laravel-model-caching/tree/13.1.11
[ik]: https://github.com/iak/keys/tree/v1.0.0
[gl-cachable]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Traits/Cachable.php#L5-L31
[gl-builder]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Traits/ModelCaching.php#L24-L40
[gl-boot]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Traits/ModelCaching.php#L126-L160
[gl-destroy]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Traits/ModelCaching.php#L162-L176
[gl-key-make]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/CacheKey.php#L107-L134
[gl-key-history]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/CacheKey.php#L45-L54
[gl-key-escape]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/CacheKey.php#L23-L43
[gl-prefix]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Traits/CachePrefixing.php#L30-L50
[gl-flush]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Traits/Caching.php#L151-L177
[gl-cachable-check]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Traits/Caching.php#L479-L535
[gl-fallback]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Traits/Caching.php#L544-L585
[gl-config]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/config/laravel-model-caching.php#L12
[gl-item-key]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Cache/ModelCacheRepository.php#L309-L318
[gl-forever]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Cache/ModelCacheRepository.php#L78-L112
[gl-serialize]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Cache/ModelCacheRepository.php#L60-L112
[gl-versions]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Cache/ModelCacheRepository.php#L119-L130
[gl-clear-all]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Cache/ModelCacheRepository.php#L178-L198
[gl-envelope]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Traits/CachedValueRetrievable.php#L63-L93
[gl-collision]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/src/Traits/CachedValueRetrievable.php#L40-L61
[gl-readme-tx]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/README.md?plain=1#L66
[gl-readme-ttl]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/README.md?plain=1#L443-L447
[gl-composer]: https://github.com/GeneaLabs/laravel-model-caching/blob/13.1.11/composer.json#L15-L24
[ik-modes]: https://github.com/iak/keys/blob/v1.0.0/src/Key.php#L18-L36
[ik-get]: https://github.com/iak/keys/blob/v1.0.0/src/Key.php#L47-L81
[ik-positional]: https://github.com/iak/keys/blob/v1.0.0/src/Key.php#L92-L119
