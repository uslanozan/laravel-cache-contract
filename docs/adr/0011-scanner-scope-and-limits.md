# 0011. Scanner scope and limits

- **Status:** proposed
- **Date:** 2026-10-24

## Context

Phase 5 builds three tools on the same reading of the source: the scanner and
`cache-contract:scan` (LRVL-18 to LRVL-20), `cache-contract:lint` (LRVL-25)
and a PHPStan rule (LRVL-28). Lint and the rule turn what the scanner finds
into errors, so the scanner's scope decides what a team is told to fix.

The public scan ([cache-usage-in-the-wild.md](../research/cache-usage-in-the-wild.md))
shows that the cache store is used by more than cache code:

```php
Cache::put('profile:'.$id, $data, 3600);   // cache usage
RateLimiter::hit('login:'.$user->id);      // the framework writes its own key to the cache store
Route::middleware('throttle:60,1');        // the same, through middleware
Redis::zadd('feed:'.$userId, $ts, $postId); // a Redis data structure, not a cache
```

Pixelfed has 239 direct `Redis::` calls, about 23% of its Redis and `Cache::`
calls, almost all feeds, sets and indexes. Every project with a login has
`RateLimiter` or `throttle:` uses. If lint flagged them, a project like
Pixelfed would get hundreds of warnings it cannot act on, and the team would
turn the rule off. If the scan ignored them, `Redis::set('user:5', …)` used as
a cache outside the contract would never be seen.

The scanner reads source without running it and without type inference. What
it finds today, and how far it reads keys, is listed in the
[scanner guide](../guide/scanner.md) and in the
[prototype note](../research/ast-scanner-prototype.md). Two questions were left
for this ADR: what is in scope, and whether calls through a project's own
wrapper class are found where the wrapper is used.

## Options

### A. Everything that touches the cache store is in scope

- Pros: one rule, nothing hidden.
- Cons: lint fails on rate limiting and on Redis data structures, which the
  contract has no place for. Too noisy to keep enabled.

### B. Only the cache API is in scope; the rest is listed but not linted

- Pros: lint errors are all fixable by moving the call into a definition.
  Direct Redis and framework users stay visible in the scan report.
- Cons: `Redis::set()` used as a cache passes lint unless the team opts in.

### C. Only the cache API; everything else is ignored

- Pros: least code.
- Cons: Redis used as a cache is invisible, and the report understates how
  much of the store a project really uses.

## Decision

**Option B.** Every call the scanner finds falls into one of three categories:

| Category | What | Scan | Lint and PHPStan rule |
| :--- | :--- | :--- | :--- |
| **Cache** | `Cache::` (also through `store()` and `tags()`), `cache()`, an injected `Repository`, `Factory` or `CacheManager`, `app('cache')` and the other container forms | listed | error outside the package and the allowed paths |
| **Direct Redis** | `Redis::`, also through `connection()` | listed | off by default; a config option turns it on |
| **Indirect** | the `RateLimiter` facade, `throttle:` middleware strings | counted as indirect usage | never |

Session, queue and other framework features whose driver may be `cache` or
`redis` are not reported: whether they touch the cache store depends on
configuration, not on the code.

**Every key kind counts.** A cache call is a lint error whatever its key:
`exact`, `template`, `opaque` or `unresolved`. The key kind tells how hard the
migration is, not whether the call is allowed. An `unresolved` key is never
dropped; it is listed with its expression.

**Wrapper call sites are not found.** There is no configurable list of
wrapper classes. The scanner finds the cache calls inside the wrapper, and
moving the wrapper onto the contract moves every caller with it.

### What the scanner guarantees

For every scanned file that parses, every cache, direct Redis and indirect
call written in a form the scanner guide lists is reported, with its file,
line, class, method, operation and key kind. A file that does not parse, or a
`--path` that does not exist, is listed as an error and makes the command
exit non-zero; it is never skipped silently.

### What it does not

A clean scan does **not** mean that all cache usage goes through the
contract. Not found:

| Gap | Example | Covered by |
| :--- | :--- | :--- |
| Receivers without a native type | `/** @var Repository */ private $cache;`, `app(Repository::class)`, `$store = Cache::store('x'); $store->get(…)` | the PHPStan rule, which has types |
| Dynamic calls | `Cache::$method(…)`, `call_user_func([Cache::class, 'get'], …)` | the dev-time listener |
| Keys beyond a constant of the same class or a variable assigned once | another class's constant, a property, a key-builder method | reported `unresolved`; the dev-time listener sees the real key |
| Wrapper call sites | Firefly III's `CacheProperties` callers | the calls inside the wrapper |
| Code not scanned | Blade views, `vendor/`, excluded paths, facade aliases other than `Cache` and `Redis` | none |
| How often a call runs | a `Cache::get()` in a loop is one usage point | none; runtime statistics are not in v1 (ADR 0005) |

A codebase counts as migrated when scan and lint are clean, the PHPStan rule
passes, and the dev-time listener reports no key written outside the contract
while the test suite or a staging run exercises the code.

## Consequences

- Lint errors are always fixable by moving the call into a definition, so
  teams can keep the rule on in CI.
- The scanner needs a category per usage and must detect `RateLimiter::` and
  `throttle:` strings; this is new work in a follow-up issue, and the scanner
  guide documents it when it ships.
- LRVL-25 adds the config option for direct Redis and names it.
- The migration guide must say that a clean scan alone is not a complete
  migration, and list the gaps above.
- Revisit if a company project hides most of its cache usage behind wrappers
  (a configurable wrapper list), or if session or queue drivers on the cache
  store turn out to cause key collisions the scan should show.
