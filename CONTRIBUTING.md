# Contributing

Thanks for helping! This guide gets you from a fresh clone to a merged PR.

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) with Compose v2
- Git

PHP, Composer and Redis run inside containers, so you do not need them on your
machine. (If you do have PHP 8.3+ locally, you can run the same commands
without the `docker compose exec php` prefix.)

## Setup

```bash
git clone https://github.com/uslanozan/laravel-cache-contract.git
cd laravel-cache-contract

docker compose up -d --build           # PHP + Redis
docker compose exec php composer install
```

Check that everything is wired up:

```bash
docker compose exec php php -v
docker compose exec php php -m | grep redis
docker compose exec redis redis-cli ping   # PONG
```

## Everyday commands

| What | Command |
| :--- | :--- |
| Run tests | `docker compose exec php composer test` |
| Static analysis | `docker compose exec php composer analyse` |
| Check code style | `docker compose exec php composer lint` |
| Fix code style | `docker compose exec php composer format` |
| Shell inside the container | `docker compose exec php bash` |
| Try another PHP version | `PHP_VERSION=8.5 docker compose up -d --build` |
| Stop everything | `docker compose down` |

> The Composer scripts arrive with the package skeleton (roadmap phase 0).
> CI runs exactly these scripts, so a green run locally means a green run in CI.

## Workflow

1. Pick or create an issue in Linear (or GitHub). Larger design changes start
   as a *Design proposal* issue and end as an ADR in `docs/adr/`.
2. Branch from `develop`: `feat/CACHE-12-short-description`.
   See [docs/branching-strategy.md](docs/branching-strategy.md).
3. Commit using [Conventional Commits](docs/commit-convention.md).
4. Open a PR into `develop`. Fill in the template; the **Why?** section and the
   **Cache behaviour impact** section matter most.
5. Get one approval, keep the checks green, resolve all threads, then merge
   (squash).

## Standards

- **Tests are part of the change.** New behaviour comes with tests. Anything
  that depends on Redis semantics (TTL, locks, tags, atomicity) gets an
  integration test against real Redis, not only the `array` store.
- **Static analysis and style are not negotiable.** Fix the finding instead of
  adding it to the baseline. If a baseline entry is truly needed, explain why
  in the PR.
- **Public API changes are deliberate.** Facades, contracts, config keys,
  artisan commands, the definition schema and the key format are public. Changing
  them needs a note under *Public API impact* in the PR.
- **English** for code, comments, commits, PRs and docs.
- **No secrets** in the diff or the history.

## Code of conduct

Be respectful and constructive. Review the code, not the person.

## License

By contributing you agree that your contributions are licensed under the
[MIT License](LICENSE).
