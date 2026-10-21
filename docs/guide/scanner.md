# Scanner

> This page is growing with the package. The scanner finds cache calls today;
> the `cache-contract:scan` command that shows them comes later in phase 5.

The scanner reads your PHP source without running it and lists every cache
call it finds: where it is, what it does and how much of its key can be read.

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
- **key:** `exact` (`'settings'`), `template` (`"user:{$id}"`, `'feed:'.$id`,
  `sprintf('pref%d', $id)`), `opaque` (only a hash, such as `md5($x)`: it
  needs a new design before it can move into a definition), `unresolved`
  (`$key`, `self::KEY`, a method call) or `none` (`flush()`)

## Configuration

```php
// config/cache-contract.php
'scanner' => [
    'paths' => ['.'],
    'exclude' => ['vendor', 'node_modules', 'storage', 'bootstrap/cache', 'tests'],
],
```

Paths are relative to the project root. `exclude` skips those files and
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
- A key built into a variable first (`$key = 'user:'.$id; Cache::get($key)`)
  is `unresolved`.
- Blade views (`*.blade.php`) are not scanned.
- `exclude` matches paths from the project root: `vendor` skips only the root
  `vendor/`. A nested one, such as `packages/acme/vendor`, is still scanned
  unless it is listed.
- Facade aliases other than `Cache` and `Redis` from `config/app.php` are not
  recognised.
