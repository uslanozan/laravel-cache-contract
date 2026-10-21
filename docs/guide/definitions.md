# Definitions

> This page is growing with the package. For now it covers TTLs and
> parameters; the full guide to writing definitions comes with the v0.1.0 docs.

Every cache entry type is defined once in `config/cache-contract.php`. Its
parameters are the values that pick one entry out of many, such as a user ID.

```php
'user' => [
    'profile' => [
        'purpose'     => 'profile',
        'type'        => 'array',
        'params'      => ['user_id' => 'int'],
        'ttl'         => 3600,
        'description' => 'Profile page data.',
    ],
],
```

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
