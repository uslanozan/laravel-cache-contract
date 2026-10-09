# Branching strategy

`develop` is the integration branch and the repository's **default branch**.
`main` only ever contains released code; every commit on `main` is a version
tag.

```text
feat/LRVL-12-registry ──squash──▶ develop ──merge commit──▶ main (tag v0.2.0)
                                      ▲                         │
                                      └──── back-merge ─────────┘ (after a hotfix)
```

## Branches

| Branch | Created from | Merges into | Merge method |
| :--- | :--- | :--- | :--- |
| `feat/…`, `fix/…`, `perf/…`, `refactor/…`, `test/…`, `docs/…`, `build/…`, `ci/…`, `chore/…` | `develop` | `develop` | squash |
| `release/vX.Y.Z` (optional) | `develop` | `main` | merge commit |
| `hotfix/…` | `main` | `main`, then back-merge `main` → `develop` | merge commit |

**Naming:** `<type>/<linear-id>-<short-description>`; the description is lowercase, words separated
by `-`. The prefix uses the same words as the commit types, so there is nothing
to translate.

```text
feat/LRVL-12-definition-registry
fix/LRVL-31-null-value-treated-as-miss
docs/readme-installation
```

Putting the Linear issue ID in the branch name links the branch and its PR to
the issue automatically, and Linear moves the issue to *Done* when the PR is
merged.

## Daily workflow

```bash
git switch develop
git pull
git switch -c feat/LRVL-12-definition-registry

# work, commit (see commit-convention.md)
git push -u origin feat/LRVL-12-definition-registry
gh pr create --base develop --fill   # or open it from the GitHub UI
```

While the PR is open:

- Keep it small. One logical change per PR; split if review takes more than ~20 minutes.
- If `develop` moves ahead, update your branch (GitHub's *Update branch* button,
  or `git rebase develop`). Rulesets require the branch to be up to date before merging.
- Every new push dismisses earlier approvals, so ask for a re-review after changes.
- The PR author merges once it is approved and green.

## Rules enforced on GitHub

See [`.github/rulesets/`](../.github/rulesets/README.md). In short: no direct
pushes to `main` or `develop`, at least one approval, all checks green, all
review threads resolved. The repository owner can bypass these rules; this
is for emergencies and repository maintenance, not for routine work.

## Releases

1. Make sure `develop` is green and the changelog notes are ready.
2. Open a PR `develop` → `main` titled `chore(release): vX.Y.Z`.
3. Merge it with a **merge commit** (squash is disabled on `main` on purpose).
4. Tag the merge commit `vX.Y.Z` and publish a GitHub release. Packagist picks
   up the tag automatically.

The release tooling (changelog generation, tagging) is set up in a later
phase; see the [roadmap](ROADMAP.md).
