# Roadmap

> **Status:** draft v0.1, written 2026-10-09 before the first team meeting.
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
| D2 | Definitions are written in **PHP** (not JSON). A compile step can generate an enum for IDE autocompletion and find-usages. | ✅ agreed, details → ADR |
| D3 | Contract rules are also enforced in **CI** (lint + static analysis) | ✅ agreed |
| D4 | Local development runs in **Docker** (PHP + Redis) | ✅ agreed |
| D5 | Target versions: **PHP `^8.3`, Laravel `^12.0 \|\| ^13.0`**, Redis 7.x | ⏳ provisional until company versions are known |
| D6 | Git flow: `feat/*` → `develop` (squash) → `main` (merge commit, tagged) | ✅ agreed |
| D7 | Cached values live in Redis; definitions in git; scan output is a regenerable artifact (no SQLite) | ✅ agreed in principle, details → ADR |

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
- [ ] GitHub setup (see checklist in section 8)
- [ ] Linear team, weekly cycles, GitHub integration
- [ ] Package skeleton: `composer.json` (name, autoload, constraints),
      service provider, config file, Orchestra Testbench, Pest, Larastan,
      Pint, Composer scripts `test` / `analyse` / `lint` / `format`
- [ ] `docs/adr/` with template and first ADRs: D2 (contract format),
      D5 (versions), D7 (storage)
- [ ] Learning track for the team (Laravel cache internals, service
      providers/facades, package development, Pest): short notes in
      `docs/learning/` are welcome

**Research spike (Track B, can start in this phase):**

- [ ] Public scan: look at popular open-source Laravel apps and record how
      they name and build cache keys (literals, concatenation, `sprintf`,
      constants, tags, `Redis::` direct use, helper vs facade vs injected
      repository). **Patterns only, no code copied.** Output:
      `docs/research/cache-usage-in-the-wild.md`.
- [ ] Prior art: existing caching packages and what they do not solve.
      Feeds the "considered alternatives" section of the architecture doc.

**Exit:** CI green on an empty package with one passing test; first ADRs merged.

### Phase 1: Core contract *(≈ 2–3 cycles)* · Track A · → `v0.1.0`

Goal: a working vertical slice: define → validate → generate key → read/write.

- Definition schema in PHP: id (snake_case), description (required), version,
  TTL, typed parameters, entity, result type, optional tags/dependencies
- Loader + validation: duplicate ids (definitions as a **list**, so duplicates
  are not silently overwritten), invalid names, empty descriptions, bad
  parameter specs
- Registry: lookup by id, list, filter
- Key factory: `{prefix}:{definition}:v{version}:{canonical params hash}`,
  plus explicit context (tenant, locale, projection…); parameter
  canonicalization that preserves types and meaningful list order
- Runtime API (`ManagedCache`): `get`, `put`, `remember`, `refresh`, `forget`;
  unknown definition / wrong params → exception
- Explicit semantics for miss vs cached `null` / `false` / `0` / empty list
- Redis error policy (read failure, write failure) as config
- Commands: `managed-cache:list`, `managed-cache:inspect`
- Compile step: generated enum of definition ids for IDE support (ADR first)

**Exit:** brief acceptance criteria for description / snake_case / duplicate /
unknown-definition / key determinism all covered by tests.

### Phase 2: Eloquent model cache *(≈ 2 cycles)* · Track A · → `v0.2.0`

Goal: cache models without a class per model.

- `ModelCache::for(User::class)->find($id)` and `->findMany($ids)`
- Model catalog: standard by-id definitions derived from config, custom
  queries defined explicitly
- Bulk path: multi-get → collect misses → one scoped DB query → fill cache;
  documented result order and not-found behaviour
- Serialization strategy (ADR): what is stored (attributes vs serialized
  model), relations, class changes across deploys
- Context-aware keys (tenant / connection / visibility); no mutable state
  leaking between requests (safe for queues and Octane)

**Exit:** partial bulk hit test (some cached, some loaded) passes with correct
order and scope.

### Phase 3: Invalidation & production behaviour *(≈ 2–3 cycles)* · Track A · → `v0.3.0`

Goal: data stays correct when it changes.

- Strategy ADR: **Laravel tags vs. generation counters (namespace versions)**,
  decided with a small benchmark/spike
- Manual API: invalidate a definition, a definition + context, an entity
- Opt-in model event integration; invalidation **after commit**
- Documented limits: mass updates, raw SQL, external writers
- Stampede protection with Laravel atomic locks; lock timeout behaviour
- Stale-write protection (a slow loader must not overwrite fresh data after
  an invalidation)
- Lists: a new row entering an "active users" list invalidates it

**Exit:** tests for list membership change, commit/rollback, lock timeout and
stale write against real Redis.

### Phase 4: Discovery & similarity *(≈ 2 cycles)* · Track B · → `v0.4.0`

Goal: a developer finds the existing definition instead of creating a duplicate.

- Name normalization: `active_users`, `active-user`, `activeUsers` → tokens
- Scoring: exact, Levenshtein, token / n-gram overlap; structural comparison
  (entity, parameters, result type, scope)
- Meaningful differences preserved (`active` vs `inactive`)
- Finding types: `identifier_collision`, `key_collision`,
  `lexical_similarity`, `structural_duplicate_candidate`, each with reasons
- `managed-cache:search <term>`
- `make:cache-definition`: shows similar definitions **before** creating a
  new one (the moment duplicates are cheapest to prevent)
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
- Output: table and JSON; runs **on demand** (`managed-cache:scan`) and in CI
- Baseline file for accepted legacy usages, each with a reason
- `managed-cache:lint`: contract checks + "no cache calls outside the
  package" with configurable allowed paths; non-zero exit code in CI
- PHPStan rule for the same check inside static analysis
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
- YAML/JSON contract adapter
- AI/embedding-based similarity, automatic merging, source rewriting

## 6. Open questions

| Question | Owner | Needed by |
| :--- | :--- | :--- |
| Which Laravel / PHP / Redis versions do company projects run? | Ozan | Phase 0 (D5) |
| Composer package name: `uslanozan/laravel-cache-contract`? | Team | Phase 0 skeleton |
| Redis client: phpredis, predis, or both supported? | Team | Phase 1 |
| Must it work under Laravel Octane from day one? | Team | Phase 2 |
| Is Redis Cluster used in company production? | Ozan | Phase 3 |
| Demo app: inside this repo (`workbench/`) or a separate repo? | Team | Phase 6 |

## 7. ADR backlog

| ADR | Topic | Phase |
| :--- | :--- | :--- |
| 0001 | Record architecture decisions (process) | 0 |
| 0002 | Definition format: PHP + generated enum | 0 |
| 0003 | Supported PHP / Laravel / Redis versions | 0 |
| 0004 | Where data lives: definitions, values, scan output, stats | 0 |
| 0005 | Key format and parameter canonicalization | 1 |
| 0006 | Miss vs cached falsy values; Redis error policy | 1 |
| 0007 | Eloquent serialization strategy | 2 |
| 0008 | Invalidation strategy: tags vs generation counters | 3 |
| 0009 | Duplicate detection layers (registry, create-time, CI, scanner) | 4 |
| 0010 | Scanner scope and limits | 5 |

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
