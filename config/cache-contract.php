<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Key prefix
    |--------------------------------------------------------------------------
    |
    | Replaces {prefix} in the key format. Keeps this application's keys apart
    | from other applications that share the same Redis.
    |
    */

    'prefix' => env('CACHE_CONTRACT_PREFIX', 'cc'),

    /*
    |--------------------------------------------------------------------------
    | Key format
    |--------------------------------------------------------------------------
    |
    | One format for every key in the project (ADR 0004). Placeholders:
    | {prefix}, {section}, {name}, {version}, {params}, always separated by ":".
    | Changing it later changes every key at once, which empties the cache
    | until it warms up.
    |
    */

    'key_format' => '{prefix}:{section}:{name}:v{version}:{params}',

    /*
    |--------------------------------------------------------------------------
    | Cache store
    |--------------------------------------------------------------------------
    |
    | The store from config/cache.php that holds the package's entries. Null
    | uses the application's default store. Redis is recommended; set
    | "timeout" and "read_timeout" on its connection, or a Redis outage can
    | make every cache call wait up to 60 seconds (ADR 0007).
    |
    */

    'store' => env('CACHE_CONTRACT_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Redis errors
    |--------------------------------------------------------------------------
    |
    | What happens when Redis cannot be reached (ADR 0007), per operation:
    | "report" passes the error to report() and continues without the cache,
    | "throw" rethrows it. Only connection errors follow this setting.
    |
    | read:       report → treated as a miss: the loader runs, nothing is written
    | write:      report → the value is returned but not cached
    | invalidate: report → the old entry stays until its TTL ends
    |
    | Consider "throw" for all three in your test suite, so failures show.
    |
    */

    'redis_errors' => [
        'read' => 'report',
        'write' => 'report',
        'invalidate' => 'report',
    ],

    /*
    |--------------------------------------------------------------------------
    | Model cache
    |--------------------------------------------------------------------------
    |
    | ModelCache::for(User::class)->find(5) needs no definition (ADR 0006).
    | Its key is built from the table, the ID, the connection and its
    | database, e.g. "cc:model:users:v1:id=5:@connection=mysql:@database=app".
    |
    | A model with global scopes (other than SoftDeletes) needs
    | withContext([...]) with the values those scopes depend on, such as the
    | tenant: ->withContext(['tenant_id' => 7])->find(5). The package cannot
    | tell which value a scope uses, so any context turns the check off; an
    | unrelated one (e.g. only the locale) caches rows without the tenant.
    |
    | ttl:        how long a model entry lives, in seconds or with units
    |             ("1 hour", "30 minutes"); required, never forever
    | chunk_size: findMany() reads at most this many keys per MGET and loads
    |             at most this many IDs per query, so one call never blocks
    |             Redis or exceeds the database's parameter limit
    | overrides:  per model class:
    |             ttl         its own TTL
    |             version     bump it when the table's columns change (ADR 0008)
    |             safe_scopes global scopes that need no context
    |
    | A global scope is safe when its result does not depend on the request,
    | the user or the tenant: it only orders rows, or applies the same filter
    | on every request (e.g. is_published = 1). A scope that reads the
    | current tenant, user or locale is not safe: give withContext() instead.
    | Only listed scopes are allowed; any other scope still needs a context.
    |
    | 'overrides' => [
    |     App\Models\User::class => ['ttl' => 600, 'version' => 2],
    |     App\Models\Post::class => ['safe_scopes' => [App\Models\Scopes\PublishedScope::class]],
    | ],
    |
    */

    'models' => [
        'ttl' => 3600,
        'chunk_size' => 1000,
        'overrides' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Definitions
    |--------------------------------------------------------------------------
    |
    | Every cache entry type, grouped into sections (ADR 0002). The ID of a
    | definition is "section.name", e.g. "user.active_list".
    |
    | 'user' => [
    |     'active_list' => [
    |         'subject'     => App\Models\User::class,  // optional
    |         'purpose'     => 'active_list',           // required
    |         'type'        => 'collection',            // required
    |         'params'      => ['tenant_id' => 'int'],  // optional
    |         'ttl'         => '5 minutes',             // required: seconds or a duration
    |         'description' => 'Active users of a tenant.', // required
    |     ],
    | ],
    |
    */

    'definitions' => [],

    /*
    |--------------------------------------------------------------------------
    | Tags
    |--------------------------------------------------------------------------
    |
    | Every tag a definition may carry, with what it groups (ADR 0002). Names
    | are snake_case. CacheContract::flushTag('users') invalidates every entry
    | of every definition tagged "users" at once, for every parameter and
    | context (ADR 0009).
    |
    | 'users' => 'Data shown about users.',
    |
    */

    'tags' => [],

    /*
    |--------------------------------------------------------------------------
    | Scanner
    |--------------------------------------------------------------------------
    |
    | Where the scanner looks for cache calls, relative to the project root.
    |
    | paths:   files or directories to scan; every .php file under them
    | exclude: files or directories to skip, also inside "paths". storage/
    |          holds compiled Blade views, which would count every call in a
    |          template a second time. tests/ sets up and fakes the cache
    |          (Cache::shouldReceive, Cache::put in a setUp), which is not
    |          the application's usage; remove it to scan the tests too
    | wrappers: your own classes that call the cache inside, with the methods
    |          that touch it and what they do: read, write, remember, forget
    |          or other. Every call to a listed method is reported where it
    |          is made; other methods of the class are not
    |
    | 'wrappers' => [
    |     App\Support\CacheProperties::class => ['has' => 'read', 'get' => 'read', 'store' => 'write'],
    | ],
    |
    */

    'scanner' => [
        'paths' => ['.'],
        'exclude' => ['vendor', 'node_modules', 'storage', 'bootstrap/cache', 'tests'],
        'wrappers' => [],
    ],

];
