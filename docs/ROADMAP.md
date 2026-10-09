# Roadmap

> **Status:** draft v0.2, updated 2026-10-09 with the decisions from the team research documents.
> It will change. Changes go through a PR like any other file.

## 1. Goal

Build a production-grade, open-source Laravel package that manages cache usage
in large applications through a central contract:

- every cache entry type is **defined once**, with a required description,
- keys are **generated**, never hand-written, so naming stays consistent,
- developers can **find** existing definitions and are **warned about
  similar ones** before adding a duplicate,
- Eloquent models (single and bulk) are cached through **one generic API**,
  without a class per model,
- cache calls that **bypass the contract are detected** in CI.

The package builds on Laravel's cache, Redis connection, locks and events
instead of re-implementing them. The full requirements are in the mission
document; this roadmap turns them into phases.

### Definition of success

The mission is done when a team can install the package into a fresh Laravel
app with Composer, define their caches, use them for models and queries,
discover existing ones, and have CI reject contract violations, all
documented, tested against real Redis, and released on Packagist.

## 2. Principles

1. **Correctness before features.** A cache that serves wrong data (wrong
   tenant, stale after invalidation, `null` read as a miss) is worse than no
   cache. Core semantics are tested before anything is built on top.
2. **Reuse Laravel.** If Laravel already does it (stores, locks, events,
   `config:cache`), we use it and document how.
3. **Fail loudly at the edges.** Unknown definitions, missing parameters, wrong
   types and invalid definitions throw. Similarity is a warning, never a block.
4. **Decisions are written down.** Anything that would be expensive to reverse
   gets an ADR in `docs/adr/` before or with the code.
5. **Small vertical slices.** Each cycle ends with something that runs and is
   tested, not with half of every layer.
6. **Don't claim what isn't tested.** Supported PHP/Laravel/Redis versions are
   exactly those in the CI matrix.

## 3. How we work

| Topic | Agreement |
| :--- | :--- |
| Planning | Linear, **1 cycle = 1 week**. Cycle planning at the start, short demo + retro at the end. |
| Issues | Every PR links a Linear issue. Design questions become *Design proposal* issues first. |
| Branches & commits | [branching-strategy.md](branching-strategy.md), [commit-convention.md](commit-convention.md) |
| Review | 1 approval from a teammate; all checks green; threads resolved. Aim to review within one working day. |
| Definition of done | Merged to `develop`, tests and static analysis green, docs updated, Linear issue closed. |
| Decisions | ADRs in `docs/adr/`, numbered, short (context, options, decision, consequences). |
| Language | English in the repository. |

## 4. Decisions so far

