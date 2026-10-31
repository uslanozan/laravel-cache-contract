# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Below `1.0.0`, a
breaking change bumps the minor version.

## [0.1.0] - Unreleased

The first release: central definitions, generated keys, the runtime API, the
model cache and the first commands.

### Added

- **Definitions** in `config/cache-contract.php`, grouped into sections and
  referred to by ID (`user.profile`). Required `purpose`, `type`, `ttl` and
  `description`; optional `subject`, `params`, `version`, `tags` and
  `invalidated_by`. All problems are reported at once with
  `InvalidDefinitions`, including the same subject and purpose under two
  names.
- **Typed parameters:** `int`, `string`, `bool`, `float`, `array` and
  `secret`, checked strictly (`"42"` is not an `int`).
- **Secret parameters:** a value such as an API token is written into the key
  as an HMAC keyed with `APP_KEY`, never in plain text. A definition with a
  secret parameter needs `APP_KEY`.
- **TTLs with units:** `'15 minutes'`, `'1 day'`, `'1h 30m'` next to plain
  seconds, at most 365 days. Months, years and bare numeric strings are
  rejected.
- **Generated keys** from one configurable format,
  `{prefix}:{section}:{name}:v{version}:{params}`, with sorted, encoded
  parameters and context values (`@tenant_id=7`).
- **`CacheContract` facade:** `remember`, `get`, `has`, `put`, `add`,
  `pull`, `refresh`, `forget`, `key` and `withContext`. A cached `null`,
  `false`, `0` or `''` is a hit, and values come back with their type.
  `add` writes only when nothing is cached, atomically on Redis; `pull`
  gives the value only to the call that removes it, so of two concurrent
  pulls one gets it (one `GETDEL` on Redis, which needs Redis 6.2+).
- **Redis error policy** (`redis_errors`): `report` or `throw`, per read,
  write and invalidate; the default keeps the application working when Redis
  is down.
- **Model cache:** `ModelCache::for(User::class)->find()` and `findMany()`
  without a definition. Keys include the connection and its database;
  `withContext()` and `withTrashed()`; models with global scopes need a
  context unless the scope is listed in `safe_scopes`; per-model `ttl` and
  `version` overrides.
- **`HasCache` trait:** `cached()`, `cachedMany()`, `modelCache()`,
  `forgetCache()` and `flushCache()`. Saving, deleting or restoring a model
  clears its entries after the commit, under its current and previous
  context; `flushCache()` gives the model's generation counter a new value.
  Models whose scopes need a context implement `ProvidesCacheContext`. A save
  never fails because of the cache.
- **Transactions:** while a transaction is open on the model's connection,
  the model cache reads cached entries but writes none, so a row that is
  rolled back is never cached. The transaction `RefreshDatabase` and
  `DatabaseTransactions` open around each test does not count.
- **Tags:** declared once under `tags` in the config, with a description;
  a definition with an undeclared tag fails validation. Names are
  snake_case, and `model:` and `def:` are reserved.
  `CacheContract::flushTag('users')` invalidates every entry of every
  definition with the tag in one Redis write, and throws `UnknownTag` for a
  tag that is not declared.
- **`invalidated_by`:** saving, deleting or restoring a listed model
  invalidates every entry of the definition after the commit, with the
  definition's generation counter; the model needs no `HasCache`. Keys of
  definitions with `tags` or `invalidated_by` end with their counters'
  values, and `cache-contract:inspect` lists the counters.
- **Commands:** `cache-contract:list` (with `--section`, `--subject` and
  `--json`) and `cache-contract:inspect` (with `--param` and `--context`,
  and suggestions for a mistyped ID). For a definition with generation
  counters, `inspect` shows them in the key pattern (`~g{users}`) and builds
  the full key from `--generation=name=value`, or from the current values in
  Redis with `--live`, which only reads.
- **Scanner:** `cache-contract:scan` lists every cache call in the source
  (facade, helper, injected repository, container, direct Redis) with its
  file, line, class, method and operation, and reads its key as `exact`,
  `template` (`feed:{id}`), `opaque`, `unresolved` or `none`. It has
  `--path` and `--json`, the `scanner.paths` and `scanner.exclude` settings,
  and exits non-zero when a path cannot be scanned. Calls to the methods of
  your own wrapper classes, declared in `scanner.wrappers`, are listed where
  they are made, with a summary line of their own. The table shows each
  location as one clickable `path:line`.

### Requirements

- PHP `~8.3.36 || ~8.4.27 || ^8.5.12`: the releases that fix the PHP
  zero-day ([ADR 0003](docs/adr/0003-supported-versions.md))
- Laravel `^11.44.2 || ^12.0.1 || ^13.0`; on PHP 8.5, Laravel 12.40+
- Redis with phpredis

### Decisions

The architecture decisions accepted for this release, in
[`docs/adr/`](docs/adr/):

- [0001](docs/adr/0001-record-architecture-decisions.md) Record architecture decisions
- [0002](docs/adr/0002-definition-format.md) Definition format: one sectioned PHP file with subject + purpose
- [0003](docs/adr/0003-supported-versions.md) Supported PHP, Laravel and Redis versions
- [0004](docs/adr/0004-key-format.md) Physical key format, versioning and parameter encoding
- [0005](docs/adr/0005-where-data-lives.md) Where data lives
- [0006](docs/adr/0006-model-api.md) Model API: a service does the work, a trait is the shortcut
- [0007](docs/adr/0007-cached-falsy-values-and-redis-errors.md) Cached falsy values and the Redis error policy
- [0008](docs/adr/0008-model-cache-stores-attributes.md) The model cache stores attributes, not serialized models
- [0009](docs/adr/0009-invalidation-with-generation-counters.md) Group invalidation with generation counters, not cache tags
- [0011](docs/adr/0011-scanner-scope-and-limits.md) Scanner scope and limits
