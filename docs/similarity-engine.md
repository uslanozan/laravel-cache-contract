# Similarity engine

How the package recognises that a new cache definition resembles an existing
one, and the decisions behind it. Owner: @uslanozan. Code: `src/Similarity/`.

> Status: name scoring (LRVL-9) and the engine that turns scores into findings
> (LRVL-10) are implemented. Threshold calibration (LRVL-11) and the decision
> record for detection layers (ADR 0010) follow.

## 1. Goal and scope

When a developer adds a definition, find existing ones that may hold the same
data, and explain why, before a duplicate is created.

| Kind of match | Example | Handled by |
| :--- | :--- | :--- |
| Same `subject` + `purpose` | two definitions for the same data | **Validator** (exact rule, an error) |
| Similar **names** | `active_users` ↔ `activeUser` ↔ `actve_users` | **Name similarity** (this document) |
| Same structure, different names | same subject, type and parameters | Structural comparison (LRVL-10) |
| Same meaning, different words | `user_profile` ↔ `member_info` | Not in v1 (LRVL-48, LRVL-49) |

v1 compares **definition names only**. `purpose` and `description` are not
compared fuzzily (decision D-3).

Everything is deterministic: the same input always gives the same result,
so it can run in CI. No language model, no network, no new dependency.

## 2. Pipeline

```text
"activeUsers"
   │ 1. split            camelCase / PascalCase boundaries, any non-alphanumeric character
   ▼
[active, Users]
   │ 2. lowercase
   ▼
[active, users]          ← "words" (as written)
   │ 3. singular         Str::singular() (Doctrine Inflector, English)
   ▼
[active, user]           ← "tokens"; normalized name: "active_user"
   │ 4. compare          soft Jaccard (words) + normalized Levenshtein (characters)
   │ 5. classify         signals: style / plural / typo / reordered / opposite / shared words
   │ 6. explain          reasons
   ▼
NameScore { score, signals, wordOverlap, characterSimilarity, opposite, reasons }
```

Classes: `NameTokenizer` (steps 1–3), `NameSimilarity` (4–6), `Antonyms`,
`NameScore`, `NameSignal`.

## 3. The measures

### Jaccard index (Paul Jaccard, 1901)

How much two sets overlap:

```text
J(A, B) = |A ∩ B| / |A ∪ B|        0 = nothing in common, 1 = identical
```

Applied to the token sets of two names. Word order does not matter.

**Soft Jaccard.** Plain Jaccard treats `actve` and `active` as different words.
We count two words as the same when both are at least
`FUZZY_WORD_MIN_LENGTH` (5) characters long, at most
`FUZZY_WORD_MAX_DISTANCE` (1) edit apart, and not opposites. Short words must
match exactly, so `usr` and `user` stay different.

### Levenshtein distance (Vladimir Levenshtein, 1965)

The minimum number of single-character insertions, deletions or
substitutions that turn one string into the other (PHP: `levenshtein()`).
Turned into a similarity on the normalized names:

```text
similarity = 1 − distance / max(length₁, length₂)
```

It is **order-sensitive** on purpose (decision D-6).

### Combined score

```text
score = WORD_WEIGHT × softJaccard + CHARACTER_WEIGHT × levenshteinSimilarity
      = 0.5 × softJaccard + 0.5 × levenshteinSimilarity     (starting values)
```

The two measures cover each other's blind spots: Jaccard sees shared words
regardless of order, Levenshtein sees typos inside words.

**Standard vs. ours:** Jaccard, Levenshtein and the normalization above are
standard. The weights, the fuzzy-word limits and any warning threshold are
**our starting values**, kept as named constants in `NameSimilarity` and
calibrated against labelled pairs in LRVL-11.

## 4. Signals

A score alone cannot say *what kind* of resemblance it is, so every
comparison also returns signals. Signals describe; the engine decides how
serious each one is (LRVL-10, ADR 0010).

