<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Similarity;

/**
 * Why two definitions were reported together.
 *
 * Collisions are proven by rules and are errors. Similarity is a hint and
 * only ever a warning: it never blocks on its own.
 */
enum FindingType: string
{
    /** Same "section.name" ID. */
    case IdentifierCollision = 'identifier_collision';

    /** Different IDs that generate the same physical key. */
    case KeyCollision = 'key_collision';

    /** Same subject + purpose: the same data under two names (ADR 0002). */
    case PurposeCollision = 'purpose_collision';

    /** Names that look alike (spelling, word order, separators). */
    case LexicalSimilarity = 'lexical_similarity';

    /** Same subject, parameters and result type under different names. */
    case StructuralDuplicateCandidate = 'structural_duplicate_candidate';

    public function isError(): bool
    {
        return match ($this) {
            self::IdentifierCollision, self::KeyCollision, self::PurposeCollision => true,
            self::LexicalSimilarity, self::StructuralDuplicateCandidate => false,
        };
    }
}
