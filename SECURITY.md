# Security policy

## Reporting a vulnerability

**Do not open a public issue.** Report privately through GitHub:
[Security → Report a vulnerability](https://github.com/uslanozan/laravel-cache-contract/security/advisories/new).

Please include:

- the affected version (tag or commit),
- what an attacker can do and under which configuration,
- a minimal reproduction if you have one.

You can expect an acknowledgement within 5 working days. We will keep you
updated while we work on a fix and credit you in the advisory unless you
prefer otherwise.

## What counts as a security issue here

A cache layer sits between an application and its data, so we treat these as
security issues, not ordinary bugs:

- data cached for one tenant, user or permission scope being served to another
  (missing context in a generated key, mutable context leaking between requests),
- unsafe deserialization of cached values,
- a cache hit bypassing authorization the uncached path enforces,
- key injection through unvalidated parameters.

## Supported versions

The package is in initial development (`0.x`). Only the latest release receives
fixes. A support table will be added with `1.0.0`.
