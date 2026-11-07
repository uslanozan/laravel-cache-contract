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
| `read`: `get`, `has`, `remember`, `pull` (on Redis, its single script that reads the value and leaves a tombstone), model `find` and `findMany`, and reading the generation counters for them | Treated as a miss: `remember()` runs the loader, `get()` and `pull()` return the default, the model cache loads from the database. Nothing is written while Redis is down. | The client's exception is thrown (see below). |
| `write`: `put`, `add` and the read it does first, `remember`, `refresh`, model cache writes | The value is returned but not cached; `put()` and `add()` return `false`, and the model cache returns the loaded models without caching them. | Thrown. |
| `invalidate`: `forget`, `flushTag`, the delete in `pull` on stores other than Redis, model `forgetCache` and `flushCache`, and the clearing after a model change, `invalidated_by` included | `false` is returned and the old entry stays until its TTL ends; `pull()` returns the default instead of a value it could not remove. | Thrown. |

- Only errors from Redis itself follow this setting: the connection, a
  timeout, and a command Redis rejects (one your ACL forbids, `OOM`). On
  phpredis that is a `RedisException`, also for a script that fails in Redis
  in `remember()`, `add()` or `pull()`. On predis it is a
  `Predis\CommunicationException` (such as `ConnectionException`) or a
  `Predis\Response\ServerException`. Any other exception, such as one thrown
  by your loader, always propagates.
- On predis, keep the client's `exceptions` option on, as it is by default.
  With `'exceptions' => false` in the connection's `options`, predis returns
  an error object instead of throwing, so this setting never sees the error
  and nothing is reported.
- Consider `throw` for all three in your test suite, so a broken Redis setup
  fails the tests instead of passing silently.
- With `report`, an outage is not reported once per call: once per minute in
  each Octane or queue worker, once per request under PHP-FPM. See
  [Reports during an outage](#reports-during-an-outage).
- An invalid value (anything other than `report` or `throw`) throws
  `InvalidConfiguration` on the first call.

## Reports during an outage

When Redis is down, every cache call fails. To keep the log and the error
tracker readable, the package reports the first connection error, then at
most one per `report_interval` seconds:

```php
'redis_errors' => [
    // ...
    'report_interval' => 60, // seconds; 0 reports every error
],
```

The errors in between are counted, per operation. The first error reported
after the interval comes with a second report, `RedisErrorsNotReported`:

```
9 more Redis connection errors in the last 61 seconds were not reported (read: 6, write: 1, invalidate: 2).
```

Its log context has the same counts (`not_reported`, `seconds`). `invalidate`
errors matter most: each one may have left a stale entry until its TTL ends.

- **The interval holds in long-running processes**, which keep the
  package's state between requests and jobs: Octane workers, queue workers,
  `schedule:work`. Each worker has its own interval.
- **Under PHP-FPM**, the application is built again for every request and
  nothing in memory survives it, so every request starts with no interval:
  its first error is reported, and the others in the same request are only
  counted. A page with 30 failing cache calls gives 1 report instead of 30,
  but 1,000 requests a minute still give about 1,000 reports a minute; rely
  on your error tracker's grouping or rate limits for those. The counts of a
  request are lost when it ends, unless an error after the interval carries
  them.
- One interval covers reads, writes and invalidations together; only the
  counts are kept apart.
- The counts of the last interval can be lost: when Redis comes back, no
  error follows to carry them.
- Only `report` is limited. With `throw`, every error is thrown.
- The value must be a whole number of seconds, 0 or more. Anything else
  throws `InvalidConfiguration` on the first call.
- Laravel's own `throttle()` for reported exceptions keeps its counter in the
  cache, usually Redis, so it cannot limit these reports while Redis is
  down. The package keeps its counter in memory instead.

## Redis timeouts

Laravel's default Redis configuration sets no read timeout. The client then
waits for PHP's `default_socket_timeout`, 60 seconds by default, before it
gives up (phpredis also uses it to connect; predis connects within 5
seconds). With `report` the application still works during an outage, but
every cache call can wait that long first.

Set short timeouts on the connection the cache store uses, in
`config/database.php`:

```php
'redis' => [
    'cache' => [
        // host, port, password, database ...
        'timeout' => 1,              // seconds to connect
        'read_timeout' => 1,         // seconds to wait for an answer (phpredis)
        'read_write_timeout' => 1,   // the same for predis
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