| Signal | When | Example | Meaning |
| :--- | :--- | :--- | :--- |
| `Identical` | exactly the same name | `active_users` ↔ `active_users` | same name |
| `StyleVariant` | same words, different style | `active_users` ↔ `activeUsers` | almost certainly the same definition |
| `PluralVariant` | only singular/plural differs | `active_user` ↔ `active_users` | almost certainly the same definition |
| `Typo` | a word one character apart | `actve_users` ↔ `active_users` | probably the same definition |
| `Reordered` | same words, different order | `tenant_members` ↔ `member_tenants` | **may be different data**; worth a look |
| `Opposite` | contains opposite words | `active_users` ↔ `inactive_users` | similar names, opposite data; never "the same" |
| `SharedWords` | some words in common, nothing stronger | `exchange_rates` ↔ `exchange_rate_list` | weak hint |

### Opposite words

Detected from two curated lists (`Antonyms`), precision over recall:

- a negating prefix (`in-`, `un-`, `dis-`, `non-`) on a **known negatable
  word** (`active`, `paid`, `connected`, `read`, `verified`…). A generic prefix
  rule was rejected: it calls `discount`/`count` and `invoice`/`voice` opposites.
- pairs without a shared prefix: `enabled`/`disabled`, `public`/`private`, `online`/`offline`,
  `visible`/`hidden`, `min`/`max`…

Both lists will grow with real names from the public scan.

## 5. The engine: from signals to findings

`DefaultSimilarityEngine::findSimilar($candidate, $existing)` compares the
candidate with every existing definition and reports **at most one finding per
definition**: the most serious one, carrying all reasons. Results are sorted
by severity, then score, then id.

### Severity

| Severity | Meaning | Lint | `search` / `make` |
| :--- | :--- | :--- | :--- |
| **Error** | a certain duplicate | fails CI | shown |
| **Warning** | probably a duplicate, worth a look | reported, CI passes | shown |
| **Info** | for awareness, likely intentional | not shown | shown |

### Policy (first matching row wins)

| Situation | Example | Type | Severity |
| :--- | :--- | :--- | :--- |
| same id | adding `user.active_list` again | `IdentifierCollision` | Error |
| same subject + purpose | ADR 0002 rule | `PurposeCollision` | Error |
| same name apart from style / plural, **same section** | `user.active_user` ↔ `user.active_users` | `NameVariant` | Error |
| … in **another section** | `member.active_users` ↔ `user.active_user` | `NameVariant` | Warning |
| … in another section **with different subjects** | `company.summary` ↔ `user.summary` | `NameVariant` | Info |
| opposite words | `inactive_users` ↔ `active_users` | `OppositeMeaning` | Info |
| typo or reordered words, **any score** | `actve_users`, `member_tenants` | `LexicalSimilarity` | Warning |
| shared words, score ≥ `SHARED_WORDS_THRESHOLD` (0.70) | `exchange_rates_eu` ↔ `exchange_rates` (0.74) | `LexicalSimilarity` | Warning |
| same subject + result type + parameter set | `active_members` ↔ `active_users` (both User, collection, `tenant_id`) | `StructuralDuplicateCandidate` | Info |

### Structural comparison

Compares fields **exactly**, never words: the same `subject` class, the same
`type`, and the same parameter set (names and types, any order). It is the v1
answer to synonyms the name measures cannot see (`active_members` ↔
`active_users`). It never applies without a subject: "an array by region" says
nothing about which data it is (`external.weather` ↔ `external.exchange_rates`).
It is Info, not Warning, because many legitimately different lists share a
structure (`active_users`, `banned_users`, both User / collection / `tenant_id`).

### Key collisions

Not searched for: the key separator is fixed to `:`, which names cannot
contain, so two definitions can never produce the same key (ADR 0004 update,
2026-10-12).

## 6. Reference results

These pairs are tested in `tests/Unit/Similarity/NameSimilarityTest.php`.

