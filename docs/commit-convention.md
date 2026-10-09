# Commit convention

We use [Conventional Commits](https://www.conventionalcommits.org/). The type
of a change decides the next version number, and the history doubles as the
changelog.

## Format

```text
<type>(<scope>): <subject>

[optional body: why, not what]

[optional footer: BREAKING CHANGE: …, Refs: LRVL-12]
```

- **subject:** imperative, lowercase, no trailing period, about 72 characters max.
  "add", not "added" / "adds".
- **scope:** optional but recommended; see the list below.
- **body:** explain *why*. The diff already shows *what*.

## Where it is enforced

Feature branches are **squash-merged** into `develop`, so the **PR title**
becomes the single commit on `develop`. The `PR title` check validates it on
every PR. Commits inside your branch are not checked one by one, but keep them
readable: reviewers read them.

## Types

| Type | Use for | Version bump |
| :--- | :--- | :--- |
| `feat` | New capability for package users | minor |
| `fix` | Bug fix | patch |
| `perf` | Performance improvement, no behaviour change | patch |
| `refactor` | Internal restructuring, no behaviour change | none |
| `test` | Adding or fixing tests | none |
| `docs` | Documentation only | none |
| `build` | Composer config, Docker, packaging | none |
| `ci` | GitHub Actions, rulesets | none |
| `chore` | Anything else that does not touch `src/` | none |
| `revert` | Reverting an earlier commit | depends |

**Breaking change:** add `!` after the type/scope, and explain the upgrade path
in the body or a `BREAKING CHANGE:` footer. Breaking means anything that forces
package users to change their code, their definitions, or that makes keys
already stored in Redis unreachable.

```text
feat(keys)!: include definition version in generated keys

BREAKING CHANGE: keys written by 0.x are no longer read. They expire by TTL;
run `php artisan managed-cache:flush-legacy` to remove them earlier.
```

While the package is below `1.0.0`, breaking changes bump the **minor**
version (`0.3.0` → `0.4.0`), following SemVer's rule for initial development.

## Scopes

| Scope | Area |
| :--- | :--- |
| `definitions` | Definition schema, loading, validation |
| `registry` | Definition registry |
| `keys` | Key generation, canonicalization |
| `api` | `ManagedCache` runtime API |
| `eloquent` | Model cache, bulk loading |
| `invalidation` | Tags, versions, model events |
| `scanner` | Source code scanning |
| `discovery` | Search, similarity |
| `lint` | Contract lint, PHPStan rules |
| `commands` | Artisan commands (when not specific to one area) |
| `demo` | Demo application |
| `deps` | Dependency updates |

New scopes are fine when none fits; keep them short and lowercase.

## Examples

```text
✅ feat(registry): reject duplicate definition ids
✅ fix(keys): keep list order when canonicalizing parameters
✅ test(eloquent): cover partial hits in findMany
✅ docs(adr): record php contract format decision
✅ ci: run tests against redis 8

❌ fixed bug                      no type, past tense
❌ feat: Added registry.          capitalized, past tense, trailing period
❌ feat(api): add remember and fix ttl bug    two changes, split into two PRs
❌ wip
```
