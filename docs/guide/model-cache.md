# Model cache

Cache Eloquent models by primary key without writing a cache class per model
or a definition in the config.

```php
use Uslanozan\CacheContract\Facades\ModelCache;

$user  = ModelCache::for(User::class)->find(5);           // ?User
$users = ModelCache::for(User::class)->findMany([3, 1]);  // Eloquent collection, in this order
```

The first call loads from the database and caches the result; later calls
read it from the cache until its TTL ends.

> **Not yet included:** changes to a model do not clear its cache entry yet.
> An entry stays until its TTL ends, so choose a TTL you can live with.
> Automatic invalidation (`forgetCache()`, `flushCache()` and clearing on
> save) comes in a later release.

## The HasCache trait

Add the trait to a model for shortcuts on the model itself. They call the
same `ModelCache`, so they share its entries and its rules:

```php
use Uslanozan\CacheContract\Eloquent\Concerns\HasCache;

class User extends Model
{
    use HasCache;
}

User::cached(5);              // ModelCache::for(User::class)->find(5)
User::cachedMany([3, 1, 2]);  // ModelCache::for(User::class)->findMany([3, 1, 2])
User::modelCache();           // ModelCache::for(User::class)
```

Use `modelCache()` when a model needs a context or soft-deleted rows:

```php
Project::modelCache()->withContext(['tenant_id' => $tenant->id])->find(5);
Post::modelCache()->withTrashed()->find(3);
```

`cached()` and `cachedMany()` take no context, so on a model whose global
scopes need one (see [Tenants and global scopes](#tenants-and-global-scopes))
they throw `ModelNotCachable`, exactly like `find()` without a context.

## find()

```php
ModelCache::for(User::class)->find(5);
ModelCache::for(User::class)->find('5');   // same entry as find(5)
```

- Returns the model, or `null` when there is no such row. That `null` is
  cached too, so a missing ID does not hit the database on every call.
- For an integer key, a string such as `'5'` (from a route or a query string)
  is accepted. Only a plain integer string is converted: `'05'`, `' 5'`,
  `'+5'` and `'5abc'` throw `InvalidParameters`.
- String keys (UUIDs) work as they are.

## findMany()

```php
$users = ModelCache::for(User::class)->findMany([3, 1, 2]);
$users->load('company');   // one query for all of them
```

- Cached models are read in one go, the rest are loaded with one query and
  cached in one go. 100 models take one Redis read, one query and one
  Redis write the first time, and one Redis read afterwards.
- The result is an Eloquent collection **in the order you asked for**.
- An ID with no row is left out of the result (and cached as `null`).
  A repeated ID is returned once; `'1'` and `1` are the same ID.
- Every ID is checked first: one invalid ID throws `InvalidParameters`
  before anything is read or written.
- Very long lists are split into chunks (see `chunk_size` below).

## Relations

Only the model's own columns are cached, never its loaded relations, so a
cached model never carries stale related data. Load relations after reading,
for many models at once with `->load()` on the collection.

## Tenants and global scopes

A global scope (for example one that adds `where tenant_id = ?` to every
query) decides which row an ID returns. A cache entry is read **before** that
scope runs, so the values the scope depends on must be part of the key:

```php
ModelCache::for(Project::class)
    ->withContext(['tenant_id' => $tenant->id])
    ->find(5);
```

- A model with a global scope (other than `SoftDeletes`) and no context
  throws `ModelNotCachable`, so a tenant can never be served another
  tenant's row by accident.
- **The context must contain what the scopes depend on**, such as the
  tenant. The package cannot tell which value a scope uses, so any context
  turns the check off; passing only an unrelated value (for example the
  locale) would cache rows without the tenant.
- **Never pass a secret as context.** Context values are written into the
  key as they are, so a token or password passed there can be read in
  Redis (`KEYS`, `MONITOR`), in logs and in `cache-contract:inspect`. Pass a
  plain identifier such as the tenant ID instead.
- `withContext()` returns a new instance; the shared one is never changed,
  so nothing leaks between requests on Octane or queue workers.

If a scope's result does not depend on the request, the user or the tenant
(it only orders rows, or applies the same filter on every request, such as
`is_published = 1`), list it as safe for that model instead:

```php
'overrides' => [
    Post::class => ['safe_scopes' => [PublishedScope::class]],
],
```

Only listed scopes are allowed; any other scope still needs a context.

The connection name and its database name are always part of the key, so
the same model on two connections, or on one connection whose database is
switched per tenant, never shares an entry.

## Soft-deleted models

Trashed models are not returned, as with Eloquent. To include them:

```php
ModelCache::for(Post::class)->withTrashed()->find(3);
```

`withTrashed()` uses its own entries, so a trashed model is never returned to
a caller who did not ask for it.

## Configuration

In `config/cache-contract.php` (publish it with
`php artisan vendor:publish --tag=cache-contract-config`):

```php
'models' => [
    'ttl' => '1 hour',      // how long an entry lives; required, never forever
    'chunk_size' => 1000,   // keys per Redis read and IDs per query in findMany()
    'overrides' => [
        User::class => ['ttl' => '10 minutes', 'version' => 2],
        Post::class => ['safe_scopes' => [PublishedScope::class]],
    ],
],
```

| Setting | Meaning |
| :--- | :--- |
| `ttl` | Default lifetime of a model entry: seconds (`3600`) or a duration (`'1 hour'`, `'1h 30m'`), at most 365 days. The same forms as a definition's `ttl` ([Definitions](definitions.md#ttl)). |
| `chunk_size` | `findMany()` reads at most this many keys and loads at most this many IDs at once, so one call never blocks Redis or exceeds the database's parameter limit. |
| `overrides.<Model>.ttl` | Lifetime for this model, in the same forms. |
| `overrides.<Model>.version` | **Bump it when the model's table changes** (a column added, removed or renamed): old entries are no longer read and expire through their TTL. Changing only the model class (casts, accessors, `$hidden`) needs no bump. |
| `overrides.<Model>.safe_scopes` | Global scopes that need no context (see above). |

The cache store is `cache-contract.store` (the default store when empty).

## When Redis is down

Model reads follow the `redis_errors` setting, like the rest of the package.
With the default `report`, the error is reported and the models are loaded
from the database; nothing is written to the cache while Redis is down.

Set a short `timeout` and `read_timeout` on the Redis connection the cache
uses. Without them, a Redis server that does not answer makes each cache call
wait up to 60 seconds before falling back.

## Keys

A key looks like:

```
cc:model:users:v1:id=5:@connection=mysql:@database=app:@tenant_id=7
```

It is built from the table, the version, the ID, the connection, its database
and any context. `ModelCache::for(User::class)->key(5)` shows it, which helps
when inspecting Redis. The section name `model` is reserved: definitions in
the config cannot use it.

## Security

An entry holds the full row, hidden columns (such as `password`) included,
so a model read from the cache behaves exactly like one read from the
database. Protect the Redis that holds the cache like the database itself.

Keys are not secret either: the ID and every context value appear in them in
plain text. Keep tokens, passwords and personal data out of the context.

## Errors

| Exception | When |
| :--- | :--- |
| `ModelNotCachable` | The class is not a model; its table name is not snake_case; its key type is neither int nor string; it has global scopes and no context; `withTrashed()` on a model without `SoftDeletes`. |
| `InvalidParameters` | An ID does not match the model's key type; a context name is reserved (`connection`, `database`, `trashed`). |
| `InvalidConfiguration` | A `models` setting is invalid. |

All of them implement `CacheContractException`.
