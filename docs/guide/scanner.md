# Scanner

The scanner reads your PHP source without running it and lists every cache
call it finds: where it is, what it does and how much of its key can be read.

```bash
php artisan cache-contract:scan
php artisan cache-contract:scan --path=app/Billing --path=app/Reports
php artisan cache-contract:scan --json
```

The table has one row per call, followed by a summary:

```text
17 usage points in 8 files. A usage point is a place in the source, not a runtime call count.
Keys: 2 exact, 9 template, 1 opaque, 5 unresolved, 0 none
```

A usage point is one call in the code. A `Cache::get()` in a loop is still
one usage point; how often it runs is not something a scan can know.

`--path` scans only the given files or directories, relative to the project
root, instead of the configured `paths`. `exclude` still applies, so
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

Each call is reported with its file, line, class and method, and:

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
  the call.

## JSON

```json
{
    "usages": [
        {
            "file": "app/Billing/Invoice.php",
            "line": 11,
            "class": "App\\Billing\\Invoice",
            "function": "total",
            "style": "facade",
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
outside a class or function.

## Configuration

```php
// config/cache-contract.php
'scanner' => [
    'paths' => ['.'],
    'exclude' => ['vendor', 'node_modules', 'storage', 'bootstrap/cache', 'tests'],
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

## Limits

- Calls through your own wrapper classes are found inside the wrapper, not
  where the wrapper is used.
- Keys are followed through constants of the same class and variables
  assigned once, nothing further: a constant of another class, a property or
  a key-builder method stays `unresolved`.
- A variable changed only through a by-reference argument
  (`preg_match($p, $s, $key)`) or `$key++` still counts as assigned once.
- Blade views (`*.blade.php`) are not scanned.
- `exclude` matches paths from the project root: `vendor` skips only the root
  `vendor/`. A nested one, such as `packages/acme/vendor`, is still scanned
  unless it is listed.
- Facade aliases other than `Cache` and `Redis` from `config/app.php` are not
  recognised.
