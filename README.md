# laravel-cache-contract

> 🚧 **Work in progress.** Not ready for use yet; there is no release.

A Laravel package that puts every cache key in a project under one central,
reviewable contract, on top of Laravel's own cache and Redis.

- **Central definitions:** each cache entry type is defined once, in PHP, with
  a required description, TTL and typed parameters. Unknown definitions and
  wrong parameters fail loudly.
- **Consistent keys:** keys are generated deterministically from the definition
  and its context (tenant, locale, projection…), never typed by hand.
- **Discovery:** list and search existing definitions, and get warned about
  similar ones before creating a duplicate.
- **Eloquent without per-model classes:** cache single models and ID lists
  through one generic API, with bulk loading for partial hits.
- **Enforcement:** a scanner and CI rules find cache calls that bypass the
  contract.

See the [roadmap](docs/ROADMAP.md) for the plan and current status.

## Requirements

- PHP 8.3 or newer
- Laravel 11.44.2+, 12.0.1+ or 13 (on PHP 8.5: Laravel 12.40+ or 13)
- Redis (phpredis) as the cache store

> **Laravel 11 is end of life** and every 11.x release has known, unpatched
> security advisories. The package supports it because existing projects still
> run it, but upgrading to Laravel 12.69+ or 13.30+ (the lowest releases with
> no known advisories as of October 2026) is strongly recommended. See
> [ADR 0003](docs/adr/0003-supported-versions.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE)
