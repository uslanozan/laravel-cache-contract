<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Similarity;

/**
 * What kind of resemblance two names have. A comparison can raise several.
 *
 * Signals only describe; how serious each one is (error, warning, hint) is
 * decided by the engine (LRVL-10, ADR 0010). See docs/similarity-engine.md.
 */
enum NameSignal: string
{
    /** Exactly the same name. */
    case Identical = 'identical';

    /** Same words, different style: active_users ↔ activeUsers. */
    case StyleVariant = 'style_variant';

    /** Differs only by singular/plural: active_user ↔ active_users. */
    case PluralVariant = 'plural_variant';

    /** A word one character apart: actve_users ↔ active_users. */
    case Typo = 'typo';

    /**
     * Same words in a different order: tenant_members ↔ member_tenants.
     * Order can change the meaning, so this is never treated as the same data.
     */
    case Reordered = 'reordered';

    /** Contains opposite words: active_users ↔ inactive_users. */
    case Opposite = 'opposite';

    /** Some words in common, nothing stronger: exchange_rates ↔ exchange_rate_list. */
    case SharedWords = 'shared_words';
}
