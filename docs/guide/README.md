# User guide

How to use `laravel-cache-contract` in a Laravel application. The package is
pre-release, so these pages describe the current `develop` branch; a page
appears here when its feature exists.

1. [Installation](installation.md): require the package, publish the config, choose the store.
2. [Definitions](definitions.md): define cache entries, their TTLs and parameters.
3. [Runtime API](cache-contract.md): read and write entries with the `CacheContract` facade.
4. [Model cache](model-cache.md): cache Eloquent models by ID without a definition.
5. [Commands](commands.md): `cache-contract:list`, `cache-contract:inspect` and `cache-contract:scan`.
6. [Production](production.md): Redis outages, timeouts, Octane and queue workers.
7. [Scanner](scanner.md): find the cache calls in your codebase, and how far their keys can be read.

Invalidation, lint rules and the upgrade guide get their pages when they ship.
