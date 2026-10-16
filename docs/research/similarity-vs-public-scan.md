# Similarity engine vs. the public scan

The public scan ([cache-usage-in-the-wild.md](cache-usage-in-the-wild.md)) ends
each repository with *similarity pair candidates*: real or realistic key pairs
labelled `distinct`, `duplicate` or `similar`. This note runs all of them
through the current similarity engine ([similarity-engine.md](../similarity-engine.md))
to see whether it reports what a reviewer would want. It is the first test of
the engine on real naming, ahead of the labelled dataset (LRVL-24) and the
threshold calibration (LRVL-11).

**Result:** the engine reports no false error. It catches every duplicate. Its
only misses are three pairs that share meaning but hardly any words.

| | Pairs | ok | mild | miss | bad |
| :--- | ---: | ---: | ---: | ---: | ---: |
| `duplicate` | 7 | 7 | | | |
| `distinct` | 12 | 12 | 0 | | 0 |
| `similar` | 3 | | | 3 | |
| **All** | **22** | **19** | **0** | **3** | **0** |

## Method

- **Pairs:** every row of the six "Similarity pair candidates" tables. A row
  with three keys becomes three pairs. Three `duplicate (if verified)` rows are
  left out because both sides are the *same string* written in two places.
  They are a scanner finding (one key, several call sites), not a naming
  question:
  - Firefly III `rate-{from}-{to}-{date}`
  - Pixelfed `avatar:{id}`
  - Akaunting `apps.installed.{companyId}`
- **From key to definition:** the engine compares definitions, so each key is
  mapped to the definition a team would write for it:
  - Placeholders (`{id}`, `{k}`, `{alias}`) become parameters, so they are
    dropped from the name.
  - Project prefixes (`pf:`) are dropped; in the contract they belong to the
    key format's `{prefix}`.
  - The remaining words keep their original style (`nextDateMatch`,
    `api-docs`), because that is exactly what the engine has to see through.
  - Both sides share a section, unless the report says they live in different
    layers (Firefly III's helper vs. chart, Pixelfed's settings vs. transformer).
  - The mapping is visible in the table below and in the script.
- **Verdict:**

  | Label | ok | otherwise |
  | :--- | :--- | :--- |
  | `duplicate` | a `name_variant` finding | **miss** |
  | `similar` | any finding (shown next to each other) | **miss** |
  | `distinct` | no finding, or `info` | **mild** for a `warning`, **bad** for an `error` (fails CI) |

- **Engine:** `DefaultSimilarityEngine` with its current constants
  (`SHARED_WORDS_THRESHOLD` = 0.70; word and character weight 0.5 each; a typo
  is at most one edit in words of five or more letters).
- **Reproduce:** `docker compose exec php php docs/research/similarity-vs-public-scan.php`
  ([script](similarity-vs-public-scan.php)) prints the table below.

## Results

| Project | Keys | Definitions compared | Label | Score | Signals | Finding | Verdict |
| :--- | :--- | :--- | :--- | ---: | :--- | :--- | :--- |
| BookStack | `custom-head-web:{hash}` / `custom-head-export:{hash}` | `page.custom-head-web` / `page.custom-head-export` | distinct | 0.58 | shared_words | none | ok |
| BookStack | `api-docs::{v}` / `api_docs:{v}` | `docs.api-docs` / `docs.api_docs` | duplicate | 1.00 | style_variant | name_variant (error) | ok |
| BookStack | `oidc-discovery::{issuer}` / `oidc-config::{issuer}` | `auth.oidc-discovery` / `auth.oidc-config` | similar | 0.42 | shared_words | none | miss |
| Firefly III | `report.expenses-by-period` / `report.income-by-period` | `report.expenses-by-period` / `report.income-by-period` | distinct | 0.57 | shared_words | none | ok |
| Firefly III | `balance-summary` / `chart.report.balance-summary` | `helper.balance-summary` / `chart.report.balance-summary` | distinct | 0.67 | shared_words | none | ok |
| Firefly III | `nextDateMatch` / `next-date-match` | `bill.nextDateMatch` / `bill.next-date-match` | duplicate | 1.00 | style_variant | name_variant (error) | ok |
| Firefly III | `journal.meta-updated` / `journal.field-updated` | `journal.meta-updated` / `journal.field-updated` | similar | 0.47 | shared_words | none | miss |
| Monica | `ImportantDateType:{v}:{t}` / `important-date-type:{v}:{t}` | `vault.ImportantDateType` / `vault.important-date-type` | duplicate | 1.00 | style_variant | name_variant (error) | ok |
| Koel | `passkey login options` / `passkey confirmation options` | `auth.passkey login options` / `auth.passkey confirmation options` | distinct | 0.58 | shared_words | none | ok |
| Koel | `passkey login options` / `passkey registration options` | `auth.passkey login options` / `auth.passkey registration options` | distinct | 0.58 | shared_words | none | ok |
| Koel | `passkey confirmation options` / `passkey registration options` | `auth.passkey confirmation options` / `auth.passkey registration options` | distinct | 0.62 | shared_words | none | ok |
| Koel | `spotify.access_token` / `dropbox_access_token` | `services.spotify.access_token` / `services.dropbox_access_token` | distinct | 0.60 | shared_words | none | ok |
| Koel | `dropbox_access_token` / `dropbox.access-token` | `services.dropbox_access_token` / `services.dropbox.access-token` | duplicate | 1.00 | style_variant | name_variant (error) | ok |
| Koel | `two-factor login token` / `one-time token` | `auth.two-factor login token` / `auth.one-time token` | similar | 0.27 | shared_words | none | miss |
| Pixelfed | `profile:following_count:{id}` / `profile:follower_count:{id}` | `profile.following_count` / `profile.follower_count` | distinct | 0.57 | shared_words | none | ok |
| Pixelfed | `pf:acct:settings:hidden-following:{id}` / `pf:acct-trans:hideFollowing:{id}` | `acct_settings.hidden-following` / `acct_trans.hideFollowing` | distinct | 0.60 | shared_words | none | ok |
| Pixelfed | `api:v1:instance-data-response-v1` / `api_v1_instance_data_response_v1` | `api.v1:instance-data-response-v1` / `api.v1_instance_data_response_v1` | duplicate | 1.00 | style_variant | name_variant (error) | ok |
| Akaunting | `apps.testimonials.{k}` / `apps.{alias}.testimonials.{k}` | `apps.testimonials` / `apps.app_testimonials` | distinct | 0.62 | shared_words | none | ok |
| Akaunting | `apps.pre_sale.{k}` / `apps.paid.{k}` | `apps.pre_sale` / `apps.paid` | distinct | 0.12 | - | none | ok |
| Akaunting | `apps.pre_sale.{k}` / `apps.free.{k}` | `apps.pre_sale` / `apps.free` | distinct | 0.19 | - | none | ok |
| Akaunting | `apps.paid.{k}` / `apps.free.{k}` | `apps.paid` / `apps.free` | distinct | 0.00 | - | none | ok |
| Akaunting | `plans.limits` / `plans-limits` | `billing.plans.limits` / `billing.plans-limits` | duplicate | 1.00 | style_variant | name_variant (error) | ok |

