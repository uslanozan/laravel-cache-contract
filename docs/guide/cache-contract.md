# Runtime API

The `CacheContract` facade reads and writes the entries you defined in
`config/cache-contract.php`. Every call takes a definition ID and its
parameters; the key and the TTL come from the definition, so they are never
typed by hand.

```php
use Uslanozan\CacheContract\Facades\CacheContract;

$profile = CacheContract::remember('user.profile', ['user_id' => 42], fn () => $this->loadProfile(42));
```

An unknown ID throws `UnknownDefinition`, and a missing, extra or wrongly
typed parameter throws `InvalidParameters`, before Redis is touched. See
[Definitions](definitions.md#parameters) for the parameter rules.

## Methods

| Method | Returns | What it does |
| :--- | :--- | :--- |
| `remember($id, $params, $loader)` | the value | The cached value; on a miss, runs `$loader`, caches its result and returns it. |
| `get($id, $params = [], $default = null)` | the value or `$default` | The cached value, or `$default` (a value or a closure) on a miss. |
| `has($id, $params = [])` | `bool` | Whether a value is cached. |
| `put($id, $params, $value)` | `bool` | Caches the value for the definition's TTL; `false` if it could not be written. |
| `refresh($id, $params, $loader)` | the value | Runs `$loader` and caches its result, even when a value is cached. |
| `forget($id, $params = [])` | `bool` | Removes the entry; `false` if there was nothing to remove or Redis could not be reached. |
| `key($id, $params = [])` | `string` | The key the other methods would use. For a definition with `tags` or `invalidated_by` it reads the generation counters from Redis, and a Redis error is thrown. |
| `withContext($context)` | a new instance | Adds context values to every key (see below). |

```php
CacheContract::put('user.profile', ['user_id' => 42], $profile);

if (CacheContract::has('user.profile', ['user_id' => 42])) {
    // ...
}

CacheContract::forget('user.profile', ['user_id' => 42]);

CacheContract::key('user.profile', ['user_id' => 42]);
// "cc:user:profile:v1:user_id=42"
```

A definition with [`invalidated_by`](definitions.md#invalidated_by) or
`tags` has keys that end with the current value of its generation counters,
such as `cc:member:active_list:v1:organization_id=1:~g8123`. Every method
reads them first, in one Redis command.

## Cached `null`, `false` and `0`

Laravel's cache cannot tell a cached `null` from a miss, so `Cache::remember()`
runs the loader again every time it returns `null`. This package stores every
value in an envelope, so a cached `null`, `false`, `0` or `''` is a hit
(ADR 0007):

```php
CacheContract::remember('user.profile', ['user_id' => 404], fn () => null);
// The loader ran once; the next call returns the cached null without running it.

CacheContract::get('user.profile', ['user_id' => 404], 'fallback');   // null: a cached value
CacheContract::has('user.profile', ['user_id' => 404]);               // true
```

`get()` returns `$default` only on a miss, so use `has()` when you need to
tell a cached `null` from a missing entry.

Numbers and booleans also come back with their type: an `int` stays an `int`,
not the string Redis would return.

## Context: tenant, locale and other request values

Values that change the result but are not parameters of the definition, such
as the tenant or the locale, go into the context. They are added to the key,
so two tenants never share an entry:

```php
$cache = CacheContract::withContext(['tenant_id' => 7, 'locale' => 'tr']);

$cache->remember('user.active_list', ['role' => 'admin'], fn () => $this->load());
// key: cc:user:active_list:v1:role=admin:@locale=tr:@tenant_id=7
```

- Context names are snake_case, and values are strings, ints, floats or bools.
- Context values are written into the key as they are. Never put a token, a
  password or personal data in the context; use a
  [`secret` parameter](definitions.md#secret-parameters) instead.
- `withContext()` returns a new instance and never changes the facade's
  shared instance, so a tenant set in one request or job cannot leak into the
  next one (Octane, queue workers). Pass the instance on, or call
  `withContext()` where you need it; calling it again adds to the existing
  context.

## Redis errors

When Redis cannot be reached, the default is to report the error and carry on
without the cache: `remember()` runs the loader, `put()` returns `false`.
This is set per operation with `redis_errors`; see
[Production](production.md#when-redis-is-down).

## Errors

| Exception | When |
| :--- | :--- |
| `UnknownDefinition` | The ID is not in `config/cache-contract.php`. |
| `InvalidParameters` | A parameter is missing, unexpected or of the wrong type, or a context value is invalid. |
| `InvalidDefinitions` | The config has errors; thrown on the first call, with every problem listed. |
| `InvalidConfiguration` | A setting such as `redis_errors` has an invalid value. |
| `InvalidKeyFormat` | `key_format` is invalid, or it uses `{prefix}` while `prefix` is empty (e.g. `CACHE_CONTRACT_PREFIX=`). |

All of them implement `Uslanozan\CacheContract\Exceptions\CacheContractException`.
