# Branch rulesets

These files are the source of truth for the branch rules on GitHub. Change the
rules here, in a PR, and then apply them, so the rules themselves get reviewed
and have a history.

| Rule | `develop` | `main` |
| :--- | :---: | :---: |
| Direct push | ❌ (PR only) | ❌ (PR only) |
| Required approvals | 1 | 1 |
| New push dismisses approvals | ✅ | ✅ |
| Last push must be approved by someone else | ✅ | ✅ |
| All review threads resolved | ✅ | ✅ |
| Required checks: `CI result`, `PR title`, `Branch flow` | ✅ | ✅ |
| Branch must be up to date with base | ✅ | ✅ |
| Allowed merge methods | squash (features), merge (back-merges) | merge commit only |
| Force push / deletion | ❌ | ❌ |
| **Bypass** | Repository admin | Repository admin |

**Bypass:** `actor_id: 5` with `actor_type: RepositoryRole` is GitHub's
built-in *Admin* repository role. On a personal repository only the owner
(@uslanozan) is admin; collaborators get *Write*. The owner can therefore push
directly and merge without approval; everyone else goes through a reviewed PR.

**Why `main` allows merge commits only:** `develop` → `main` must keep the
individual commits. Squashing would give `main` a commit that `develop` does
not have, and the two branches would diverge and conflict on every release.

## Applying

```bash
# First time (creates the rulesets)
gh api -X POST repos/uslanozan/laravel-cache-contract/rulesets --input .github/rulesets/develop.json
gh api -X POST repos/uslanozan/laravel-cache-contract/rulesets --input .github/rulesets/main.json

# Later changes: find the id, then PUT the updated file
gh api repos/uslanozan/laravel-cache-contract/rulesets --jq '.[] | "\(.id) \(.name)"'
gh api -X PUT repos/uslanozan/laravel-cache-contract/rulesets/<id> --input .github/rulesets/develop.json
```

Required status check names must match the job `name:` values in
`.github/workflows/ci.yml` and `.github/workflows/pr-checks.yml`. Renaming a
job without updating these files blocks every PR.
