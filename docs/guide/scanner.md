# Scanner

The scanner reads your PHP source without running it and lists every cache
call it finds: where it is, what it does and how much of its key can be read.

```bash
php artisan cache-contract:scan
php artisan cache-contract:scan --path=app/Billing --path=app/Reports
php artisan cache-contract:scan --json
```

The table has one row per call, followed by a summary. Its first column is
the location as `path:line` (`app/Billing/Invoice.php:11`), which VS Code and
PhpStorm terminals open at the line on click.

```text
17 usage points in 8 files. A usage point is a place in the source, not a runtime call count.
Categories: 9 cache, 2 redis, 2 indirect, 4 wrapper
Keys: 2 exact, 9 template, 1 opaque, 5 unresolved, 0 none
Wrapper calls: 3 read, 1 write
```

The last line appears only when [wrapper](#wrappers) calls are found. They
are included in the usage points but not in the `Keys:` line: the cache calls
inside the wrapper already count its keys.

A usage point is one call in the code. A `Cache::get()` in a loop is still
one usage point; how often it runs is not something a scan can know.

`--path` scans only the given files or directories, relative to the project
root or absolute, instead of the configured `paths`. `exclude` still applies, so
`--path=.` never scans `vendor/`. Repeat it to scan several.

The command exits with a non-zero code when a path could not be scanned: a
`--path` that does not exist or a file that does not parse. Those are listed
under the table, and the calls in every other file are still reported.

## What it finds

| Style | Example |
| :--- | :--- |
| Facade, also under the global alias or another name | `Cache::get('k')`, `\Cache::get('k')`, `use …\Cache as AppCache` |
| Facade through a store or tags | `Cache::store('redis')->put(…)`, `Cache::tags([…])->flush()` |
| Helper | `cache('k')`, `cache(['k' => $v], 60)`, `cache()->remember(…)` |
| Injected repository | a property or parameter typed `Illuminate\Contracts\Cache\Repository`, `Factory`, `Illuminate\Cache\Repository` or `CacheManager` |
| Container | `app('cache')`, `resolve('cache.store')`, `$this->app['cache']` |
| Direct Redis | `Redis::get(…)`, `Redis::connection()->del(…)` |
| Wrapper | a call to a listed method of one of your [wrapper classes](#wrappers) |
| Rate limiter | `RateLimiter::hit('login:'.$id)`, `RateLimiter::attempt(…)`, `\RateLimiter::clear(…)` |
| Throttle middleware | any `'throttle:…'` string: `->middleware('throttle:60,1')`, `['auth', 'throttle:api']`, a middleware group in `app/Http/Kernel.php` |

Each call is reported with its file, line, class and method, and:

- **category**, which decides what [lint](#categories) does with it:
  `cache`, `redis`, `indirect` or `wrapper`
- **operation:** `read`, `write`, `remember` (a read, plus a write on a miss),
  `forget`, or `other` (locks, Redis data structures)
- **key**, as one of:

| Kind | Source | Reported as |
| :--- | :--- | :--- |
| `exact` | `'settings'`, `self::TOKEN_KEY`, `$key = 'settings'` | `settings` |
| `template` | `'feed:'.$id`, `"user:{$user->id}"`, `sprintf('pref%d%s', $id, $name)`, `self::PREFIX.$id` | `feed:{id}`, `user:{id}`, `pref{id}{name}`, `profile:{id}` |
| `opaque` | only a hash: `md5($x)`, `hash('sha256', $x)` | the expression |
| `unresolved` | no literal part: a parameter `$key`, a key builder `self::key($id)`, `cache_key('label')` | the expression |
| `none` | no key: `flush()` | - |

An `opaque` key needs a new design before it can move into a definition. An
`unresolved` key is only known at runtime; it is always listed, with the
expression, so you can find it.

How keys are read:

- A **template** keeps its literal parts and names each runtime part after
  the variable, property or method it comes from: `$user->id` and
  `$user->id()` are both `{id}`. A part with no name is `{?}`.
- A **constant of the same class** (`self::`, `static::` or the class name)
  is replaced by its value.
- A **variable assigned once** in the same function is replaced by its value:
  `$key = 'api-docs::'.$version; Cache::get($key)` is `api-docs::{version}`.
  A variable written twice (`$key .= …`, a second assignment, a `foreach`)
  stays `unresolved`, because the scanner cannot tell which value reaches
  the call. As in PHP, an arrow function sees the variables around it and a
  closure only those in its `use (…)` list; a parameter of the same name
  hides the outer variable.

## Categories

| Category | Styles | What it is | Lint |
| :--- | :--- | :--- | :--- |
| `cache` | facade, helper, repository, container | the cache API, which the contract replaces | an error outside the allowed paths |
| `redis` | redis | Redis used directly, often for data structures rather than a cache | off unless turned on |
| `indirect` | rate-limiter, throttle | the framework keeping its own keys in the cache store | never |
| `wrapper` | wrapper | a call to one of your wrapper classes | never: the cache call inside the wrapper is flagged |

`cache-contract:lint` is not released yet; the last column is how it will
treat each category. Indirect usage is listed so you can see how much of the
store the framework uses, but there is nothing to move into a definition. A
`throttle:` string is reported with the key kind `unresolved`, since the
limiter builds its key at runtime from the route and the user or IP; a
`RateLimiter::` call has its key read like any other.

Session, queue and other drivers that can be set to `cache` or `redis` are
not reported: whether they touch the cache store depends on your
configuration, not on the code.

## JSON

```json
{
    "usages": [
        {
            "file": "app/Billing/Invoice.php",
            "line": 11,
            "class": "App\\Billing\\Invoice",
            "function": "total",
            "category": "cache",
            "style": "facade",
            "wrapper": null,
            "method": "remember",
            "operation": "remember",
            "key_kind": "template",
            "key": "invoice:{id}"
        }
    ],
    "errors": {
        "app/Broken.php": "Syntax error, unexpected EOF on line 1"
    }
}
```

Files are relative to the project root. `class` and `function` are `null`
outside a class or function. `wrapper` is the wrapper class of a `wrapper`
call and `null` for every other style.

## Configuration

```php
// config/cache-contract.php
'scanner' => [
    'paths' => ['.'],
    'exclude' => ['vendor', 'node_modules', 'storage', 'bootstrap/cache', 'tests'],
    'wrappers' => [],
],
```

Paths are relative to the project root, wherever the command is run from.
`exclude` skips those files and
directories even inside `paths`. Keep `storage` excluded: it holds compiled
Blade views, which would count every call in a template a second time.

`tests` is excluded because test code sets up and fakes the cache
(`Cache::shouldReceive('get')`, `Cache::put('user:1', $user)` in a setUp).
Those calls are not your application's cache usage and would inflate the
inventory. Remove `tests` from the list to scan them too.

A file that does not parse is listed as an error and the scan goes on.

## Wrappers

A class of your own that calls the cache inside hides its callers: they never
mention `Cache::`, so the scan finds one file where the cache is used in
fifty. Declare such a class with the methods that touch the cache and what
each does (`read`, `write`, `remember`, `forget` or `other`):

```php
'scanner' => [
    'wrappers' => [
        App\Support\CacheProperties::class => ['has' => 'read', 'get' => 'read', 'store' => 'write'],
    ],
],
```

Every call to a listed method is then a usage point with the style `wrapper`,
shown as `wrapper (CacheProperties)` in the table. Other methods, such as
`addProperty()`, are not cache calls and are not listed. A wrapper is found
as:

- an instance created with `new`: `(new CacheProperties)->get()`, or a
  variable assigned one once: `$cache = new CacheProperties; $cache->get()`
- a property or parameter typed as the wrapper (also `?CacheProperties` or
  `CacheProperties|null`), as for an injected repository
- a static call: `CacheProperties::flushAll()`

The key of a wrapper call is always `unresolved` with no expression: the
wrapper builds it inside, and the scanner cannot tell which argument, if any,
feeds it. The `Cache::` calls inside the wrapper are still listed with their
own keys.

Nothing is detected automatically, since not every class named `*Cache*` is a
wrapper. An unknown operation stops the command with an `InvalidConfiguration`
error. Wrapper calls are listed for sizing a migration, not for fixing one by
one: moving the wrapper onto the contract moves every caller with it.

## Limits

- Calls through a wrapper class are found where they are made only when the
  class is listed in `wrappers`. A wrapper obtained from a factory method
  (`CacheProperties::make()->get()`), held in a variable written more than
  once, or typed only in a docblock is not found; its cache calls are still
  found inside the wrapper.
- Keys are followed through constants of the same class and variables
  assigned once, nothing further: a constant of another class, a property or
  a key-builder method stays `unresolved`.
- A variable changed only through a by-reference argument
  (`preg_match($p, $s, $key)`) or `$key++` still counts as assigned once.
- Literal braces in a key read as a placeholder: `self::PREFIX.'{tenant}'`
  is reported as a `template`, and in `{tenant:42}:stats:{id}` the two
  cannot be told apart. This matters for Redis Cluster hash tags.
- Blade views (`*.blade.php`) are not scanned.
- `exclude` matches paths from the project root: `vendor` skips only the root
  `vendor/`. A nested one, such as `packages/acme/vendor`, is still scanned
  unless it is listed.
- Facade aliases other than `Cache`, `Redis` and `RateLimiter` from
  `config/app.php` are not recognised.
- The rate limiter is found through its facade only. An injected
  `Illuminate\Cache\RateLimiter`, `ThrottleRequests::using(…)`, `->throttleApi()`
  in `bootstrap/app.php`, a bare `'throttle'` and a built string such as
  `'throttle:'.$limit` are not found.
- A cache key that is a literal starting with `throttle:`, such as
  `Cache::get('throttle:login')`, is counted twice: as the cache call and as
  throttle middleware.
