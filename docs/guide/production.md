# Production

## When Redis is down

By default the application keeps working when Redis cannot be reached: the
error is passed to Laravel's `report()` and the call carries on without the
cache. This is set per kind of operation in `config/cache-contract.php`
(ADR 0007):

```php
'redis_errors' => [
    'read' => 'report',
    'write' => 'report',
    'invalidate' => 'report',
],
```

| Operation | With `report` | With `throw` |
| :--- | :--- | :--- |
| `read`: `get`, `has`, `remember`, model `find` and `findMany` | Treated as a miss: `remember()` runs the loader, `get()` returns the default, the model cache loads from the database. Nothing is written while Redis is down. | The `RedisException` is thrown. |
| `write`: `put`, `remember`, `refresh`, model cache writes | The value is returned but not cached; `put()` returns `false`, and the model cache returns the loaded models without caching them. | Thrown. |
| `invalidate`: `forget` | `false` is returned; the old entry stays until its TTL ends. | Thrown. |

- Only Redis connection errors follow this setting. Any other exception, such
  as one thrown by your loader, always propagates.
- Consider `throw` for all three in your test suite, so a broken Redis setup
  fails the tests instead of passing silently.
- With `report`, every failing call reports an error, so a Redis outage
  produces many reports. Make sure your error tracker can take the volume;
  limiting them during an outage is planned (LRVL-52).
- An invalid value (anything other than `report` or `throw`) throws
  `InvalidConfiguration` on the first call.

## Redis timeouts

Laravel's default Redis configuration sets no timeout. phpredis then waits
for PHP's `default_socket_timeout`, 60 seconds by default, before it gives
up. With `report` the application still works during an outage, but every
cache call can wait that long first.

Set short timeouts on the connection the cache store uses, in
`config/database.php`:

```php
'redis' => [
    'cache' => [
        // host, port, password, database ...
        'timeout' => 1,        // seconds to connect
        'read_timeout' => 1,   // seconds to wait for an answer
    ],
],
```

Choose values that fit your network; a healthy Redis answers in
milliseconds.

## Octane and queue workers

Long-running processes reuse the same objects across requests and jobs. The
package keeps no request state in them:

- The `CacheContract` facade's instance is immutable. `withContext()`
  returns a new instance, so a tenant or locale set in one request cannot be
  carried into the next.
- The same holds for `ModelCache::for(...)`: every `withContext()` and
  `withTrashed()` call returns a new object.
- Definitions are loaded once per process. After a deploy that changes
  `config/cache-contract.php`, restart the workers (`php artisan
  octane:reload`, `php artisan queue:restart`), as for any other config
  change.

## Keys and Redis

- Each application gets its own key prefix (`prefix`, default `cc`). Give
  applications that share a Redis different prefixes.
- The package never calls `Cache::flush()`, which would empty the whole
  Redis database, including other applications' data. To clear one
  definition's entries after a structure change, bump its `version`; the old
  keys are no longer read and expire through their TTL.
- Keys and model entries are readable in Redis. Protect Redis like your
  database, and keep secrets out of keys (see
  [secret parameters](definitions.md#secret-parameters)).
