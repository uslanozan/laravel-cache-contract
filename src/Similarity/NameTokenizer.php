<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Similarity;

use Illuminate\Support\Str;

/**
 * Splits a name into comparable words, whatever its style:
 *
 *     activeUsers, active-users, ACTIVE_USERS, active.users → [active, user]
 *
 * 1. camelCase and PascalCase boundaries ("HTTPRequest" → "HTTP Request")
 * 2. any non-alphanumeric character separates words
 * 3. lowercase
 * 4. singular form via Str::singular() (Doctrine Inflector, English);
 *    no stemming, which would merge unrelated words like active/activity
 *
 * Letters and digits stay together ("oauth2"). Nothing is dropped.
 */
final class NameTokenizer
{
    public function tokenize(string $name): TokenizedName
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $name) ?? $name;
        $parts = preg_split('/[^A-Za-z0-9]+/', $spaced, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $words = array_map(strtolower(...), $parts);
        $tokens = array_map(
            fn (string $word): string => ctype_alpha($word) ? strtolower(Str::singular($word)) : $word,
            $words,
        );

        return new TokenizedName($name, $words, $tokens);
    }
}
