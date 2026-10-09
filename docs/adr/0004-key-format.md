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
