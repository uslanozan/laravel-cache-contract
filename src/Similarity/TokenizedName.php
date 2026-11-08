<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Similarity;

/**
 * A name split into words, e.g. "activeUsers":
 * words ["active", "users"], tokens ["active", "user"], normalized "active_user".
 */
final readonly class TokenizedName
{
    /**
     * @param  list<string>  $words  lowercase words as written
     * @param  list<string>  $tokens  the same words in singular form
     */
    public function __construct(
        public string $original,
        public array $words,
        public array $tokens,
    ) {}

    /** Tokens joined with "_": the name with style and plural removed. */
    public function normalized(): string
    {
        return implode('_', $this->tokens);
    }
}
