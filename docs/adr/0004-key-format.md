# 0004. Physical key format, versioning and parameter encoding

- **Status:** accepted
- **Date:** 2026-10-09

## Context

A definition ID (`user.active_list`) is what developers write. The physical
key is what is stored in Redis. The package generates physical keys; nobody
writes them by hand. Teams have different naming conventions, but within one
project every key must follow the same one.

Two further problems:

1. **Shape changes across deploys.** If the cached value of `user.profile`
   gains a field, entries written by the old code are still in Redis after a
   deploy, and the new code reads data in the old shape.
2. **Complex parameters.** `tenant_id=7` fits in a key; a list of 200 IDs or a
   filter array does not.

## Decision

**One project-wide, configurable format.** The config file holds a single
`key_format`; every key is generated from it.

```php
'key_format' => '{prefix}:{section}:{name}:v{version}:{params}',
```

Available placeholders: `{prefix}`, `{section}`, `{name}`, `{version}`,
`{params}`. Lint rejects a format that cannot produce unique keys (for example
one without `{name}` or `{params}`). The format is the default; teams may
change it, but there is only ever one per project.

Example: `myapp:user:active_list:v1:tenant_id=7`.

**Versioning is on by default.** Each definition has a `version` (default 1).
When the cached shape changes, the developer bumps it: new code reads and
writes `v2` keys, old `v1` keys are never read again and expire through their
TTL. No manual cleanup, no mixed shapes during a deploy. The cost is a few
bytes per key. Removing `{version}` from the format is allowed and documented
as a trade-off.

**Parameter encoding:**

- Scalar parameters (int, string, bool) are written readably, in a fixed order
  (sorted by name): `tenant_id=7:type=admin`.
- Arrays and other complex values are canonicalized (types kept, meaningful
  list order kept) and replaced by a short hash: `ids=#a3f9c2e1`.
- The same input always produces the same key; different tenant / locale /
  projection context always produces a different key.

## Consequences

- Keys stay readable when inspecting Redis, except for hashed parts.
- **Changing `key_format` or turning versioning on later changes every key at
  once.** No data is lost and nothing breaks, but the cache is effectively
  empty: every read misses and goes to the database until the cache warms up,
  and old keys use memory until their TTL ends. The upgrade notes must say so;
  doing it outside peak hours is advised.
- Bumping a single definition's version has the same effect for that
  definition only.
- Hashed parameters cannot be read back from the key; `cache-contract:inspect`
  shows how a key was built from its inputs.

## Update 2026-10-12: the separator is fixed to ":"

The format stays configurable (which placeholders, `prefix` and `version`
optional, literal text such as `v` before `{version}`), but placeholders must
always be separated by `:`, and only `:`, lowercase letters and digits may
appear around them. Definition names cannot contain `:`, so segment
boundaries are never ambiguous and two definitions can never produce the
same key. With `_` or no separator they could: `user_a` + `b` and
`user` + `a_b` would both give `user_a_b`. Other separators may be offered
as options later. Enforced by `KeyFormat` (LRVL-6); the similarity engine
therefore does not need to look for key collisions.

## Update 2026-10-15: generation segment (ADR 0009)

Keys of entries that depend on generation counters (tags, `invalidated_by`)
get a generation segment, e.g. `cc:user:profile:v2:user_id=42:~g8123`. It is
**not** a `key_format` placeholder: the key factory always appends it, like
context, so a custom format can never drop it and turn invalidation off. The
caller reads the counters and passes the generations in; the key factory
stays pure and never talks to Redis. See
[ADR 0009](0009-invalidation-with-generation-counters.md).

## Update 2026-10-16: strict parameters, lenient model IDs

Two kinds of call-time values reach the key factory, and they are checked
differently on purpose:

- **Definition parameters stay strict.** An `int` parameter does not accept
  `"42"`. The type is written by the developer in the definition, so a string
  there usually means an unvalidated request value. The error message says so
  and suggests validating before casting, because a plain `(int)` turns
  invalid input such as `"abc"` into `0`: a valid-looking key for the wrong
  data.
- **Model cache IDs follow Eloquent.** `ModelCache::for(User::class)->find("5")`
  is the same as `find(5)`. Only a canonical integer string is converted;
  `"05"`, `"5abc"` and `"abc"` are rejected. Here the type comes from the
  model (`getKeyType()`), not from the developer. IDs very often arrive as
  strings from routes, query strings or Redis. And the model cache is meant
  to replace `Model::find()`, which accepts them.

Both rules keep the principle of this ADR: one value, one key. Strict
parameters reject a second spelling, and model IDs map it to the first.
Making model IDs strict as well was rejected: it would push every caller to
write `(int) $id`, which accepts more bad input than the conversion does.