## Findings

1. **Every duplicate is caught as an error.** All seven `duplicate` pairs differ
   only in style: case, separators, camelCase. The tokenizer reduces both sides
   to the same words, the engine reports `style_variant`, and the finding is
   an `error` because both sit in the same section. This is the most common
   real problem in the scan, and it would fail CI.
2. **No false error, no false warning.** None of the twelve hard negatives
   produces a finding. `following_count` / `follower_count` was the pair most
   likely to fail: it is *not* read as a typo, because the words are three
   edits apart and the typo rule allows one. The scores of the hard negatives
   (0.00–0.67) all stay below the 0.70 threshold.
3. **The three `similar` misses are about meaning, not spelling.**
   `oidc-discovery` / `oidc-config` (0.42), `meta-updated` / `field-updated`
   (0.47) and `two-factor login token` / `one-time token` (0.27) share one
   word or none. They score **lower than most hard negatives**, so no threshold
   on this score can catch them without flagging all the hard negatives too.
   This is the limit of a lexical engine, as expected by design (names only,
   no synonyms yet).
4. **The threshold has little room below it.** The highest hard negative is
   `helper.balance-summary` / `chart.report.balance-summary` at 0.67, only 0.03
   under the threshold. Lowering the threshold to 0.65 would already turn it
   into a warning.
5. **The contract removes one class of problem by itself.** Akaunting's
   `apps.testimonials.{k}` vs. `apps.{alias}.testimonials.{k}` ("same words,
   different scope") cannot exist as written: `{alias}` becomes a parameter, so
   both would be `apps.testimonials`. The registry rejects that as a duplicate
   ID, and the team has to name the second one differently.

## What this means for the next issues

- **LRVL-11 (calibration):** keep `SHARED_WORDS_THRESHOLD` at 0.70 or higher
  unless the labelled dataset shows otherwise. Real hard negatives reach 0.67.
  Add the 0.67 pair to the dataset as a boundary case.
- **LRVL-24 (dataset):** all 22 pairs can be copied as they are, with the
  report's label and the reason from its table. Prefer them over invented
  pairs: they are the first ones taken from real naming.
- **LRVL-49 (semantic layer):** the three misses are its first real test cases.
  A purpose/description comparison or a small synonym list (`discovery` ~
  `config`, `two-factor` ~ `one-time`) is what would connect them; spelling
  rules will not.
- **LRVL-26 (`search`):** "show related definitions side by side", which the
  report asks for in the `similar` rows, fits a search over all definitions in
  a section better than a CI finding does.

No engine bug was found, so no fix issue is opened from this note.

## Limits

- 22 pairs is a small sample, and several pairs are synthetic in the report
  (marked there). The labels are the report's, not a second reviewer's.
- The mapping from key to definition is a judgement call. It keeps the key's
  words and style; a team that renames while migrating would give the engine
  an easier task.
- Only name similarity is tested. Every definition here has the same type and
  no subject, so subject, purpose and structural findings are not exercised.