| # | Decision | Status |
| :--- | :--- | :--- |
| D1 | Public repo at `github.com/uslanozan/laravel-cache-contract`, MIT license | ✅ agreed |
| D2 | Definitions live in **one PHP config file**, split into sections (`user`, `order`…). IDs are `section.name`, snake_case (`user.price_number`). Each definition has `subject` (model class) + `purpose`; the same pair twice is an exact duplicate. → [ADR 0002](adr/0002-definition-format.md) | ✅ agreed |
| D3 | Contract rules are also enforced in **CI** (lint + static analysis) | ✅ agreed |
| D4 | Local development runs in **Docker** (PHP + Redis) | ✅ agreed |
| D5 | Target versions: **PHP `^8.3`, Laravel `^11.23 \|\| ^12.0 \|\| ^13.0`**, Redis 7.x. Company projects run PHP 8.3 + Laravel 11, so 11 is supported; 11.23 is the first release with `Cache::flexible`. Features added later (`Cache::memo` 12.9, failover store 12.35) cannot be required by the core. → [ADR 0003](adr/0003-supported-versions.md) | ✅ agreed |
| D6 | Git flow: `feat/*` → `develop` (squash) → `main` (merge commit, tagged) | ✅ agreed |
| D7 | Cached values live in Redis; definitions in git; scan output is a regenerable artifact (no SQLite); no per-key index in Redis in v1. → [ADR 0005](adr/0005-where-data-lives.md) | ✅ agreed |
| D8 | Physical keys are generated from one project-wide, configurable `key_format`. The default includes a definition version; complex parameters are hashed. → [ADR 0004](adr/0004-key-format.md) | ✅ agreed |
| D9 | Model API: a `ModelCache` service does the work; a `HasCache` trait on models is a shortcut to it and hooks model events. → [ADR 0006](adr/0006-model-api.md) | ✅ agreed |
| D10 | Artisan commands use the package prefix `cache-contract:` (not Laravel's `cache:`). The "new definition" command only **shows** similar definitions; it does not edit the config file. | ✅ agreed |
| D11 | No Laravel Sail in this repo (Sail is for applications; the package uses `docker-compose.yml`). The demo app may use Sail. | ✅ agreed |
| D12 | Composer package name: **`uslanozan/laravel-cache-contract`** | ✅ agreed |
| D13 | Redis client: start with **phpredis** (Laravel's default, compatible with PHP 8.3 + Laravel 11); predis support and Redis Cluster are decided once company production details are known | ⏳ to confirm with company |
| D14 | Demo app lives in this repo under **`examples/`** (a full Laravel app that installs the package from the local path; excluded from the Composer dist). May move to its own repo later. | ✅ agreed |
| D15 | Runtime facade name: **`CacheContract`** (matches the package name and the `cache-contract:` command prefix) | ✅ agreed |

## 5. Phases

The work splits into two tracks that can run **in parallel** after phase 0:

- **Track A (runtime):** phases 1 → 2 → 3, the code that runs inside the app.
- **Track B (tooling):** research → phases 4 → 5, the code that runs at
  development/CI time.

Phase 6 brings both together. Cycle counts are rough estimates for planning,
not commitments.

### Phase 0: Foundation *(≈ 1–2 cycles)*

Goal: everyone can clone, run tests in Docker and open a PR that passes CI.

- [x] Repository conventions: PR/issue templates, CI skeleton, PR checks,
      Dependabot, rulesets as code, commit & branching docs, CONTRIBUTING,
      SECURITY, LICENSE, Docker environment
- [x] GitHub setup (see checklist in section 8)
- [ ] Linear team, weekly cycles, GitHub integration
- [ ] Package skeleton (`spatie/package-skeleton-laravel` as reference,
      `spatie/laravel-package-tools` for the provider): `composer.json`
      (name, autoload, constraints), service provider, config file,
      Orchestra Testbench, Pest, Larastan, Pint, Composer scripts
      `test` / `analyse` / `lint` / `format`
- [x] `docs/adr/` with template and first ADRs (0001–0006)
- [ ] Learning track for the team (Laravel cache internals, service
      providers/facades, package development, Pest): short notes in
      `docs/learning/` are welcome

**Research spike (Track B, can start in this phase):**

- [ ] Public scan: look at popular open-source Laravel apps and record how
      they name and build cache keys (literals, concatenation, `sprintf`,
      constants, tags, `Redis::` direct use, helper vs facade vs injected
      repository). **Patterns only, no code copied.** Output:
      `docs/research/cache-usage-in-the-wild.md`.
- [ ] Prior art: existing caching packages and what they do not solve
      (first pass done in the team's research report: genealabs/laravel-model-caching,
      rennokki/laravel-eloquent-query-cache, cerbero/laravel-enum, iak/keys).
      Move it into `docs/research/` in English. Feeds the "considered
      alternatives" section of the architecture doc.

**Exit:** CI green on an empty package with one passing test; first ADRs merged.

### Phase 1: Core contract *(≈ 2–3 cycles)* · Track A · → `v0.1.0`

Goal: a working vertical slice: define → validate → generate key → read/write.

- Definition schema ([ADR 0002](adr/0002-definition-format.md)): sections,
  `subject`, `purpose`, `type`, `params`, `ttl` (required), `version`,
  `description` (required), optional `tags`; unknown fields rejected
- Loader + validation: invalid names, empty descriptions, missing TTL, bad
  parameter specs, unknown subject classes; consistent, actionable error
  messages ("did you mean …?")
- Registry: lookup by id, list, filter by section / subject
- Key factory ([ADR 0004](adr/0004-key-format.md)): project-wide
  `key_format`, version segment, readable scalar parameters, hashed complex
  parameters, explicit context (tenant, locale…), deterministic output
- Runtime API (`CacheContract` facade): `get`, `put`, `remember`, `flexible`, `refresh`,
  `forget`; unknown definition / wrong params → exception
- Explicit semantics for miss vs cached `null` / `false` / `0` / empty list
- Redis error policy (read failure, write failure) as config
- "Flush everything" only ever touches the package's own keys
  (`Cache::flush()` ignores prefixes and would wipe a shared Redis)
- Commands: `cache-contract:list`, `cache-contract:inspect`

**Exit:** description / naming / TTL / unknown-definition / key determinism
rules all covered by tests.

### Phase 2: Eloquent model cache *(≈ 2 cycles)* · Track A · → `v0.2.0`

Goal: cache models without a class per model.

- `ModelCache` service: `ModelCache::for(User::class)->find($id)` and
  `->findMany($ids)`; `HasCache` trait shortcuts (`User::cached(5)`,
  `User::cachedMany([...])`, `$user->forgetCache()`, `User::flushCache()`)
  that delegate to the service ([ADR 0006](adr/0006-model-api.md))
- Convention keys: by-id caching of a model needs no definition; template,
  tag and default TTL are derived. Custom data (profile, lists, stats) is
  defined in the config with `subject` pointing at the model
- Bulk path: multi-get → collect misses → one scoped DB query → fill cache;
  documented result order and not-found behaviour
- Serialization strategy (ADR): proposed default is to store attributes only
  and rebuild models with `newFromBuilder`; relations cached separately
- Context-aware keys (tenant / connection / visibility); no mutable state
  leaking between requests (safe for queues and Octane)

**Exit:** partial bulk hit test (some cached, some loaded) passes with correct
order and scope.

### Phase 3: Invalidation & production behaviour *(≈ 2–3 cycles)* · Track A · → `v0.3.0`

Goal: data stays correct when it changes.

- Strategy ADR: **Laravel tags vs. generation counters (namespace versions)**,
  decided with a small benchmark/spike
- Tags are declared centrally in the config; `model:*` tags are reserved for
  the package; no per-record tags (`user:5`). Scheduled
  `cache:prune-stale-tags` documented for Redis
- Manual API: invalidate a definition, a definition + context, an entity
- Model event integration through `HasCache`; invalidation **after commit**;
  `invalidated_by` for related models
- Documented limits: mass updates (`query()->update()`), `saveQuietly`,
  raw SQL, external writers; `flushCache()` as the escape hatch
- Stale-while-revalidate with `Cache::flexible` (available from Laravel 11.23)
- Stampede protection with Laravel atomic locks; lock timeout behaviour
- Stale-write protection (a slow loader must not overwrite fresh data after
  an invalidation)
- Lists: a new row entering an "active users" list invalidates it

**Exit:** tests for list membership change, commit/rollback, lock timeout and
stale write against real Redis.

### Phase 4: Discovery & similarity *(≈ 2 cycles)* · Track B · → `v0.4.0`

Goal: a developer finds the existing definition instead of creating a duplicate.

- Exact duplicates are errors: same `subject` + `purpose`, same normalized
  name, same key template
- Name normalization: `active_users`, `active-user`, `activeUsers` → tokens
- Scoring: Levenshtein + token overlap (pure PHP, deterministic); structural
  comparison (subject, parameters, result type)
- Threshold calibrated on a fixture of 30–50 hand-labelled similar /
  not-similar pairs, kept as a test
- Similarity behind an interface so the algorithm can be swapped later
- Meaningful differences preserved (`active` vs `inactive`)
- Finding types: `identifier_collision`, `key_collision`,
  `lexical_similarity`, `structural_duplicate_candidate`, each with reasons
- `cache-contract:search <term>`
- `cache-contract:make <id>`: lists similar definitions **before** one is
  added; it does not write to the config file
- Suppressions with a required reason

**Exit:** `activeUsers` finds `active_users`; `inactive_users` is reported as
similar but never merged.

### Phase 5: Scanner & enforcement *(≈ 3 cycles)* · Track B · → `v0.5.0`

Goal: see every cache usage in an existing codebase and stop new bypasses.

- AST scanner (nikic/php-parser): `Cache::` facade, `cache()` helper,
  injected `Repository`, `Redis::` direct calls; read / write / remember /
  forget classification
- Inventory: file, line, class/method, operation, key as exact / template /
  unresolved; unresolved usages always reported
- Output: table and JSON; runs **on demand** (`cache-contract:scan`) and in CI
- Baseline file for accepted legacy usages, each with a reason
- `cache-contract:lint`: contract checks (including a duplicate array key
  inside the config file, which PHP would silently overwrite, found by
  parsing the file) + "no cache calls outside the package" with configurable
  allowed paths; non-zero exit code in CI
- Strictness by environment: warning locally, error in CI, silent and
  zero-overhead in production
- PHPStan rule for the same check inside static analysis
- Optional dev-time listener on cache events that warns about keys written
  outside the contract (catches dynamic keys static analysis misses)
- Migration guide: legacy usage → central definition

**Exit:** scanner reports fixtures built from the public-scan patterns; lint
fails CI on a direct `Cache::put` in app code.

### Phase 6: Demo, documentation, hardening *(≈ 2–3 cycles)* → `v1.0.0`

- Demo Laravel app using every feature (single + bulk model cache, lists,
  invalidation, discovery, scanner on a legacy module)
- Documentation: installation, configuration, every API and command, upgrade
  notes
- Architecture document: chosen design, alternatives considered, key decisions
  (links to ADRs)
- Concurrency and failure tests (Redis down, lock contention)
- Release automation (changelog from commits, tags), Packagist publishing
- `1.0.0` criteria: stable public API, supported versions table, security
  policy with support window

### Later / out of scope for 1.0

- Runtime statistics per definition (hit/miss counters, Laravel Pulse card)
- Live Redis inspection: map keys back to definitions, report "orphan" keys
  that belong to no definition (`SCAN`, never `KEYS`)
- Redis Cluster / Sentinel support (only once tested)
- In-request memory layer with `Cache::memo` (Laravel 12.9+ only, so
  optional and feature-detected)
- Generated IDE helper / enum of definition IDs for autocompletion
- `cache-contract:make` writing the new definition into the config file
- Redis set index of written keys
- YAML/JSON contract adapter
- AI/embedding-based similarity, automatic merging, source rewriting

## 6. Open questions

| Question | Owner | Needed by |
| :--- | :--- | :--- |
| Which Redis version and client do company projects run, and is Redis Cluster used? | Ozan | Phase 1 (D13) |
| Do company projects run Laravel Octane? (Design is Octane-safe from day one regardless: no request state in singletons.) | Ozan | Phase 2 |

## 7. ADR backlog

| ADR | Topic | Phase | Status |
| :--- | :--- | :--- | :--- |
| [0001](adr/0001-record-architecture-decisions.md) | Record architecture decisions | 0 | accepted |
| [0002](adr/0002-definition-format.md) | Definition format: one sectioned PHP file, subject + purpose | 0 | accepted |
| [0003](adr/0003-supported-versions.md) | Supported PHP / Laravel / Redis versions | 0 | accepted |
| [0004](adr/0004-key-format.md) | Physical key format, versioning, parameter encoding | 0 | accepted |
| [0005](adr/0005-where-data-lives.md) | Where data lives: definitions, values, scan output, stats | 0 | accepted |
| [0006](adr/0006-model-api.md) | Model API: service + trait shortcut | 0 | accepted |
| 0007 | Miss vs cached falsy values; Redis error policy | 1 | backlog |
| 0008 | Eloquent serialization strategy | 2 | backlog |
| 0009 | Invalidation strategy: tags vs generation counters | 3 | backlog |
| 0010 | Duplicate detection layers (registry, create-time, CI, scanner) | 4 | backlog |
| 0011 | Scanner scope and limits | 5 | backlog |

## 8. GitHub setup checklist (one-time)

- [x] Create public repo `uslanozan/laravel-cache-contract`, push `main`
- [x] Create `develop` from `main`, set it as the **default branch**
- [x] Settings → General: allow **squash** and **merge commits**, disable
      rebase merge; squash and merge commit title = PR title, body blank;
      auto-delete head branches
- [x] Apply rulesets from `.github/rulesets/` (see its README)
- [x] Create labels from `.github/labels.json`
- [x] Enable private vulnerability reporting, Dependabot alerts, Discussions
- [ ] Add contributors with the **Write** role
- [ ] Connect Linear (GitHub integration, branch-name linking)
