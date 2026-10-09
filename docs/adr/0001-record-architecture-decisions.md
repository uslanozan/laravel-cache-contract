# 0001. Record architecture decisions

- **Status:** accepted
- **Date:** 2026-10-09

## Context

The package will be maintained by a team and used by other projects. Choices
such as the definition format or the key format are expensive to reverse once
keys exist in production Redis. Without a written record, the reasons behind
them get lost and the same discussions repeat.

## Decision

We record every decision that is expensive to reverse, or that shapes the
public API, as an Architecture Decision Record in `docs/adr/`:

- one file per decision, numbered `NNNN-short-title.md`, based on
  [`template.md`](template.md);
- written in English, short: context, options, decision, consequences;
- added or changed through a normal PR, so the review is the discussion;
- never rewritten after acceptance. A new ADR supersedes the old one, and the
  old one's status links to it.

Larger questions can start as a *Design proposal* issue; the accepted outcome
becomes an ADR.

## Consequences

- Reviewers can point to an ADR instead of re-arguing a decision.
- The architecture document required by the mission can link to the ADRs
  instead of duplicating them.
- Writing an ADR costs time; small, easily reversible choices do not need one.
