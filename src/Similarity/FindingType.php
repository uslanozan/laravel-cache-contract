<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Similarity;

/**
 * Why an existing definition was reported against a candidate. How serious
 * it is lives on the Finding (Severity), because the same type can be more
 * or less serious depending on context (NameVariant in the same section or
 * in another one).
 *
 * Key collisions are not a finding type: the ":" separator makes them
 * impossible (ADR 0004 update, 2026-10-12).
 */
enum FindingType: string
{
    /** Same "section.name" ID. */
    case IdentifierCollision = 'identifier_collision';

    /** Same subject + purpose: the same data under two names (ADR 0002). */
    case PurposeCollision = 'purpose_collision';

    /** Same name apart from style or singular/plural: active_user ↔ active_users. */
    case NameVariant = 'name_variant';

    /** Names that look alike: a typo, reordered words or enough shared words. */
    case LexicalSimilarity = 'lexical_similarity';

    /** Similar names with opposite words: active_users ↔ inactive_users. */
    case OppositeMeaning = 'opposite_meaning';

    /** Same subject, result type and parameters under different names. */
    case StructuralDuplicateCandidate = 'structural_duplicate_candidate';
}
