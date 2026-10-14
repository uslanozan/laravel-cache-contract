# Cache usage in the wild

This report looks at how six open-source Laravel applications use the cache: BookStack, Firefly III, Monica, Koel, Pixelfed and Akaunting. For each one it records the call styles, key families, naming, TTL and invalidation patterns, and candidate pairs for similar-key detection.

Method for every repository: shallow clone, text search (`grep`) with `scan-repo.sh`, no AST yet. Numbers are **usage points in the source, not runtime call counts**. Some repositories are AGPL (Firefly III, Monica, Pixelfed) and Akaunting uses the Business Source License: only counts, key shapes and naming patterns are recorded, no source code is copied, and example keys are generalized.

## BookStack

- **rev-head:** 241a7018e5597f4bf742257b5aa96a59c67f8913 (branch `development`; scanned 2026-10-12)
- **Laravel Version:** 12.69.3 (installed, from `composer.lock`; the constraint is `^12.26.4`) · **License:** MIT · **Min PHP:** 8.3
- **Cache Numbers:** 18 usage points in 9 files (the script lists 10; the extra one is a settings store matched by `$store->`, not a cache): 9 facade calls, 6 `cache()` helper calls, 3 on an injected repository (plus 1 `flush` on a library's own cache, not counted)
- **tags:** 6, none of them are cache tags (Eloquent relation, false positive)
- **RateLimiter:** 4
- **Styles:** facade, helper, injected repository
- **No Redis code** (default `CACHE_DRIVER=file`, prefix `bookstack`; Redis and Memcached servers are configurable in the environment file)

### Key families

| Key family | Used by | Resolution | Notes |
| :--- | :--- | :--- | :--- |
| `htmlpurifier::cache-version` | get, set | exact | Same literal written in two places |
| `status_test_{rand}` | add, pull | template (interpolation) | Health check: writes a random value, reads it back |
| `images::{id}::{path}` | get, put (x3) | template | Contains a file path, variable length |
| `api-docs::{appVersion}` | get, put | template | Versioned key |
| `oidc-discovery::{issuer}` | remember | template | Issuer is a URL |
| `saml2_acs:{acsId}` | set, pull | template | Built by hand in two methods |
| `custom-head-web:{hash}`, `custom-head-export:{hash}` | remember | template (inline) | Two similar names, two purposes |
| page content key | get, put | unresolved | Built inside a protected method from the page HTML and the application configuration, so no central place can see it |
| store passed to OIDC | indirect | unresolved | The cache object leaves the file |

Totals: 1 exact, 6 template, 1 unresolved family, 1 indirect usage.

### Naming observations

- **Separators are mixed in one project:** `::` (`images`, `api-docs`, `oidc-discovery`, `htmlpurifier`), `:` (`saml2_acs`, `custom-head-*`), `_` (`status_test`). Words use hyphens (`api-docs`) and underscores (`saml2_acs`).
- **Key strings are repeated at call sites:** `saml2_acs:` is built in two methods and the `htmlpurifier` literal appears twice. Editing one copy desynchronizes the other.
- **Key construction happens in three layers:** inline at the call, in a local variable, in a method return value.
- **Near-identical names, different purposes:** `custom-head-web` vs `custom-head-export`. A similarity check flags them even though they are distinct.

### Operations and TTL

- **Operations (18):** `put` 5, `get` 4, `remember` 3, `set` 2, `pull` 2, `add` 1, `store` 1. A scanner that only knows `get` / `put` / `forget` misses `set`, `add`, `pull` and `remember`. One `get` uses the helper in its read form (`cache('key')`), which is easy to miss.
- **Invalidation is by changing the key** (content hash, application version, configuration, resource id). No `forget`, `has` or `forever`. The only `flush` is on a library's own cache (HTML Purifier), through a plain `$cache` receiver, not a Laravel store.
- **TTL is written six ways:** inline number (`86400`), local expression (`86400 * 7`), class constant (`604_800`), unitless expression (`60 * 24`, written as minutes but passed straight to `put`, so it is 1440 seconds), parameter-derived (`$cacheMinutes * 60`), small bare number (`10`, seconds). Units are not visible at the call site.

- **Rate limiting (4 matches):** three named limiters (`api`, `public`, `exports`), applied to 3 export controllers and 6 account routes, and login throttling resolved through the container (`app()->make(RateLimiter::class)`), not the facade.

### Similarity pair candidates

Invented names; the pattern is kept.

| Label | Pattern |
| :--- | :--- |
| `distinct` (hard negative) | `custom-head-web:{hash}` vs `custom-head-export:{hash}` |
| `duplicate` (format only) | `api-docs::{v}` vs `api_docs:{v}`: same after normalization |
| `similar` (synthetic) | `oidc-discovery::{issuer}` vs `oidc-config::{issuer}` |

### Summary

- Low density, but **all three call styles appear together**.
- Evidence for a central key registry: repeated key strings, mixed separators, keys built in three layers, one key hidden in a protected method.
- Evidence for explicit TTL handling: six spellings, several without a visible unit.
- No Redis code, no cache tags, no explicit invalidation calls.

## Firefly III

- **rev-head:** ba26768619842a4926655f315716f1b7788933d7 (branch `main`, scanned 2026-10-14)
- **Laravel Version:** 13.35.0 · **License:** AGPL-3.0 · **Min PHP:** 8.5
- **Cache Numbers:** 29 direct `Cache::` calls in 7 files, plus 54 cache blocks in 24 files through a custom wrapper (`CacheProperties`)
- **tags:** 23, none of them are cache tags (21 Eloquent relations, 2 commented-out calls)
- **RateLimiter:** 0
- **Styles:** facade and a custom wrapper object. No `cache()` helper, no injected repository
- **No Redis code** (default `CACHE_STORE=file`, Redis only configurable)

### The cache wrapper

Most cache usage is **invisible to a search for `Cache::` or `cache(`**: only 7 of the 30 cache-using files import the facade. A class `app/Support/CacheProperties.php` wraps the facade, and 24 files use it through a `$cache` variable with four methods:

| Method | Lines |
| :--- | :--- |
| `addProperty` | 219 |
| `has` | 54 |
| `get` | 55 |
| `store` | 55 |

Each block adds a list of values ("properties": dates, ids, a currency flag, a text label), checks `has`, returns `get`, or computes and calls `store`. That gives **54 cache blocks**. The key is a hash of the property list; the wrapper's only write is `forever`. The re-scan confirms that every `$cache` is a `CacheProperties` instance: 54 `new CacheProperties()` lines match the 54 blocks one to one. The extra `store` (55 against 54) comes from blocks that store in two branches, and the extra `get` from one debug line that reads the stored value again.

### Key families

**Direct calls**

| Key family | Used by | Resolution | Notes |
| :--- | :--- | :--- | :--- |
| `ff3-config-{name}` | has, get, forget, forever | template | `ff3` is a legacy prefix; the store prefix is `firefly` |
| `preference{userId}{name}` | has, get, forget, forever, put | template (`sprintf`) | **No separator** between the two placeholders. One method forgets the key and then writes an empty value under it for 5 seconds, the only expiry in the project (purpose unclear) |
| `cer-{from}-{to}-{date}` | get, forever (x3) | template | Two builders (verified): a private key-builder method feeds the direct calls, an inline `sprintf` feeds a wrapper block. Whether both produce the same string is not visible |
| installer debounce | has, get, set (x2), clear | opaque hash | SHA-256 of command name and arguments, used as a two-minute throttle. The two `set` calls pass no TTL, which Laravel stores forever, and the same method can flush the whole store |

**Wrapper blocks (hashed key plus text label).** 52 of 54 blocks carry a literal label:

| Label style | Count | Examples |
| :--- | :--- | :--- |
| Dotted, `chart.` prefix | 30 | `chart.account.expense-accounts`, `chart.category.period.no-cat` |
| Flat, hyphenated | 18 | `account-report`, `box-balance`, `journal-meta-updated` |
| camelCase | 4 | `nextDateMatch`, `getAccountBalances` |

The two blocks without a literal label: one in `Steam` (account id and date only) and one in `ExchangeRateConverter` (label is a variable).

### Naming observations

- **Three label styles in one project** (30 dotted, 18 hyphenated, 4 camelCase). Section count in dotted labels varies.
- **The effective key cannot be read.** Looking at the store, nobody can tell which feature wrote an entry.
- **One label at three call sites:** `journal-meta-updated` in `JournalRepository` (1) and `JournalCLIRepository` (2), with the same properties. They probably share one physical key.
- **Unlabeled block:** the `Steam` block's key depends on an account id and a date only; another block with the same two values would collide.
- **Property order differs between blocks** for the same ingredients (start, end, currency flag). If the hash is order sensitive, equal inputs give different keys.
- **Same figure under two layers:** net worth is cached under `net-worth-by-accounts` (helper) and `chart.report.net-worth` (chart). Expense and income reports split the same way.
- **Exchange rates have two key builders** (verified) plus a wrapper block labelled `cer-euro-id`.
- **Missing separator** in `preference{userId}{name}`: user `1` with a name starting with digits could collide with another user whose id continues them.

### Operations and TTL

- **Direct operations (29), all located:** `forget` 7, `has` 6, `forever` 6, `get` 4, `clear` 3, `set` 2, `put` 1. By file: configuration helper 7, preference helper 8, the wrapper itself 3, exchange rate converter 4, installer 5, and one `clear` each in the debug controller and in an exchange-rate listener.
- **`clear` flushes the whole store in three places:** the installer, the debug controller and a listener for currency exchange rates (`ProcessesExchangeRates`). Which events trigger the listener is not visible in the scan.
- **Almost no expiry:** one explicit TTL (the 5 second `put` in the preference helper), two `set` calls with no TTL (forever in Laravel), and everything else is `forever`: 6 direct calls, one of which is the wrapper's single write serving all 55 `store` calls.
- **Invalidation:** `forget` (7) only for configuration and preference keys, `clear` for everything, no tags. The 54 wrapper blocks have no deletion of their own, so with `forever` writes the whole-store `clear` in the exchange-rate listener is probably the main way they are refreshed (hypothesis).
- **Three entries look write-only:** in `Chart/PiggyBankController`, `Chart/ReportController` and `Report/BudgetController` the return of the cached value is commented out while `has` and `store` remain.

### Similarity pair candidates

Invented names; the pattern is kept.

| Label | Pattern |
| :--- | :--- |
| `distinct` (hard negative) | `report.expenses-by-period` vs `report.income-by-period`: one word differs, different data |
| `distinct` (two layers) | `balance-summary` (helper) vs `chart.report.balance-summary` (chart) |
| `duplicate` (format only) | `nextDateMatch` vs `next-date-match`: same after normalization |
| `duplicate` (if verified) | `rate-{from}-{to}-{date}` from a key builder and the same string added as a wrapper property: one purpose, two physical keys |
| `similar` | `journal.meta-updated` vs `journal.field-updated` (synthetic) |

### Summary

- **Direct `Cache::` usage is only the visible part:** 29 direct calls in 7 files, 54 more blocks behind a project-specific wrapper in 24 files.
- **Keys are opaque:** hashes of runtime values plus a free-text label in three styles. The strongest case so far for a registry that defines each label, its parameters and its purpose.
- **Almost no expiry:** one 5 second `put`, everything else is `forever`. Staleness is handled by `forget` for two key families, by the key itself, and by three whole-store `clear` calls (one in an exchange-rate listener).
- **Dead and duplicate signals:** three write-only entries (if confirmed), one label at three sites, two exchange-rate key builders.
- No Redis code, no cache tags.

## Monica

- **rev-head:** 6e6ec217457e946e330ba7cd3b28107f4332c7b1 (branch `main`, scanned 2026-10-14)
- **Laravel Version:** 12.22.1 · **License:** AGPL-3.0 · **Min PHP:** 8.3
- **Cache Numbers:** 3 usage points in 3 files. The script lists a fourth file, but it is a false positive: an artisan command that matched only because it calls its own method named `cacheConfig`
- **tags:** 7, none of them are cache tags (Eloquent relations on vaults and posts)
- **RateLimiter:** 8 (4 `RateLimiter::for` definitions, 4 calls in the login request), plus one `throttle:` route middleware
- **Styles:** facade only, always through `Cache::store('array')`. No `cache()` helper, no injected repository, no custom wrapper
- **No Redis code.** Default store is `database` (`CACHE_STORE=database`); the Sail example uses `memcached`

The smallest cache footprint so far. The 20 raw matches break down as 3 `Cache::` lines, 3 `->remember(` lines (the same three calls: in two files the `Cache::store('array')` and the `->remember(` sit on different lines, in the third both are on one line, so the union is 5), 7 `->tags()` and 8 `RateLimiter::`: 5 + 7 + 8 = 20.

### Key families

| Key family | Used by | Resolution | Notes |
| :--- | :--- | :--- | :--- |
| `ImportantDateType:{vaultId}:{type}` | remember (TTL `5`) | template (interpolation) | Visible in one helper only |
| two more keys in `VaultHelper` and `UserHelper` | remember | not shown by the scan | The `remember(` is on the line after the matched `Cache::store('array')`, so the key was not captured |

All three calls are `Cache::store('array')->remember(...)`: the `array` store lives only for the current request or process, so this is per-request memoization of helper results, not a persistent cache. The persistent default store is not touched by any direct call in the scanned paths.

### Naming observations

- **PascalCase plus colons** (`ImportantDateType:...`). Every other project so far used lowercase kebab or snake case; a normalizer has to fold case.
- **The store is part of the call**, not of the key. A scanner that only reads the first argument would not know that these entries never reach the default store.
- **Rate limiter names** (`api`, `oauth2-socialite`, `login`, `two-factor`) are hyphenated lowercase and set the limiter's cache keys indirectly.

### Operations and TTL

- **Operations:** `remember` only (x3, all 3 confirmed by the `->remember(` count), always on the `array` store. `RateLimiter` calls: `hit`, `clear`, `tooManyAttempts`, `availableIn`, `for`.
- **TTL:** one value visible, a bare `5` with no unit (seconds for an integer in current Laravel).
- **No invalidation:** no `forget`, `has`, `forever`, `flush`, `clear` on a cache, no tags. The array store makes explicit invalidation unnecessary.

### Similarity pair candidates

| Label | Pattern |
| :--- | :--- |
| `duplicate` (format only) | `ImportantDateType:{v}:{t}` vs `important-date-type:{v}:{t}`: same after case and separator normalization (synthetic) |

### Summary

- **Cache usage is almost absent** (3 calls) and request-scoped. Heavy use of `RateLimiter` shows that "cache usage" also hides in framework features that write to the store.
- A scanner must handle `Cache::store('name')->remember(...)` and multi-line calls where the key sits on the next line.
- No Redis code, no tags, no wrapper, no invalidation.

## Koel

- **rev-head:** 2c932c07f502d79d405c76ee57783950810c2ccd (branch `master`, scanned 2026-10-14)
- **Laravel Version:** 13.30.0 · **License:** MIT · **Min PHP:** 8.3
- **Cache Numbers:** 59 matching lines in 29 files; 57 `Cache::` operations plus 4 calls on an injected repository. Two of the 29 files are not cache usage (the alias list in `config/app.php`, and an in-memory collection, see below). Two of the 59 lines are a wrapper's own static method `cache()` and its call, not the cache API
- **tags:** 0
- **RateLimiter:** 0 (one custom `ThrottleRequests` middleware and 4 `throttle:N,M` route uses)
- **Styles:** facade (24 imports), injected repository (2 classes), named store via `Cache::store('encyclopedia')`, container resolution `app('cache')` (one migration), an in-house `cache_key()` helper and a small static wrapper (`RadioStreamMetadata`). No `cache()` helper call
- **No Redis code.** The 1 `Redis::` match is a class alias in `config/app.php` (false positive). Default store is `file` (`CACHE_DRIVER=file`)

The densest project so far, and the first one with locks, a named store and a key-building helper. The 62 raw matches break down as 59 `Cache::` / `cache(` lines, 1 `Redis::` alias and 2 `->remember(` lines on the injected repository in `SongService` (the script now prints 4 `->remember(` lines: these 2 plus 2 `Cache::store('encyclopedia')->remember(` lines already counted among the 59).

### Key families

| Key family | Used by | Resolution | Notes |
| :--- | :--- | :--- | :--- |
| `spotify.access_token` | get, put (`59 * 60`), forget | exact, via class constant | The constant is shared with a console command, so the key is written once |
| `dropbox_access_token` | get, put, forget (x2) | exact (literal) | Literal repeated in two files |
| `license_status` | has, get, delete, put (x2) | exact (literal) | One file, five uses, **two different TTLs** (one week and 15 minutes); the status method deletes the key and then writes it |
| `episode-playable.{id}` | get, forever | template | Prefix written twice (read and write) |
| `cache_key('<label>', ...)` | set, put, pull, rememberForever | helper call | 7 labels seen (plain words with spaces), e.g. `passkey login options`, `two-factor login token`, `IP to country code`; one more call passes a variable first and the literal `cover` second, so the label is not always the first argument |
| radio stream metadata, email change request, album and artist information | put / get / forget | key-builder method per class | `cacheKey(...)`, `latestRequestCacheKey(...)`, `resolve...CacheKey(...)`. The radio class is a small static wrapper (`cache()`, `getCached()`, `cacheKey()`) called from a second file |
| `NEXT_SLOT_KEY`, `SLOT_MUTEX_KEY` | forever, get, lock | exact, class constants | A custom rate limiter for an external API built on the cache and a lock |
| keys passed to the `TriesRemember` helper | remember, put, forever | unresolved (parameter) | Helper caches a "nothing found" marker with its own TTL |
| keys of 8 `Cache::remember` calls | remember | now located, keys mostly on the next line | `AlbumService` (key from `cache_key(<directory>, 'cover')`, one day), `ApplicationInformationService`, `EncyclopediaService` (x2, inside closures), `ITunesService`, `YouTubeService`, `TicketmasterService` (x2) |

### Naming observations

- **Five ways to build a key in one project:** class constant, inline literal, template string, key-builder method per class and the `cache_key()` helper. The helper is the closest thing to a central key builder seen so far, but it only takes a free-text label, so it neither lists the labels nor catches a duplicate one.
- **Mixed styles:** dotted (`spotify.access_token`, `episode-playable.`), snake case (`dropbox_access_token`, `license_status`) and spaced words inside `cache_key()` labels.
- **Repeated literals:** `dropbox_access_token` (two files), `episode-playable.` (two sites).
- **One key, two lifetimes:** `license_status` is written with a week TTL for a known status and 15 minutes for an unknown one. Same key, different meaning of the stored value.
- **Near-identical labels:** the three `passkey ... options` labels share a prefix and differ by one word, as do `two-factor login token` and `one-time token`.
- **The named store is part of the call**, not of the key: four places use the `encyclopedia` store, the rest use the default one.

### Operations and TTL

- **Operations (57 `Cache::`):** `get` 10, `forget` 9, `remember` 8, `put` 6, `set` 5, `pull` 4, `forever` 4, `lock` 3, `rememberForever` 2, `has` 1, `delete` 1, plus 4 on the named store: `store(...)->remember` 2, `store(...)->forever` 1 and a bare `store(...)` that hands the store to a helper. Injected repository: `remember` 2, `get` 1, `put` 1. All remembers together: 8 + 2 (named store) + 2 (injected) = 12.
- **Wide operation set:** PSR-16 `set` and `delete`, `pull` (read and delete in one step), locks, `rememberForever`. A scanner limited to `get` / `put` / `forget` misses about a third of the calls.
- **`pull` for single-use values:** OAuth-style state, passkey options and login tokens are written with `set` / `put` and read once with `pull`. These keys should never be shared or reused.
- **TTL spellings (seven):** Carbon expressions (`now()->addMinutes(10)`, `addWeek()`, `addSeconds(expires_in - 60)`), bare seconds arithmetic (`59 * 60`, `60 * 5`, `60 * 10`), constants with the unit in the name (`..._IN_MINUTES`, `..._SECONDS`, `..._HOURS`), a variable (`$expiresAt`), a helper parameter (`$ttl`) and `null` meaning forever. The unit is visible in most cases, which is better than the other projects.
- **Invalidation:** explicit `forget` (9) and `delete` (1) for tokens, user requests, license status and album and artist information; no `flush` or `clear`; no tags. `forever` (4 plus the named-store call) is used for rate limiter slots and episode data.
- **Indirect users:** a custom `ThrottleRequests` middleware class and `throttle:10,1` / `throttle:60,1` routes.

### False positives and receivers

- `$this->cache->` appears in `ScannerCacheStrategy`, but the receiver is an in-memory `Collection` used as a bounded map, not a Laravel cache. Its class name also matched the wrapper search (together with `ScannerNoCacheStrategy`). Only `$this->cache` in `SongService` and `SpotifyClient` is a real injected repository.
- The `Redis` alias in `config/app.php` and `use ...Facades\Cache;` in the same file are framework configuration, not usage.
- `RadioStreamMetadata::cache(...)` (a static method named `cache`) matches the helper pattern `cache(` twice (its definition and one call). It is a wrapper method, not the Laravel helper, which is why the helper section lists it.
- The one new file in the list is a permission-package migration that resolves the cache through the container (`app('cache')`), a real usage point in a form the earlier scan did not search.

### Similarity pair candidates

| Label | Pattern |
| :--- | :--- |
| `distinct` (hard negative) | `passkey login options` vs `passkey confirmation options` vs `passkey registration options`: same prefix, three different ceremonies |
| `distinct` | `spotify.access_token` vs `dropbox_access_token`: same suffix, different services and separators |
| `duplicate` (format only) | `dropbox_access_token` vs `dropbox.access-token` (synthetic): same after normalization |
| `similar` | `two-factor login token` vs `one-time token`: both short-lived login values, both encrypted user ids (different purpose in the code, but a registry should show them next to each other) |

### Summary

- **Highest cache density so far** (about 60 usage points in 26 real files), and a wide operation set: locks, `pull`, `set` / `delete`, named store, forever.
- **A small in-house key helper already exists** (`cache_key()`), which confirms the need but shows what is missing: no list of labels, no duplicate check, no parameter types.
- **Evidence for a registry:** five key-building styles, repeated literals, one key with two lifetimes.
- **Scanner notes:** handle `Cache::store('name')->...` chains, injected `Repository` aliases (`... as Cache`), helper calls that build the key, multi-line calls, and receivers that are not caches (in-memory collections, class aliases).

## Pixelfed

- **rev-head:** bf556068ea19ee35a928227019757901035e2c32 (branch `dev`, scanned 2026-10-14)
- **Laravel Version:** 13.35.0 · **License:** AGPL-3.0 · **Min PHP:** 8.4
- **Cache Numbers:** 789 matching lines in 166 files; 790 `Cache::` operations (the updated script no longer counts the 19 calls on classes whose name ends in `Cache`)
- **Redis:** 239 direct `Redis::` calls (the only project so far with real direct Redis use)
- **tags:** 0 · **RateLimiter:** 20 lines, plus about 25 `throttle:` route middleware uses
- **Styles:** facade only. No `cache()` helper, no injected repository. A project helper `config_cache()` reads database-backed settings
- **Default store:** `redis` in `.env.example` (`REDIS_CLIENT=predis`, queue on redis, session on database)

By far the largest project (more than 10x Koel). The scan output is 1850 lines, so only counts, key shapes and patterns are recorded here. Key strings are mostly generalized because of the AGPL license; a few short identifiers remain in the similarity table.

### Operations (790)

| Operation | Count | Operation | Count |
| :--- | :--- | :--- | :--- |
| `forget` | 428 | `rememberForever` | 10 |
| `remember` | 196 | `increment` / `decrement` | 6 / 3 |
| `put` | 56 | `set` | 4 |
| `get` | 43 | `forever`, `clear` | 2, 2 |
| `has` | 21 | `lock`, `pull`, `many`, `putMany` | 1 each |
| `add` | 15 | | |

- **Invalidation dominates:** `forget` is 54% of all operations, with a ratio of about 2 `forget` calls per `remember`. 261 of the 428 sit in HTTP controllers (the biggest: the API controller with 68, admin settings 43, admin reports 28), the rest in services, jobs and commands.
- **`remember` is the second pillar:** 196 calls, now fully located (the first scan did not print them) in 84 files; the heaviest are the main API controller (13), the profile controller (9), admin reports (9) and the group, instance, trending hashtag and account services (5-8 each). `rememberForever` (10) sits in a video pipeline, HTTP signature code, instance actor code, a bouncer service, the instance service and the custom emoji service.
- **Wide operation set:** atomic `add` (15 operations; used as a guard so a warm-up job runs once), `increment`/`decrement` counters, `lock`, `many`/`putMany`. Both `clear` calls are in a repair command and a migration.
- **No tags at all**, so invalidation is key by key; there is no group clear.

### Key families

Key strings are written as literals or as concatenation, and 35 call sites build them through `self::key(...)`-style static helpers. In the listed lines (forget, remember, put, get, has, add, set, forever, pull), 113 distinct literal keys were seen, plus about 55 key-named methods and 53 key constants.

The first argument of the 196 `remember` calls: 90 string literals or interpolations, 42 variables, 36 class-constant concatenations, 16 `self::key(...)` calls, 12 whose key sits on the next line.

| Pattern | Seen | Example shape |
| :--- | :--- | :--- |
| Namespaced by area, id appended | most keys | `pf:{area}:{name}:{id}`, `profile:{counter}:{id}`, `status:{part}:{id}` |
| Fixed global key | about 25 | `admin-dash:reports:{metric}`, `pf:custom_emoji`, `profiles:private` |
| Version segment inside the key | 6 families | `api:v{N}:{name}-v{N}`, `pf:bouncer_v{N}:{name}`, `filters:v{N}:{id}` |
| Class-constant prefix plus id | 4+ classes | `self::SOME_KEY . $id` |
| Per-class `self::key(...)` builder | 35 call sites, several classes | `self::key("a_{$x}:t_{$y}")` (compact, no prefix at the call site) |
| Variable or call as the whole key | 32 `forget` calls | key built earlier, not visible at the call |

- **Same key forgotten in many places:** one instance-data response key (two versions) is `forget`-ed 27 times across controllers; several profile counters and bouncer keys 13–17 times each. If the key changes, every site must change.
- **Manual versioning:** version numbers live inside the key string (`v1`/`v2`); the old key stays in the store until it expires.

### Naming observations

- **Prefix sprawl:** `pf:` (31 literals), `api:` (15), `profile:` (12), `admin-dash:` (11), `admin:` (9), `status:` (7), plus `site:`, `filters:`, `pfc:`, `pf-api`, `pf-admin-api`, `_api:` and others, 23 distinct first segments. `pf` is clearly a project prefix, but many keys skip it.
- **Separator `:` between sections** is consistent, but words inside a section mix underscores (`following_count`), hyphens (`instance-data-response`) and camelCase (`hideFollowing`).
- **A leading underscore** on one family (`_api:`) and two near-duplicate prefixes (`pf-api`, `pf-admin-api` against `api:` and `admin-api`).
- **Redis keys outside the cache:** the direct `Redis::` calls use their own key space (for example an `email:manual` set), with no relation to the cache prefix. A registry that only knows `Cache::` keys would not see them.
- **Store prefix:** the cache config derives the prefix from the application name, and a second prefix (`pf_session`) is used for the session store.

### Direct Redis (239 calls)

- **Heaviest users:** a story index service (42 calls), a follower service (29), reblog, user filter and notification services (11–13 each), and several timeline and feed services.
- **Data types:** sorted sets dominate (`zadd` 26, `zrem` 26, `zcard` 23, `zrevrange` 15, `zrevrangebyscore` 14, `zscore` 12), then `del` 23, `expire` 11, sets (`sadd` 9, `smembers` 8, `srem` 6), `pipeline` 6, plus a few hashes, lists and `setnx`.
- **Notable:** 2 `Redis::keys` calls (a blocking scan on a large instance) and several writes without a following `expire` in the listed lines (unverified).
- **Meaning for the package:** direct Redis calls are about 23% of all Redis and `Cache::` calls here, and they are not cache (feeds, indexes, sets). A "cache registry" should state clearly whether such structures are in scope.

### TTL

Measured on the 196 `remember` calls (the only operation whose lines are fully listed):

- **Spellings:** bare integer seconds (139), Carbon helpers (18: `addDays` 8, `addHours` 4, `addMinutes` 3, `addMonths` 2, `addHour` 1), variables or class constants (about 27: `$ttl` 11, `$limitTtl` 7, `self::..._TTL` constants), 12 whose TTL sits on the next line.
- **Many distinct lifetimes:** at least 15 different literal values from 5 minutes to 120 days. The most common: 1 hour (31 calls), 12 hours (18), 1 day (16), 14 days (15), 7 days (12), 6 hours (8), 4 hours (7). Units are not visible in the bare integers.
- **Forever:** `rememberForever` 10, `forever` 2; their invalidation relies on the 428 `forget` calls.

### Rate limiting and indirect users

- **6 named limiters** (`api`, signup, code verify, code resend, account lookup, personal access token) and a manual limiter that builds its key from the client IP and a version string (`pf:apiv1.1:iar:{ip}`).
- **Two styles in routes:** named (`throttle:app-signup`) and numeric (`throttle:10,1`, `throttle:20,120`, `throttle:3,10`). One numeric style has a third argument as a limiter prefix.
- **API controller code** with per-user keys, remaining-attempts headers and `increment` shows a hand-written limiter beside the framework one.

### False positives and wrappers

- **19 calls** such as `...::whereK(`, `::dispatch(`, `::firstOrCreate(` on classes whose names end in `Cache` (a config model, warm-cache jobs) were counted by the first script. The updated script uses a word boundary and no longer counts them: matching lines dropped from 810 to 789 and files from 167 to 166.
- **Model lifecycle hooks in cache-using files (3 places):** a custom filter model with five hooks (`saving`, `updating`, `deleting`, `saved`, `deleted`) and two observers (status, follower). These are the places where manual invalidation is wired to model events.
- **Candidate wrappers (4):** a config cache model and service (database-backed settings), a fetch cache service (7 usage points), and a cached OAuth client repository. Five queue jobs whose names mention cache warm-up (the follow and feed ones) call `Cache::add` or `put` to pre-fill keys.

### Similarity pair candidates

| Label | Pattern |
| :--- | :--- |
| `distinct` (hard negative) | `profile:following_count:{id}` vs `profile:follower_count:{id}`: one word differs, opposite meaning |
| `distinct` | `pf:acct:settings:hidden-following:{id}` vs `pf:acct-trans:hideFollowing:{id}`: related topic, different layer (settings vs transformer) and different casing |
| `duplicate` (format only) | `api:v1:instance-data-response-v1` vs `api_v1_instance_data_response_v1` (synthetic) |
| `duplicate` (if verified) | `avatar:{id}` forgotten as a literal and as `"avatar:{$id}"` in different files: same key, two spellings |

### Summary

- **Largest and most invalidation-heavy** project: 428 `forget` calls, a store used as a read cache for API responses, profile counters and admin dashboards.
- **Strongest case for central key definition and lint:** 23 distinct first segments, three word-separator styles, the same key forgotten at 13–27 sites, version numbers inside key strings, 32 `forget` calls whose key is not visible at the call.
- **Scope signal:** direct `Redis::` use for feeds and indexes lives beside the cache and is not covered by `Cache::` rules.
- **Scanner notes:** `self::key(...)` builders, constant-prefix concatenation and calls whose key is a variable. Resolving keys by hand is not realistic at this size; the scanner needs template extraction.

## Akaunting

- **rev-head:** 06dfa473e0d71a5262a58f6f0759c6badf34d5d2 (branch `master`, scanned 2026-10-14)
- **Laravel Version:** 10.50.3 · **License:** Business Source License (source-available, not open source) · **Min PHP:** 8.1
- **Cache Numbers:** 81 matching lines in 15 files, of which 11 files really use the cache; 73 `Cache::` operations and 2 on an injected cache = 75. The other 8 matching lines are calls to an image library's own `cache()` method in 4 view-component files (false positives); the 6 calls on classes named `ReportCache` and 3 related files are no longer counted by the updated script
- **tags:** 0 · **Redis:** 0 · **RateLimiter:** 7 (5 named limiters, 2 manual `attempt` calls)
- **Styles:** facade, injected cache through the container (`$app['cache']`), an in-house static wrapper class (`ReportCache`), event-driven clearing (listeners named `Clear...Cache`). No `cache()` helper
- **Default store:** `file` (`CACHE_DRIVER=file`). `.env.example` also sets `MODEL_CACHE_ENABLED=false`, which suggests a third-party Eloquent model-cache package is wired in but off by default (package name not verified)

Raw matches: 81 `Cache::` / `cache(` lines + 7 `RateLimiter::` + 1 `->remember(` line on the injected cache = 89. The 81 lines split into 73 `Cache::` lines and 8 image-library lines.

### Operations (75 real)

| Operation | Count | Operation | Count |
| :--- | :--- | :--- | :--- |
| `get` | 24 | `rememberForever` | 1 |
| `put` | 22 | `forever` | 1 |
| `forget` | 13 (+1 injected) | injected `remember` | 1 |
| `remember` | 12 | | |

- **Hand-written cache-aside:** `get` (24) plus `put` (22) outnumber `remember` (12). 17 of the `get` / `put` pairs sit in one trait (`Traits/Modules`, 43 of the 81 matching lines), each doing get, check, fetch, put with an hour TTL. The same trait also has 7 proper `remember` calls with a 6 hour TTL, so both styles live side by side and `remember` would remove most of the manual code.
- **Invalidation is explicit and event-driven:** 13 `forget` calls spread over a clearing listener (4), utilities, traits and the install/update controller, wired through an event provider. No tags, no `flush`.
- **The 12 `remember` calls are now located:** 7 in the marketplace trait, 2 in the version utility (`versions`, `updates`), and one each in the category trait (`transferCategoryId.{companyId}`, the only bare-number TTL: 60 seconds), the plans trait (`plans.limits`) and a layout component (`modules.categories.{language}`).
- **The 4 view-component files are false positives:** their 8 matching lines are the image library's own `cache(...)` method (the 8 `Image::cache(` lines), not Laravel's cache.

### Key families

| Pattern | Seen | Shape |
| :--- | :--- | :--- |
| App marketplace data | 19 key builders in one trait | `apps.{section}[.{alias}].{dataKey}`; 15 sections (docs, releases, reviews, categories, vendors, widgets, ...); 7 of the 19 carry an `{alias}`, the suffix `{dataKey}` comes from a method that turns request data into a string |
| Fixed `apps.` keys | 5 | `apps.subscriptions`, `apps.suggestions`, `apps.notifications`, `apps.tips`, `apps.installed.{companyId}` |
| Unprefixed or differently prefixed keys | 6 | `versions`, `updates`, `plans.limits`, `state_company_id`, `transferCategoryId.{companyId}` (camelCase), `modules.categories.{language}` |
| Payment methods | 2 audiences | key from a method with an audience argument (`admin` / `customer`), 6 hour TTL |
| Module status | 1 | `{config key}.statuses`, key prefix read from config |
| Report cache | wrapper class | entry key from `getKey(slot, class, query, old)`, a per-report token key, and a slot key that lists variants |

- **Token-based invalidation in `ReportCache`:** a random 20-character token is stored forever per company and report, entries are stamped with that token, and reading compares the stamp with the stored token. Clearing means forgetting the token and the entries listed under the slot key. This is a hand-made substitute for cache tags.
- **Config-driven TTL:** one call uses `config('report.cache.ttl')`.

### Naming observations

- **One dotted style (`apps.section.name`) is consistent** across the marketplace keys, and the separator is `.` everywhere. Unprefixed keys (`versions`, `updates`) and snake case (`state_company_id`, `pre_sale`) break it.
- **Same words, different order:** `apps.testimonials.{dataKey}` (global) and `apps.{alias}.testimonials.{dataKey}` (per app) are two different scopes with the same words in a different order. `apps.categories.` and `apps.vendors.` come in the same pair of forms.
- **Repeated literals:** `versions` and `updates` are forgotten in two places each (an install controller and a trait); `apps.notifications` and `apps.suggestions` appear both in the trait and in the clearing listener.
- **One key, two access paths:** `versions` is written by `remember` in one method and read and written by a manual `get` / `put` pair in another method of the same utility.
- **Two category prefixes:** `apps.categories.{dataKey}` (marketplace trait) and `modules.categories.{language}` (layout component) look like the same idea under two prefixes; whether they hold the same data is not visible.
- **Two builders for one key:** `apps.installed.{companyId}` is built with a helper function in the trait and with the event's company id in the listener.
- **Global key in a multi-company app:** `state_company_id` has no company, user or session part. Its file is a model factory base class, so it may be test-only (unverified).
- **Key assignments are noisy for lexical searching:** most of the `key =` lines in the scan are translation or setting keys (`default_key`, `getDocumentSettingKey`), not cache keys.

### TTL

- **Carbon durations almost everywhere, with units visible:** `addHour()` 17, `addHours(6)` 11 and `addHour(6)` 4 (6 hours written two ways), one bare `60` (seconds), one config-driven TTL, one `$lifetime` variable, and `forever` (2). Durations come through a `Date` facade rather than `now()`.
- **Two tiers in practice:** 1 hour for remote marketplace data, 6 hours for payment methods, versions, installed apps and the state key.

### Rate limiting

5 named limiters (`api`, `oauth`, `dcr`, `import`, `email`) used through route middleware aliases; the email trait also builds two manual keys (per month and per minute) for `RateLimiter::attempt`.

### Similarity pair candidates

| Label | Pattern |
| :--- | :--- |
| `distinct` (hard negative) | `apps.testimonials.{k}` vs `apps.{alias}.testimonials.{k}`: same words, different scope |
| `distinct` | `apps.pre_sale.{k}` vs `apps.paid.{k}` vs `apps.free.{k}`: one word differs, different data |
| `duplicate` (format only) | `plans.limits` vs `plans-limits` (synthetic) |
| `duplicate` (if verified) | `apps.installed.{companyId}` built by two different code paths |

### Summary

- **Medium density (75 operations, 11 real files), concentrated:** one trait holds almost half of the usage.
- **Good example of a consistent family and of its weaknesses:** the marketplace keys follow one pattern, but each is written by hand 17 times with the same get / check / put steps and a free-form suffix.
- **Hand-made invalidation machinery:** a token plus slot index (stand-in for tags), event-driven listeners that must know every key literal, and a module-status key that is forgotten under a possibly different name (see notes).
- **Model-cache signal:** a model cache toggle in the environment file shows that real projects mix their own `Cache::` calls with a model-cache package; our registry has to coexist with one.
