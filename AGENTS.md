# AGENTS.md

Working rules for everyone on this repository: the team and the AI coding
agents they use (Codex reads this file; Claude Code reads it through
`CLAUDE.md`). If a rule here conflicts with a habit, the rule wins.

## What this is

`uslanozan/laravel-cache-contract`: a Laravel package that manages cache usage
through one central contract. Namespace `Uslanozan\CacheContract`, facade
`CacheContract`, commands `cache-contract:*`.

Read before changing anything substantial:

- [`docs/ROADMAP.md`](docs/ROADMAP.md): goals, decisions D1–D15, phases, team & ownership
- [`docs/adr/`](docs/adr/): accepted architecture decisions
- [`CONTRIBUTING.md`](CONTRIBUTING.md): setup and everyday commands

## Workflow rules (mandatory)

1. **Every task has a Linear issue** (team `LRVL`). No issue yet? Create one
   first, in the current cycle, with an assignee, a Type and an Area label.
2. **Move the issue to *In Progress* when you start**, before the first commit.
3. **Branch** from `develop`: `<type>/lrvl-<n>-<short-description>`
   (`feat/`, `fix/`, `docs/`, `test/`, `refactor/`, `chore/`, `ci/`, `build/`, `perf/`).
   Linear's *Copy git branch name* (`Ctrl+Shift+.`) gives the part after the slash.
4. **Commits and PR titles** follow [Conventional Commits](docs/commit-convention.md).
   The PR title becomes the squash commit on `develop`.
5. **Open a PR into `develop`** and fill in the template, including
   `Closes LRVL-<n>`. The issue moves to *In Review* by itself once a reviewer
   is requested (CODEOWNERS requests one; if you own every changed file,
   request a teammate yourself).
6. **Merge** after one approval, green checks and resolved threads. The issue
   moves to *Done* by itself. Work that ends without a PR is moved to *Done*
   by hand, and only when its output exists.
7. **Never push directly** to `main` or `develop`. Never force-push shared branches.
8. Changes under `src/Contracts/` need **two** approvals: they affect everyone.

## Development environment

Everything runs in Docker; no PHP is needed on the host.

```bash
docker compose up -d --build
docker compose exec php composer install
docker compose exec php composer test       # Pest
docker compose exec php composer analyse    # Larastan
docker compose exec php composer lint       # Pint (check)
docker compose exec php composer format     # Pint (fix)
```

CI runs the same scripts against real Redis on PHP 8.3–8.5 × Laravel 11/12/13
(the latest patch release of each PHP version).

## Code rules

- Supported range: PHP `~8.3.36 || ~8.4.27 || ^8.5.12` (the zero-day fixes),
  Laravel `^11.44.2 || ^12.0.1 || ^13.0`; on PHP 8.5, Laravel 12.40+ (ADR 0003).
  Do not use `Cache::memo` or the failover store in the core; they do not exist
  in Laravel 11.
- Reuse Laravel's cache repository, locks and events. Do not open separate
  Redis connections. Never call `Cache::flush()`; it wipes a shared Redis.
- **No request state in singletons** (tenant, user, locale). Pass it per call
  or use `scoped` bindings. The package must be safe under Octane and queue workers.
- Follow the accepted ADRs. A change that contradicts one needs a new ADR in
  the same or an earlier PR.
- New behaviour comes with tests in the same PR. Anything that depends on
  Redis semantics (TTL, locks, tags, atomicity) gets an integration test
  against real Redis, not only the `array` store.
- New or changed user-facing behaviour (API, config, commands, errors) updates
  its page in `docs/guide/` in the same PR, and creates the page if it does not
  exist yet. The README stays short: what the package does, installation, a
  quick example and links. `docs/guide/` is for package users; `docs/adr/`,
  `docs/research/` and the roadmap are for the team.
- Keep PRs small: one logical change.

## Repository rules

- English in code, comments, commits, PRs and docs.
- No secrets, tokens or personal data in the diff or history.
- `ozan/` is a private, git-ignored folder. Never commit or quote it.

## Rules for AI agents

- Before implementing a task, **explain the plan** (what, which files, why)
  and wait for the person you work with to confirm. Do not implement first
  and explain afterwards.
- Apply the Linear workflow above with the Linear tools: set *In Progress*
  when starting, never set *Done* before the work is actually finished.
- Run `composer test`, `composer analyse` and `composer lint` in Docker
  before saying a change works, and report failures as they are.
