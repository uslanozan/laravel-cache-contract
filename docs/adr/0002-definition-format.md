# 0002. Definition format: one sectioned PHP file with subject + purpose

- **Status:** accepted
- **Date:** 2026-10-09

## Context

Every cache entry type in an application is declared once, centrally. The
format decides how developers read the catalogue, how duplicates are found,
and how the registry is loaded at runtime.

## Options

### Format

- **JSON / YAML file.** Language-neutral, but needs its own loader and cache
  layer, and is unfamiliar in Laravel config.
- **PHP enum or one class per definition.** Good IDE autocompletion, but
  carrying metadata is awkward, and a class per definition drifts towards the
  "class per model" pattern the mission rules out.
- **PHP config array.** Familiar to every Laravel developer; works with
  `php artisan config:cache`, so definitions are not parsed on every request.

### Layout

- **One file per domain** (`config/cache-keys/user.php`, …). Smaller files,
  fewer merge conflicts, per-domain code owners. But a large app ends up with
  many files, and reviewing or searching the catalogue means opening all of them.
- **One file, split into sections.** The whole catalogue is in one place;
  one file to read, search and review.

## Decision

Definitions live in **one PHP config file**, grouped into **sections**:

```php
// config/cache-contract.php
use App\Models\Company;
use App\Models\User;

return [
    'definitions' => [

        // ── user ─────────────────────────────────────────
        'user' => [
            'active_list' => [
                'subject'     => User::class,        // model the data is about
                'purpose'     => 'active_list',      // what it is for: English, snake_case
                'type'        => 'collection',       // model | collection | array | int | bool | string …
                'params'      => ['tenant_id' => 'int'],
                'ttl'         => 300,                // seconds, required
                'version'     => 1,                  // bump when the cached shape changes (ADR 0004)
                'description' => 'Active users of a tenant.',  // required, free text, any language
                'tags'        => ['users'],          // optional, must be declared centrally
            ],
            'profile' => [
                'subject'        => User::class,
                'purpose'        => 'profile',
                'type'           => 'array',
                'params'         => ['user_id' => 'int'],
                'ttl'            => 3600,
                'description'    => 'Profile page data, including the company name.',
                'invalidated_by' => [Company::class],  // optional: clear when a Company changes
            ],
        ],

        // ── external ─────────────────────────────────────
        'external' => [
            'exchange_rates' => [
                // no subject: the data does not belong to a model
                'purpose'     => 'exchange_rates',
                'type'        => 'array',
                'ttl'         => 600,
                'description' => 'Rates fetched from the central bank API.',
            ],
        ],

        // ── order ────────────────────────────────────────
        'order' => [
            // ...
        ],
    ],
];
```

- **IDs** are `section.name`, both parts snake_case: `user.active_list`,
  `user.price_number`. The same name in two sections never collides.
- **`subject` + `purpose`** describe what the data is. The same pair on two
  definitions is an **exact duplicate and an error**, regardless of their
  names. This catches semantic duplicates that name similarity cannot.
- `description` is required, but it is free text and is not used for
  similarity.
- Unknown fields (e.g. a typo like `ttll`) are rejected.

### Fields

| Field | Required | Rule |
| :--- | :--- | :--- |
| `purpose` | yes | English, snake_case |
| `type` | yes | one of `model`, `collection`, `array`, `string`, `int`, `float`, `bool`. Used for structural similarity in v1. |
| `ttl` | yes | positive integer, seconds. **No "forever" entries in v1**: a missed invalidation would serve stale data indefinitely. |
| `description` | yes | non-empty free text, any language |
| `subject` | no | an existing Eloquent model class. Omitted for data that does not belong to a model (external APIs, settings, cross-table reports); such definitions are compared by name and structure only. |
| `params` | no | name → type (`int`, `string`, `bool`, `float`, `array`). **Every declared parameter is required at call time in v1.** Optional parameters would let the same data live under two keys (with and without the parameter); if needed later, they come as parameters with a default value, so the key stays the same. |
| `version` | no | positive integer, default `1` (ADR 0004) |
| `tags` | no | list of centrally declared tag names |
| `invalidated_by` | no | list of existing Eloquent model classes whose changes clear this definition. Part of the schema from v1 so it is validated early; the invalidation behaviour arrives in Phase 3. |
- Model by-id caching needs no definition (see ADR 0006); only custom data
  (profiles, lists, statistics) is defined here.

**Duplicates inside the file:** PHP silently keeps the last value when an
array key appears twice, so the runtime loader cannot see it. `cache-contract:lint`
parses the file and reports duplicate keys. Lint runs in CI, so this is enforced.

## Consequences

- The catalogue is readable top to bottom in one file; search is one `grep`.
- Several people adding definitions at once will cause more merge conflicts
  in this file. Keeping sections in a fixed order and definitions alphabetical
  within a section limits this.
- String IDs get no IDE autocompletion. Typos are caught by lint and by the
  runtime exception for unknown IDs. A generated IDE helper is possible later.
- If the single file becomes unmanageable in practice, splitting is a new ADR;
  IDs (`section.name`) would not need to change.
