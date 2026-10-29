# Definitions

Every cache entry type is defined once in `config/cache-contract.php`, under
`definitions`. Code refers to it by its ID; the key and the TTL come from the
definition, so they are never typed by hand.

```php
'definitions' => [
    'user' => [                        // section
        'profile' => [                 // name → ID "user.profile"
            'subject'     => App\Models\User::class,
            'purpose'     => 'profile',
            'type'        => 'array',
            'params'      => ['user_id' => 'int'],
            'ttl'         => '1 hour',
            'description' => 'Profile page data.',
        ],
    ],
],
```

## Sections and IDs

Definitions are grouped into sections. The ID is `section.name`, e.g.
`user.profile`, and both parts are snake_case. Group by the data's owner
(`user`, `billing`, `report`) so related entries sit together.

The section `model` is reserved for the [model cache](model-cache.md).

## Fields

| Field | Required | Meaning |
| :--- | :--- | :--- |
| `purpose` | yes | What the entry is for, in snake_case: `profile`, `active_list`. |
| `type` | yes | What is cached: `model`, `collection`, `array`, `string`, `int`, `float` or `bool`. |
| `ttl` | yes | How long an entry lives: seconds or a duration; see [TTL](#ttl). |
| `description` | yes | One sentence for the people who read the config and the `list` output. |
| `subject` | no | The Eloquent model the data is about, e.g. `App\Models\User::class`. |
| `params` | no | The values that pick one entry out of many; see [Parameters](#parameters). |
| `version` | no | A positive integer, default `1`; see [Versions](#versions). |
| `tags` | no | A list of tag names. Each tag has a generation counter in the key; clearing by tag comes in a later release. |
| `invalidated_by` | no | A list of model classes whose changes clear every entry of this definition; see [invalidated_by](#invalidated_by). |

Two definitions with the same `subject` and `purpose` are rejected: that is
the same data cached under two names.

## Versions

The version is part of the key (`cc:user:profile:v2:…`). Bump it when the
structure of the cached value changes, for example when a field is added to
the cached array. The old entries are then no longer read, and they expire
through their TTL; nothing has to be flushed.

## invalidated_by

List the models whose changes make the cached data wrong. When one of them
is saved (created or updated), deleted or restored, every entry of the
definition is invalidated:

```php
'member' => [
    'active_list' => [
        'purpose'        => 'active_list',
        'type'           => 'array',
        'params'         => ['organization_id' => 'int'],
        'ttl'            => '10 minutes',
        'description'    => 'Names of the active members of an organization.',
        'invalidated_by' => [App\Models\Member::class],
    ],
],
```

```php
CacheContract::remember('member.active_list', ['organization_id' => 1], fn () => $this->activeNames(1));

Member::create([...]);   // a new member enters the list: the next call loads it again
```

- **Every entry of the definition**, for every parameter and context: a new
  member of organization 2 also clears the list of organization 1. The
  package cannot tell which entries a row belongs to.
- **After the commit.** Inside a transaction on the model's connection, the
  entries are invalidated once it commits; on rollback nothing changes.
  Without a transaction on that connection they are invalidated right away.
- **Only real changes.** Eloquent fires its `saved` event even when
  `save()` had nothing to write; such a save invalidates nothing. A delete or
  a restore always does.
- **The model needs no `HasCache`.** Any Eloquent model works.
- **The exact class.** A change to a subclass of a listed model is not seen,
  as with Eloquent's own model events; list the subclass too.
- **A save never fails because of the cache.** An invalid setting is passed
  to `report()`. A Redis error follows the `invalidate` setting of
  [Redis errors](production.md#when-redis-is-down): with the default
  `report`, it is reported and the entries stay until their TTL ends.
- **Not seen by model events**, as in Eloquent itself: mass updates and
  deletes (`Member::query()->update([...])`), `saveQuietly()`,
  `withoutEvents()`, raw SQL and changes from other applications. Keep the
  TTL short enough for those.

Each definition with `invalidated_by` has a generation counter (ADR 0009).
A change gives it a new value, which is part of every key of the definition,
so all the old entries become unreachable at once with one Redis write, and
expire through their TTL. Reading such an entry costs one extra Redis
command for the counter. A value loaded while a change commits is written
under the old value, so it is never read.

The listeners are registered when the application boots, from the
`invalidated_by` lists only: the definitions are not validated at boot, so a
broken config never stops the application from booting. It still fails on
the first cache call and in `cache-contract:list`. After changing
`invalidated_by`, restart long-running workers (`php artisan octane:reload`,
`queue:restart`), as for any other config change.

## Validation

The definitions are validated on the first cache call (and by the commands).
Every problem is reported at once, with the definition it belongs to:

```
Uslanozan\CacheContract\Exceptions\InvalidDefinitions
3 problems in config/cache-contract.php:
  - user.profile: "ttl" is required
  - user.active_list: unknown field "ttll" (did you mean "ttl"?)
  - user.active_list: "ttl" is required
```

Run `php artisan cache-contract:list` after editing the config: it loads and
validates every definition, so a mistake shows up before a request hits it.

## TTL

Every definition has a TTL; entries that never expire are not allowed. Write
it in seconds, or with units so the meaning is visible:

```php
'ttl' => 3600,          // seconds
'ttl' => '1 hour',
'ttl' => '15 minutes',
'ttl' => '1h 30m',      // parts add up
'ttl' => '2 weeks',
```

- Units: seconds, minutes, hours, days and weeks, long or short (`s`, `m`,
  `h`, `d`, `w`).
- Months and years are rejected, because their length is not fixed. Use days
  or weeks, e.g. `'30 days'`.
- A numeric string such as `'300'` is rejected, because its unit is not
  visible. Write `300` or `'300 seconds'`.
- A TTL is at most 365 days (`31536000` seconds). The limit catches typos
  such as an extra `* 1000`; most entries live minutes to hours, and data
  that changes is cleared on change anyway, not by its TTL.

`php artisan cache-contract:inspect <id>` shows the TTL in seconds and in
words, e.g. `3600 s (1 hour)`.

## Parameters

Every declared parameter is required at call time, and nothing else may be
passed. Each one has a type:

| Type | Accepts | In the key |
| :--- | :--- | :--- |
| `int` | an int | as written: `user_id=42` |
| `string` | a string | URL-encoded: `region=eu`, `name=a%3Ab` |
| `bool` | a bool | `true` / `false` |
| `float` | a finite float | `ratio=1.5` |
| `array` | an array | a short hash of its content: `ids=#3f2a…` |
| `secret` | a string | a keyed hash, never the value: `api_token=#9c1e…` |

Types are strict: an `int` parameter does not accept `"42"`. Values from a
request are strings, so validate them first and then cast them. A plain
`(int)` turns invalid input such as `"abc"` into `0`, a valid-looking key for
the wrong data.

### Secret parameters

Use `secret` for values that must not be readable in Redis, logs or
`cache-contract:inspect`, such as API tokens:

```php
'session_owner' => [
    'purpose'     => 'session_owner',
    'type'        => 'array',
    'params'      => ['api_token' => 'secret'],
    'ttl'         => 300,
    'description' => 'Owner of an API session.',
],
```

```
cc:auth:session_owner:v1:api_token=#9c1e…
```

- The hash is keyed with your `APP_KEY`, so it cannot be reversed by hashing
  guessed tokens. A definition with a secret parameter needs `APP_KEY` to be
  set: without it the definitions fail to load with an `InvalidDefinitions`
  error that names the parameter.
- The same value always gives the same key. If you rotate `APP_KEY`, these
  entries all miss once and are cached again, so expect a burst of loads
  right after the rotation.
- Only parameters are protected. Context values (`withContext()`) are always
  written into the key as they are, so never pass a secret as context.
- `cache-contract:inspect` does not accept a secret with `--param`, because
  the value would stay in your shell history and CI logs. It asks for the
  value instead, without showing what you type, and prints only the hash.
  With `--no-interaction` it prints the key pattern only.