| Pair | Score | Words | Characters | Signals |
| :--- | :---: | :---: | :---: | :--- |
| `active_users` ↔ `activeUsers` | 1.000 | 1.000 | 1.000 | style |
| `active_users` ↔ `active_user` | 1.000 | 1.000 | 1.000 | plural |
| `actve_users` ↔ `active_users` | 0.955 | 1.000 | 0.909 | shared words, typo |
| `exchange_rates` ↔ `exchange_rate_list` | 0.694 | 0.667 | 0.722 | shared words |
| `tenant_members` ↔ `member_tenants` | 0.615 | 1.000 | 0.231 | reordered |
| `active_users` ↔ `inactive_users` | 0.590 | 0.333 | 0.846 | shared words, opposite |
| `public_posts` ↔ `private_posts` | 0.417 | 0.333 | 0.500 | shared words, opposite |
| `user_profile` ↔ `user_settings` | 0.417 | 0.333 | 0.500 | shared words |
| `active_users` ↔ `active_members` | 0.513 | 0.333 | 0.692 | shared words (synonym not seen) |
| `user_profile` ↔ `member_info` | 0.083 | 0.000 | 0.167 | none (synonyms: known blind spot) |

## 7. Decision log

| # | Date | Decision | Why |
| :--- | :--- | :--- | :--- |
| D-1 | 2026-10-12 | Deterministic text similarity only; no embeddings or language models in v1 | Must give the same result in CI; no external service (ADR 0002 alternatives) |
| D-2 | 2026-10-12 | English is the target language | `purpose` is English by rule; singular rules are English |
| D-3 | 2026-10-12 | Compare definition **names** only; not `purpose` or `description` | Keep v1 precise; semantic comparison of free text needs a different technique (LRVL-49). The exact `subject` + `purpose` rule stays in the validator |
| D-4 | 2026-10-12 | Never drop words while tokenizing (no stop words) | `user_list` and `user` are different data; dropping `list`, `data`, `info` would create false positives |
| D-5 | 2026-10-12 | No stemming; singular form only, via `Str::singular()` | Stemmers (Snowball/Porter) merge unrelated identifiers (`active`/`activity`); singularization is enough and adds no dependency |
| D-6 | 2026-10-12 | Levenshtein stays order-sensitive; reordered names get their own `Reordered` signal instead of a high score | `tenant_members` and `member_tenants` are different data; treating word order as irrelevant ("token sort") would be a false positive |
| D-7 | 2026-10-12 | Soft Jaccard with fuzzy words (≥ 5 characters, ≤ 1 edit, not opposites) | Plain Jaccard scored a one-letter typo as a different word (0.63 instead of 0.96) |
| D-8 | 2026-10-12 | Signals describe the match; severity is decided by the engine | One place for policy; easier to calibrate |
| D-9 | 2026-10-12 | Opposites from curated lists, not a generic prefix rule | Generic `in-`/`dis-` matched `invoice`/`voice`, `discount`/`count` |
| D-10 | 2026-10-12 | Weights 0.5 / 0.5 as starting values, calibrated later | No data yet; labelled pairs arrive with LRVL-24 |
| D-11 | 2026-10-12 | Three severities: Error (fails CI), Warning (reported), Info (search / make only) | Two levels forced every hint into either "blocks CI" or "silent" |
| D-12 | 2026-10-12 | Policy table in section 5; style / plural variants are errors only within one section | A different section can be a deliberate split; different subjects make it very likely deliberate |
| D-13 | 2026-10-12 | Typos and reordered words warn regardless of score; shared words warn from 0.70 | Typos and reordering are specific signals; shared words alone are a weak hint. 0.70 is a starting value, tuned over time (LRVL-11) |
| D-14 | 2026-10-12 | Structural comparison = same subject + type + parameter set, exact; never without a subject; severity Info | Catches synonyms the name measures cannot see, but many different lists share a structure. Partial overlap and parameter-name similarity later (LRVL-48) |
| D-15 | 2026-10-12 | No key-collision finding; the key separator is fixed to `:` instead | Prevents the collision instead of detecting it; the engine needs no key factory |

## 8. Open work

| Issue | Topic |
| :--- | :--- |
| LRVL-11 | Calibrate weights and thresholds with labelled pairs; ADR 0010 |
| LRVL-24 | Labelled pair dataset (Barış), from the public scan |
| LRVL-48 | Abbreviation and synonym layer (`qty`, `cnt`, `users`/`members`), **planned, not v1** |
| LRVL-49 | Semantic layer on `purpose` / `description` (dense embeddings), optional |
| LRVL-50 | Splitting names without separators (`membermoneyamount`), needed for legacy keys from the scanner |
