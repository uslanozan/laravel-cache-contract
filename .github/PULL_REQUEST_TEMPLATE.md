<!--
  PR title = the squash commit message on `develop`. It must follow
  Conventional Commits, e.g. `feat(registry): reject duplicate definition ids`.
  A CI check enforces this. See docs/commit-convention.md.

  Keep the PR small: one logical change. Delete sections that do not apply.
-->

## What changed?

<!-- One or two sentences: what a reviewer will see in the diff. -->

## Why?

<!--
  The context the diff cannot give: the problem, the decision you made,
  and what you considered and rejected. Link the ADR if one exists.
-->

Closes <!-- Linear issue ID, e.g. LRVL-12, or GitHub issue #12 -->

## Type of change

- [ ] `feat`: new capability *(minor)*
- [ ] `fix`: bug fix *(patch)*
- [ ] `perf`: performance improvement *(patch)*
- [ ] `refactor`: no behaviour change
- [ ] `test`: tests only
- [ ] `docs`: documentation only
- [ ] `build` / `ci` / `chore`: tooling, dependencies, pipeline
- [ ] ⚠️ Breaking change: `!` in the title and described under "Public API impact" *(major)*

## Public API impact

<!--
  This is a library: other projects depend on its public surface.
  Public surface = facades, contracts/interfaces, config keys, artisan
  commands and their options/exit codes, definition schema, key format.
-->

- [ ] No public API change
- [ ] Adds to the public API (backwards compatible)
- [ ] Changes or removes public API, upgrade note added under `docs/`

## Cache behaviour impact

<!-- Tick everything this PR touches and explain non-obvious effects under "Why?". -->

- [ ] None
- [ ] Key format / key generation (existing keys in Redis may become unreachable)
- [ ] TTL, locking or stampede behaviour
- [ ] Invalidation (what gets cleared, and when)
- [ ] Serialization of cached values (values written by older versions must still read or miss cleanly)
- [ ] Definition schema / contract rules

## How was this validated?

- [ ] Unit tests added or updated: <!-- which? -->
- [ ] Integration tests against real Redis added or updated: <!-- which? -->
- [ ] Verified manually: <!-- steps -->
- [ ] `composer test`, `composer analyse` and `composer lint` pass locally

## Checklist

- [ ] I read my own diff before requesting review
- [ ] Docs / README / CHANGELOG-relevant notes updated, or not needed
- [ ] No secrets, tokens or personal data in the diff

## Notes for the reviewer

<!-- Optional: where to start reading, open questions, deliberate follow-ups. -->
