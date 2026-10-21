# AST scanner prototype

LRVL-18 asked for the smallest scanner that parses PHP into a syntax tree with
nikic/php-parser and finds cache calls, to see how many of the patterns from
the public scan ([cache-usage-in-the-wild.md](cache-usage-in-the-wild.md)) it
can catch. The prototype is `Uslanozan\CacheContract\Scanner\Scanner`
(`@internal`); the fixtures in `tests/Fixtures/Scanner/` rebuild the patterns
and `tests/Unit/Scanner/ScannerTest.php` pins the result.

**Result:** 7 of the 8 call styles in the six repositories are found directly,
and none of the 5 false positive kinds the text search hit is reported. The
gap is project wrappers: their own `Cache::` calls are found, their call sites
are not. That is the largest blind spot (Firefly III: 54 blocks behind one
wrapper against 29 direct calls).

## What it reports

One `Usage` per call: file, line, style, method, operation (read, write,
remember, forget, other) and the key as **exact** (`'settings'`), **template**
(`"user:{$id}"`, `'feed:'.$id`) or **unresolved** (`$key`, `self::KEY`, a
method call). Keyless calls such as `flush()` have kind **none**.

## Call styles

| Style in the public scan | Where | Found |
| :--- | :--- | :--- |
| `Cache::` facade, also the global alias `\Cache::` | all six | yes |
| Named store or tags: `Cache::store('x')->…`, `Cache::tags([…])->…` | Monica, Koel | yes |
| `cache()` helper: `cache('k')`, `cache([…], ttl)`, `cache()->…` | BookStack | yes |
| Injected repository (typed property, promoted or assigned in the constructor, typed parameter) | BookStack, Koel | yes |
| Container: `app('cache')`, `resolve('cache.store')` | Koel | yes |
| Container as array: `$app['cache']`, `$this->app['cache']` | Akaunting | yes |
| Direct `Redis::` calls, also `Redis::connection()->…` (own style) | Pixelfed | yes |
| Project wrapper: `CacheProperties`, `RadioStreamMetadata`, `ReportCache`, a fetch cache service | Firefly III, Koel, Akaunting, Pixelfed | inner calls only |

## False positives avoided

All come from the public scan, all are in the `NotCacheUsage` fixture or were
checked by hand:

- Classes whose name ends in `Cache` (`WarmFeedCache::dispatch()`, Pixelfed's
  19 calls): names are resolved, so only the real facade counts.
- A static method named `cache()` (Koel): a static call, not the helper.
- `$this->cache` that is a `Collection` (Koel): only cache-typed properties count.
- The alias list in `config/app.php` and `use` imports: no call, nothing reported.
- The Eloquent `tags()` relation (BookStack): the receiver is not a cache.

## Not found yet

Checked with a probe; each one is a candidate for phase 5:

- **Wrapper call sites.** Needs a configurable list of wrapper classes and
  methods with their operation. Highest value: it is the Firefly III case.
- **Keys from constants and key builders** are reported as unresolved
  (`self::KEY`, Koel's `cacheKey(...)` methods, Koel's `cache_key()` helper).
  Constants of the same class could be resolved; builder methods probably not.
- **Variables assigned from a cache**: `$store = Cache::store('x'); $store->get(…)`.
- **Other container forms**: `app(Repository::class)`, `app()->make('cache')`.
- **Docblock-only types**: `/** @var Repository */ private $cache;`.
- `many()` / `putMany()` and `cache([...])` report only their first key.
- TTL is not extracted (the public scan records TTL spellings).

## Limits of the prototype

- It scans code or one file; walking a directory is not built.
- A file that does not parse throws; a repository scan should collect the error
  per file and go on.
- It has only run on the fixtures, not on the six repositories, so the numbers
  above are call styles, not usage counts.

## Update: LRVL-19

All three limits above are closed. `Scanner::scanPaths()` walks directories
(paths and excludes from the `scanner` config, `vendor/` excluded by default),
lists files that do not parse and goes on, and skips Blade views. Every
`CacheUsage` now has the class and method it is in. Two key kinds changed:
`sprintf()` with a literal format is a **template**, and a key that is only a
hash (`md5()`, `sha1()`, `crc32()`, `hash()`, `hash_hmac()`) is **opaque**,
the Firefly III `CacheProperties` case.

Wrapper call sites are not in it: their scope is decided in ADR 0011
(LRVL-21) first.

### BookStack

Run on the revision from the public scan (`241a701`), `tests/` excluded:

| | Public scan (by hand) | Scanner |
| :--- | ---: | ---: |
| Usages | 18 in 9 files | 17 in 8 files |
| Facade | 9 | 8 |
| Helper | 6 | 6 |
| Injected repository | 3 | 3 |
| False positives | 6 `tags()` (Eloquent), 1 `$store->` | 0 |

The one difference is `Cache::store(null)` in `OidcService`, passed to
`OidcProviderSettings` as an argument. It does nothing by itself; its use there
(`remember`) is already one of the three repository usages, so the manual count
had it twice.

Operations: 8 write, 6 read, 3 remember. Keys: 2 exact, 2 template,
**13 unresolved**. BookStack builds most keys into a variable first
(`$cacheKey = 'api-docs::'.$version;`), which the public scan resolved by
reading the code. Following a local variable assigned once from a literal is
the next gain for key resolution.
