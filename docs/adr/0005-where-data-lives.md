# 0005. Where data lives

- **Status:** accepted
- **Date:** 2026-10-09

## Context

The package handles four kinds of data with very different lifetimes and
readers. A single store (for example a local SQLite file) was considered and
rejected: production apps run on several servers, each would have its own
file, and writing to it on every request adds locking and latency.

## Decision

| Data | Where | Why |
| :--- | :--- | :--- |
| **Definitions** (ID, description, TTL, params…) | The PHP config file, in git | Source of truth; reviewed in PRs; loaded through `config:cache` |
| **Cached values** | Redis, through Laravel's cache store | The point of the package |
| **Scan output** (usage inventory) | Command output / JSON file, not committed | Regenerable from source at any time |
| **Accepted exceptions** | A committed baseline file, each entry with a reason | Like PHPStan's baseline |
| **Runtime statistics** (hit/miss) | Not in v1. Later: per-definition counters fed by Laravel cache events, optionally shown in Laravel Pulse | New scope; keep the hot path lean first |

We do **not** keep an index of every written key in Redis (for example a set
of key names) in v1. It costs an extra write per cache write and needs its own
cleanup when keys expire. "Which definitions exist" is answered by the config;
"which keys are in Redis right now" is answered on demand with `SCAN`, never
`KEYS`.

## Consequences

- No extra infrastructure; nothing to back up besides git and Redis.
- Listing physical keys is slower (a `SCAN`) but rare.
- If usage statistics turn out to be needed, they get their own ADR.
