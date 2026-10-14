# 0008. The model cache stores attributes, not serialized models

- **Status:** proposed
- **Date:** 2026-10-14

## Context

`ModelCache` (ADR 0006) writes Eloquent models to Redis and reads them back.
Before it is built we have to decide what an entry holds: the whole model
object, or only its column values. ADR 0007 (proposed) wraps every value in a
one-element envelope; this decision is about what goes inside it and holds
either way.

A spike (LRVL-34) compared both on one model with array, boolean, enum and
datetime casts, a hidden `password` column and a `belongsTo` relation, on
PHP 8.3.35, Laravel 13.36, phpredis and Redis 7.4.11. Size and speed are
single-machine measurements; every other row is pinned by
[`EloquentModelSerializationTest`](../../tests/Integration/EloquentModelSerializationTest.php),
which CI runs on Laravel 11, 12 and 13.

| | (a) `serialize()` the model | (b) `getAttributes()`, rebuilt with `newFromBuilder()` |
| :--- | :--- | :--- |
| Size of one entry | 1830 bytes (2861 with the relation loaded) | **388 bytes** |
| Write + read through Redis | **175 µs** | 199 µs |
| Casts on read | correct | correct |
| Cast changed in the class after the write | **old cast is used**: the object's configuration is stored in the entry | current cast is used |
| Class renamed or removed | **`__PHP_Incomplete_Class`** | works with the class the caller asks for |
| Loaded relations | **embedded**, and stale after the related row changes | not stored |
| Hidden columns (`password`) | written to Redis | written to Redis |
| `retrieved` event | not fired | fired, as for a database read |

Apps can restrict which classes the cache may unserialize
(`cache.serializable_classes`) only from Laravel 12.x onwards: the option is in
12.60.0 and 13.0.0 but not in 11.57.0 or 12.0.0. Our users run Laravel 11
(ADR 0003), where option (a) cannot be restricted. Where it is restricted,
(a) only works if every cached model class is on the allowed list; otherwise
PHP returns `__PHP_Incomplete_Class`.

## Options

### A. Serialize the whole model

- Pros: no extra code; the read is about 24 µs faster.
- Cons: every class change can serve the old configuration or an unusable
  object until the TTL ends, unless someone remembers to bump the version.
  Relations are cached with the model and go stale, which roadmap principle 1
  counts as wrong data. Nearly five times larger. Needs unrestricted
  unserialization.

### B. Store the attributes and rebuild

- Pros: the entry holds plain values only, so it is small, survives class
  refactors and needs no unserializing of objects. A rebuilt model behaves
  like one read from the database. This is what the team research report
  recommended.
- Cons: a little slower per read. A table change still needs a version bump.

## Decision

**Option B.**

- An entry holds the model's raw attributes (`getAttributes()`, the column
  values as the database returned them) inside the ADR 0007 envelope. Reading
  rebuilds the model with `newFromBuilder()` on the class the caller asked for
  and on the model's connection.
- `ModelCache` writes only models it loaded from the database itself, never an
  instance passed in by the caller, so unsaved changes are never cached.
- **Relations are never stored in a model entry.** A related model is cached
  under its own key and loaded separately; the API comes with `ModelCache` in
  phase 2.
- **Hidden columns are stored like any other column:** the entry is a copy of
  the row. Leaving columns out would hand back models that silently miss data
  (for example a `password` used for a login check). Excluding columns per
  model is not part of v1; if it is needed, it gets its own ADR.
- **When to bump the version (ADR 0004):** when the table's columns change (a
  column is added, removed or renamed, or its stored format changes). Changes
  to the class alone (casts, accessors, `$hidden`, the class name) need no
  bump.

## Consequences

- Entries are about five times smaller than whole models in the spike.
- Each read costs about 24 µs more than option A: small next to a database
  round trip, but measurable. Revisit if profiling shows it matters.
- `retrieved` listeners and observers run on every cache hit, as they do for
  database reads; heavy work in them slows cache hits too.
- The docs must say that Redis holding the model cache contains full rows,
  hidden columns included, and must be protected like the database.
- The docs must list table changes as a reason to bump the version; a later
  lint rule could catch migrations that touch cached tables.
- Entries contain no objects, so apps on Laravel versions with
  `cache.serializable_classes` can turn object unserialization off.
