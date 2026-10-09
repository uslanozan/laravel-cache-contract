# 0003. Supported PHP, Laravel and Redis versions

- **Status:** accepted
- **Date:** 2026-10-09

## Context

The package's first users are company projects running **PHP 8.3 and
Laravel 11** (11.56). Laravel's current releases are 12 (security fixes only,
until 2027-02-24) and 13 (current). Laravel 11 stopped receiving security
fixes on 2026-03-12 and supports PHP 8.2–8.4.

Several cache features we would like to use arrived at different times:

| Feature | First release |
| :--- | :--- |
| `Cache::flexible` (stale-while-revalidate) | Laravel 11.23 |
| `Cache::memo` (in-request memoization) | Laravel 12.9 |
| Failover cache store | Laravel 12.35 |

## Decision

- **PHP `^8.3`**: the company's version, and the minimum for Laravel 13.
- **Laravel `^11.23 || ^12.0 || ^13.0`**: 11.23 because it is the first
  11.x release with `Cache::flexible`.
- **Redis 7.x** in CI and local development.
- The core may use `Cache::flexible`. It must **not** depend on `Cache::memo`
  or the failover store; those can only be optional, feature-detected extras.
- We claim support only for what the CI matrix tests: PHP 8.3–8.5 ×
  Laravel 11/12/13, minus Laravel 11 on PHP 8.5.

## Consequences

- The package installs in the company projects as they are today.
- The CI matrix is larger (three Laravel majors, lowest and stable dependencies).
- An in-request memory layer has to be written by us or stay optional until
  Laravel 11 is dropped.
- Laravel 11 should be the first version dropped, in a minor release before
  `1.0.0` or in a major after it. We should recommend the company upgrade,
  since 11 no longer gets security fixes.
- Redis version and client (phpredis / predis) used in company production are
  still to be confirmed.
