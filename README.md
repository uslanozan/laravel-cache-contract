# laravel-cache-contract

> **Pre-release.** The API can still change before 1.0. There is no tagged
> release yet; the first one is `v0.1.0`.

A Laravel package that puts every cache entry in a project under one central,
reviewable contract, on top of Laravel's own cache and Redis.

| | Feature | Status |
| :--- | :--- | :--- |
| **Definitions** | Each cache entry type is defined once in PHP, with a description, a TTL and typed parameters. Unknown IDs and wrong parameters fail loudly. | ✅ |
| **Keys** | Keys are generated from the definition, its parameters and the context (tenant, locale…), never typed by hand. | ✅ |
| **Runtime API** | `CacheContract::remember()`, `get`, `has`, `put`, `refresh`, `forget`; a cached `null`, `false` or `0` is a hit. | ✅ |
| **Model cache** | `ModelCache::for(User::class)->find(5)` and `findMany()` without per-model classes. Models with global scopes (e.g. a tenant) need the tenant in the context; see [Model cache](docs/guide/model-cache.md#tenants-and-global-scopes). | ✅ |
| **Discovery** | `cache-contract:list` and `cache-contract:inspect`, with "did you mean" suggestions for a mistyped ID. | ✅ (warnings for similar definitions: planned) |
| **Scanner** | `cache-contract:scan` lists every cache call in a codebase with its file, line and how much of its key can be read. | ✅ |
| **Invalidation** | Models with `HasCache` clear their entries after a change is committed, a definition's `invalidated_by` clears all its entries when a listed model changes, and `CacheContract::flushTag()` clears every definition with a tag, all with generation counters; see [Model cache](docs/guide/model-cache.md#automatic-invalidation) and [Definitions](docs/guide/definitions.md#invalidated_by). | ✅ |
| **Enforcement** | Lint and PHPStan rules for CI. | 🗓️ planned |

## Quick example

```php
// config/cache-contract.php
'definitions' => [
    'user' => [
        'profile' => [
            'purpose'     => 'profile',
            'type'        => 'array',
            'params'      => ['user_id' => 'int'],
            'ttl'         => '1 hour',
            'description' => 'Profile page data.',
        ],
    ],
],
```

```php
use Uslanozan\CacheContract\Facades\CacheContract;

$profile = CacheContract::remember('user.profile', ['user_id' => 42], fn () => $this->loadProfile(42));
// key: cc:user:profile:v1:user_id=42
```

## Requirements

- PHP 8.3.36+, 8.4.27+ or 8.5.12+ (earlier releases have a known zero-day)
- Laravel 11.44.2+, 12.0.1+ or 13 (on PHP 8.5: Laravel 12.40+ or 13)
- Redis (phpredis) as the cache store

> **Laravel 11 is end of life** and every 11.x release has known, unpatched
> security advisories. The package supports it because existing projects still
> run it, but upgrading to Laravel 12.69+ or 13.30+ (the lowest releases with
> no known advisories as of October 2026) is strongly recommended. See
> [ADR 0003](docs/adr/0003-supported-versions.md).

## Installation

The package is not on Packagist yet, so install it from GitHub. There is no
tag yet either, so require `dev-develop`; once `v0.1.0` is tagged, use `^0.1`.

```json
// composer.json
"repositories": [
    { "type": "vcs", "url": "https://github.com/uslanozan/laravel-cache-contract" }
]
```

```bash
composer require uslanozan/laravel-cache-contract:dev-develop
php artisan vendor:publish --tag=cache-contract-config
```

Details: [Installation](docs/guide/installation.md).

## Documentation

The [user guide](docs/guide/README.md) covers installation, definitions, the
runtime API, the model cache, the commands and production settings. The
decisions behind the design are in [`docs/adr/`](docs/adr/), and the
[changelog](CHANGELOG.md) lists what each release adds.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE)
