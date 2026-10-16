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
    | ttl:        seconds a model entry lives; required, never forever
    | overrides:  per model class:
    |             ttl         its own TTL
    |             version     bump it when the table's columns change (ADR 0008)
    |             safe_scopes global scopes that never change which row an ID
    |                         returns (e.g. one that only adds an order)
    |
    | 'overrides' => [
    |     App\Models\User::class => ['ttl' => 600, 'version' => 2],
    | ],
    |
    */

    'models' => [
        'ttl' => 3600,
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
    |         'ttl'         => 300,                     // required, seconds
    |         'description' => 'Active users of a tenant.', // required
    |     ],
    | ],
    |
    */

    'definitions' => [],

];
