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
| `remember($id, $params, $loader)` | the value | The cached value; on a miss, runs `$loader`, caches its result and returns it. The result is not cached if the entry was removed while the loader ran (see [Removals](#removals-leave-a-tombstone)). |
| `get($id, $params = [], $default = null)` | the value or `$default` | The cached value, or `$default` (a value or a closure) on a miss. |
| `has($id, $params = [])` | `bool` | Whether a value is cached. |
| `put($id, $params, $value)` | `bool` | Caches the value for the definition's TTL; `false` if it could not be written. |
| `add($id, $params, $value)` | `bool` | Caches the value only when nothing is cached yet (on Redis, a tombstone counts as nothing); `true` when this call wrote it. Atomic on Redis. |
| `pull($id, $params = [], $default = null)` | the value or `$default` | The cached value, removed in the same call; `$default` on a miss. On Redis, of two concurrent calls only one gets the value. |
| `refresh($id, $params, $loader)` | the value | Runs `$loader` and caches its result, even when a value is cached. |
| `forget($id, $params = [])` | `bool` | Removes the entry by leaving a short-lived tombstone; `true` whether or not something was cached, `false` only if Redis could not be reached. |
| `key($id, $params = [])` | `string` | The key the other methods would use. For a definition with `tags` or `invalidated_by` it reads the generation counters from Redis, and a Redis error is thrown. |
| `flushTag($tag)` | `bool` | Invalidates every entry of every definition with this [tag](definitions.md#tags), for every parameter and context; `false` if Redis could not be reached. |
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

### `add` and `pull`

`add()` writes only when the entry does not exist, which makes it a simple
"run once" guard:

```php
if (CacheContract::add('report.export_lock', ['report_id' => 9], true)) {
    // only one worker gets here until the entry expires (the definition's TTL)
}
```

On Redis the check and the write are one script, so of two concurrent calls
exactly one returns `true`. A cached `null` counts as cached, and so does a
value another part of your code wrote under the same key: `add()` returns
`false` until it expires (never, if it was written without a TTL), although
`get()` treats it as a miss and `put()` or `remember()` would overwrite it.
On Redis, a tombstone left by `forget()` or `pull()` counts as nothing; on
other stores it blocks `add()` until it expires.

Use a definition **without** `tags` or `invalidated_by` for such a guard. With
them, the generation counters are read before the write, and a change in
between gives two workers different keys, so both can get `true`. A guard
that a model change resets is rarely what you want anyway.

`pull()` returns the entry and removes it, for values that may be used only
once, such as a login code:

```php
$code = CacheContract::pull('auth.login_code', ['user_id' => 42]);
```

On Redis, one script reads the code and leaves a
[tombstone](#removals-leave-a-tombstone) in its place. If two requests pull
the same code at the same moment, one gets it and the other gets the
default, and a new code written in between is never removed by mistake.
Laravel's own `Cache::pull()` reads and deletes in two steps, so both
requests would get the code. If Redis cannot be reached, `pull()` returns
the default.

On other stores (`array`, `database`, `file`…) `pull()` reads, then deletes,
and hands out the value only when its delete removed an entry. That is not
atomic, and it leaves no tombstone: use Redis when a value must be used
once.

A definition with [`invalidated_by`](definitions.md#invalidated_by) or
`tags` has keys that end with the current value of its generation counters,
such as `cc:member:active_list:v1:organization_id=1:~g8123`. Every method
reads them first, in one Redis command.

### Removals leave a tombstone

Without care, a `remember()` whose loader started before a `forget()` would
write its value after it: the entry is removed, then cached again with the
data from before the change, until its TTL ends. So `forget()` and `pull()`
do not delete the entry: they leave a **tombstone**, a marker with a random
token that every read treats as a miss, for `tombstone_ttl` seconds
(ADR 0012):

```php
'tombstone_ttl' => 10, // seconds; should outlast your slowest loader
```

`remember()` caches its loader's result only if the key still holds what its
read found: nothing, or the same tombstone. On Redis that check and the
write are one script.

- A loader that ran across a removal returns its value, but the value is not
  cached; the next call loads again.
- A loader that started after the removal caches at once: it read the
  tombstone, and the tombstone is still there.
- `forget()` returns `true` whenever it wrote the tombstone, whether or not a
  value was cached, and `false` only when Redis could not be reached.
- `put()` and `refresh()` write whatever the key holds: the caller asked for
  that value. A `refresh()` whose loader runs across a `forget()` can still
  write old data.
- A value other code wrote under the key is overwritten by `remember()`, so
  such a key is repaired.
- `flushTag()` and `invalidated_by` are not affected: they change the key,
  not the entry.
- The [model cache](model-cache.md#a-read-during-a-change) works the same
  way for `find()`, `findMany()` and the clearing after a model change.

**On other stores** (`array`, `database`, `file`…) the check is a plain
`add()`: a tombstone blocks `remember()`'s write and `add()` until it
expires. After a `forget()`, `remember()` runs its loader on every call for
`tombstone_ttl` seconds. Keep that in mind in tests on the `array` store:
use `put()` to fill an entry right after a `forget()`.

**Cache events:** on Redis, `remember()`'s write, `add()` and `pull()` run in
a script, so they fire no `KeyWritten` or `KeyForgotten`; `pull()` fires no
`CacheHit` or `CacheMissed` either. `forget()` fires `KeyWritten` for the
tombstone instead of `KeyForgotten`, and a read that finds a tombstone fires
`CacheHit`, although the package treats it as a miss. Tools that listen to
these events, such as Telescope or Pulse, show the calls that way.

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

## Inside a database transaction

`CacheContract` does not know which database your loader reads, so it caches
whatever the loader returns. Inside a transaction that can be data the
transaction has not committed yet; if it rolls back, the cached value stays
until its TTL ends. Call `remember()`, `put()` and `refresh()` before the
transaction starts or after it commits:

```php
DB::transaction(function () {
    // ... change the data
});

$list = CacheContract::remember('member.active_list', ['organization_id' => 1], fn () => $this->load(1));
```

The [model cache](model-cache.md#inside-a-transaction) does this itself: it
writes nothing while a transaction is open on the model's connection.

## Redis errors

When Redis cannot be reached, the default is to report the error and carry on
without the cache: `remember()` runs the loader, `put()` returns `false`.
This is set per operation with `redis_errors`; see
[Production](production.md#when-redis-is-down).

## No counters

There is no `increment()` or `decrement()`. A counter is the data itself, not
a copy that can be loaded again, so it does not fit the mandatory TTL, the
[envelope](#cached-null-false-and-0) or the
["carry on without Redis"](production.md#when-redis-is-down) default
(ADR 0013). Keep counted data where it belongs:

- **Rate limits and attempt counts:** Laravel's `RateLimiter`
  (`RateLimiter::hit()`, `tooManyAttempts()`). It throws when Redis fails, so
  a failure does not go unnoticed.
- **Counts that must not be lost:** the database (`$post->increment('views')`).
  An `increment()` or `decrement()` on a model fires no `saved` event, so it
  clears neither that model's [cache](model-cache.md) nor definitions with
  the model in `invalidated_by`. Call `$post->forgetCache()` after it for the
  model cache; for those definitions, call `flushTag()` if they have a tag,
  or keep their TTL short.
- **High-volume counters:** Redis directly. `INCR` creates a missing key
  without a TTL, so set the expiry yourself, for example with `EXPIRE` in the
  same `MULTI`; otherwise the keys never expire in a shared Redis.

## Errors

| Exception | When |
| :--- | :--- |
| `UnknownDefinition` | The ID is not in `config/cache-contract.php`. |
| `UnknownTag` | `flushTag()` got a tag that is not declared under `tags`. |
| `InvalidParameters` | A parameter is missing, unexpected or of the wrong type, or a context value is invalid. |
| `InvalidDefinitions` | The config has errors; thrown on the first call, with every problem listed. |
| `InvalidConfiguration` | A setting such as `redis_errors` has an invalid value. |
| `InvalidKeyFormat` | `key_format` is invalid, or it uses `{prefix}` while `prefix` is empty (e.g. `CACHE_CONTRACT_PREFIX=`). |

All of them implement `Uslanozan\CacheContract\Exceptions\CacheContractException`.
