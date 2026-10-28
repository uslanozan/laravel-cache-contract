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

## Update 2026-10-11: Laravel 11 security advisories

The first CI run with the real package (PR #6) showed that **every Laravel 11
release, including the latest 11.57.0, is affected by published security
advisories** that will not be patched: a high-severity CRLF injection in the
default email validation rule, a medium-severity temporary signed URL path
confusion and a low-severity XSS in the debug page (fixed only in 12.60+ /
13.x). Composer refuses to install packages with known advisories by default.

The decision above stands: Laravel 11 stays supported because company
projects run it. In CI, the Laravel 11 jobs alone lift the advisory block for
`laravel/framework`; Laravel 12 and 13 jobs keep it. The README tells users
that Laravel 11 is end of life with known vulnerabilities and recommends
upgrading. This strengthens the consequence above: Laravel 11 is the first
version to drop.

## Update 2026-10-17: floors match what the lowest CI jobs install

None of the three `prefer-lowest` jobs installed the floor we claimed:

| Claimed | Installed | Why |
| :--- | :--- | :--- |
| `^11.23` | 11.44.2 | Dev tooling: `nunomaduro/collision` 8.8.3 (required by Pest 3.8) conflicts with `laravel/framework <11.44.2`; `orchestra/testbench-core` 9.6.3 with `<11.33.2`. The Laravel 11 jobs already lift the advisory block, so it plays no part. |
| `^12.0` | 12.69.0 | Composer's advisory block: every release below 12.69.0 is affected by at least one advisory. |
| `^13.0` | 13.30.0 | Same: every release below 13.30.0 is affected. |

So most of the claimed range was never tested, against the decision above
("we claim support only for what the CI matrix tests"). The Laravel 12/13
floors were also moving: the last jump came from `PKSA-d5tc-s1qs-h781` (a
low-severity XSS in the debug page, published 2026-09-29), which raised them
from 12.61.1 and 13.12.0. Following them would mean raising our constraint
after every Laravel advisory.

**Decision:**

- The `prefer-lowest` jobs lift the advisory block for `laravel/framework`,
  as the Laravel 11 jobs already do. Composer's lowest resolution is then
  limited only by our test tooling, which changes only when we change it.
  Laravel 12/13 `prefer-stable` jobs keep the protection. This tests old,
  vulnerable framework releases in CI only; it does not change what users
  install.
- Each floor is the lowest release our test tooling can install:
  `^11.44.2 || ^12.0.1 || ^13.0` in `composer.json` and the CI matrix.
  - **11.44.2**: Collision, as above. It still includes `Cache::flexible`
    (11.23), so the reason for the original floor holds; the feature table
    above keeps 11.23 because that is when the feature arrived. Company
    projects run 11.56 and are not affected. Lowering it again would mean
    older dev tooling in the lowest job; not worth it for an end-of-life
    version.
  - **12.0.1**: `orchestra/testbench` 10.0.0 requires `^12.0.1`.
  - **13.0.0**: unchanged.
- **On PHP 8.5, Laravel 12 starts at 12.40.0.** With the block lifted, 12.0.1
  on PHP 8.5 fails two Redis tag tests with `Cannot use bool as array` in
  Laravel's `RedisTagSet`; 12.39.0 fails the same way and 12.40.0 passes.
  12.40.0 is the first release with "PHP 8.5 Compatibility"
  (laravel/framework#57835). Like Laravel 11 on PHP 8.5, this follows
  Laravel's own PHP support; the CI matrix sets `^12.40.0` for that cell only.

Verified locally in Docker against real Redis: every `prefer-lowest` cell
passes at these floors (PHP 8.3 and 8.4 × Laravel 11/12/13; PHP 8.5 ×
Laravel 12.40.0 and 13.0.0).

## Update 2026-10-27: PHP floor raised for the zero-day

A zero-day in PHP is fixed in **8.5.12, 8.4.27 and 8.3.36** (and 8.2.35,
which is below our range). The company is patching its projects to these
releases (LRVL-70). The package must not allow, or be tested on, a release
that is still vulnerable.

**Decision:**

- `composer.json` requires `~8.3.36 || ~8.4.27 || ^8.5.12`: a separate floor
  for each minor version. `^8.3.36` alone would not work, because it also
  allows 8.4.0–8.4.26. `^8.5.12` keeps later versions (8.6+) open, as
  `^8.3` did.
- The CI matrix keeps PHP 8.3, 8.4 and 8.5. `setup-php` installs the latest
  patch release of each, and Composer refuses to install on a PHP release
  below the constraint, so a vulnerable runner fails the job instead of
  passing quietly.
- The Docker image is pinned to the patch release (`8.3.36` by default),
  because a cached `8.3` tag can still be an older, vulnerable build.

The Laravel constraints and the "minus Laravel 11 on PHP 8.5" rule do not
change. Company projects on PHP 8.3 have to be on 8.3.36 or later to install
the package.
