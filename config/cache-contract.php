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
    | {prefix}, {section}, {name}, {version}, {params}. Changing it later
    | changes every key at once, which empties the cache until it warms up.
    |
    */

    'key_format' => '{prefix}:{section}:{name}:v{version}:{params}',

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
