# Installation

## Requirements

- PHP 8.3.36+, 8.4.27+ or 8.5.12+ (earlier releases have a known zero-day)
- Laravel 11.44.2+, 12.0.1+ or 13 (on PHP 8.5: Laravel 12.40+ or 13)
- Redis with the phpredis extension, used as a Laravel cache store

Other cache stores work for trying the package out (the test suite also runs
on the `array` store), but the package is designed and tested for Redis.

## Require the package

The package is not on Packagist yet. Add the GitHub repository to your
`composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/uslanozan/laravel-cache-contract" }
]
```

Then require it. Until the `v0.1.0` tag exists, use `dev-develop`:

```bash
composer require uslanozan/laravel-cache-contract:^0.1
# before v0.1.0 is tagged:
composer require uslanozan/laravel-cache-contract:dev-develop
```

The service provider and the `CacheContract` and `ModelCache` facades are
registered automatically through package discovery.

## Publish the config

```bash
php artisan vendor:publish --tag=cache-contract-config
```

This creates `config/cache-contract.php`. Every setting has a comment; the
most important ones are below.

| Setting | Default | Meaning |
| :--- | :--- | :--- |
| `prefix` | `cc` (`CACHE_CONTRACT_PREFIX`) | First part of every key; keeps this application's keys apart from others on the same Redis. |
| `key_format` | `{prefix}:{section}:{name}:v{version}:{params}` | One format for every key (ADR 0004). Changing it changes every key at once. |
| `store` | `null` (`CACHE_CONTRACT_STORE`) | The store from `config/cache.php` that holds the entries; `null` uses the default store. |
| `redis_errors` | `report` for read, write and invalidate | What happens when Redis is down; see [Production](production.md). |
| `models` | `ttl` 3600, `chunk_size` 1000 | The model cache; see [Model cache](model-cache.md). |
| `definitions` | `[]` | Your cache entries; see [Definitions](definitions.md). |
| `scanner` | whole project, without `vendor`, `tests`… | Where the scanner looks; see [Scanner](scanner.md). |

## Choose the store

Point the package at a Redis store. If your application's default store is
already Redis, there is nothing to do. Otherwise:

```dotenv
CACHE_CONTRACT_STORE=redis
```

Give the Redis connection behind that store a short `timeout` and
`read_timeout`; without them, a Redis server that does not answer can make
every cache call wait up to 60 seconds. See [Production](production.md).

## Next

Define your first cache entry: [Definitions](definitions.md).
