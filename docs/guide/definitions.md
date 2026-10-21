# Definitions

> This page is growing with the package. For now it covers parameters; the
> full guide to writing definitions comes with the v0.1.0 docs.

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
  set.
- The same value always gives the same key. If you rotate `APP_KEY`, these
  entries miss once and are cached again.
