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

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE)
