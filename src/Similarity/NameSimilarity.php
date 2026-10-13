<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Similarity;

/**
 * Scores how alike two names are (0.0 to 1.0) and explains why.
 *
 *     score = WORD_WEIGHT × soft Jaccard(words) + CHARACTER_WEIGHT × normalized Levenshtein(names)
 *
 * - Soft Jaccard (Jaccard, 1901): shared words / all distinct words, where two
 *   long words at most one edit apart count as the same word (typos).
 *   Catches reordered names: user_profile ↔ profile_user.
 * - Normalized Levenshtein (Levenshtein, 1965): 1 − edits / longer length,
 *   on the normalized names. Catches typos: actve_users ↔ active_users.
 *
 * Opposite words (active/inactive) are reported, never hidden: the score may
 * be high, the engine decides what that means. Pure and deterministic.
 *
 * The constants are starting values, to be calibrated against labelled
 * pairs (LRVL-11). Rationale: docs/similarity-engine.md.
 */
final class NameSimilarity
{
    public const WORD_WEIGHT = 0.5;

    public const CHARACTER_WEIGHT = 0.5;

    /** Words shorter than this must match exactly ("user" ≠ "usr"). */
    public const FUZZY_WORD_MIN_LENGTH = 5;

    /** Maximum edits for two words to count as the same word. */
    public const FUZZY_WORD_MAX_DISTANCE = 1;

    public function __construct(
        private readonly NameTokenizer $tokenizer = new NameTokenizer,
        private readonly Antonyms $antonyms = new Antonyms,
    ) {}

    public function compare(string $a, string $b): NameScore
    {
        $left = $this->tokenizer->tokenize($a);
        $right = $this->tokenizer->tokenize($b);

        $opposite = $this->antonyms->find($left->tokens, $right->tokens);
        [$wordOverlap, $shared, $fuzzy] = $this->softJaccard($left->tokens, $right->tokens);
        [$characterSimilarity, $distance] = $this->characterSimilarity($left->normalized(), $right->normalized());

        $score = round(self::WORD_WEIGHT * $wordOverlap + self::CHARACTER_WEIGHT * $characterSimilarity, 3);

        return new NameScore(
            score: $score,
            signals: $this->signals($left, $right, $shared, $fuzzy, $opposite),
            wordOverlap: round($wordOverlap, 3),
            characterSimilarity: round($characterSimilarity, 3),
            opposite: $opposite,
            reasons: $this->reasons($left, $right, $shared, $fuzzy, $distance, $opposite),
        );
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return array{float, list<string>, list<array{string, string}>} score, shared words, fuzzy pairs
     */
    private function softJaccard(array $a, array $b): array
    {
        $a = array_values(array_unique($a));
        $b = array_values(array_unique($b));

        $shared = array_values(array_intersect($a, $b));
        $restA = array_values(array_diff($a, $shared));
        $restB = array_values(array_diff($b, $shared));

        $fuzzy = [];
        foreach ($restA as $x) {
            foreach ($restB as $i => $y) {
                if ($this->sameWord($x, $y)) {
                    $fuzzy[] = [$x, $y];
                    unset($restB[$i]);

                    break;
                }
            }
        }

        $matches = count($shared) + count($fuzzy);
        $union = count($a) + count($b) - $matches;

        return [$union === 0 ? 0.0 : $matches / $union, $shared, $fuzzy];
    }

    private function sameWord(string $x, string $y): bool
    {
        return strlen($x) >= self::FUZZY_WORD_MIN_LENGTH
            && strlen($y) >= self::FUZZY_WORD_MIN_LENGTH
            && levenshtein($x, $y) <= self::FUZZY_WORD_MAX_DISTANCE
            && ! $this->antonyms->areOpposite($x, $y);
    }

    /**
     * @return array{float, int} similarity, edit distance
     */
    private function characterSimilarity(string $a, string $b): array
    {
        $longer = max(strlen($a), strlen($b));
        $distance = levenshtein($a, $b);

        return [$longer === 0 ? 1.0 : 1 - $distance / $longer, $distance];
    }

    /**
     * @param  list<string>  $shared
     * @param  list<array{string, string}>  $fuzzy
     * @param  array{string, string}|null  $opposite
     * @return list<NameSignal>
     */
    private function signals(TokenizedName $left, TokenizedName $right, array $shared, array $fuzzy, ?array $opposite): array
    {
        $signals = [];

        if ($left->original === $right->original) {
            $signals[] = NameSignal::Identical;
        } elseif ($left->normalized() === $right->normalized()) {
            $signals[] = $left->words === $right->words ? NameSignal::StyleVariant : NameSignal::PluralVariant;
        } elseif ($this->sameWordsInAnotherOrder($left->tokens, $right->tokens)) {
            $signals[] = NameSignal::Reordered;
        } elseif ($shared !== []) {
            $signals[] = NameSignal::SharedWords;
        }

        if ($fuzzy !== []) {
            $signals[] = NameSignal::Typo;
        }

        if ($opposite !== null) {
            $signals[] = NameSignal::Opposite;
        }

        return $signals;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function sameWordsInAnotherOrder(array $a, array $b): bool
    {
        if ($a === $b) {
            return false;
        }

        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * @param  list<string>  $shared
     * @param  list<array{string, string}>  $fuzzy
     * @param  array{string, string}|null  $opposite
     * @return list<string>
     */
    private function reasons(TokenizedName $left, TokenizedName $right, array $shared, array $fuzzy, int $distance, ?array $opposite): array
    {
        $reasons = [];

        if ($left->original === $right->original) {
            $reasons[] = 'identical names';
        } elseif ($left->normalized() === $right->normalized()) {
            $reasons[] = $left->words === $right->words
                ? 'same words in a different style'
                : 'differs only by singular/plural';
        } else {
            if ($this->sameWordsInAnotherOrder($left->tokens, $right->tokens)) {
                $reasons[] = 'same words in a different order (the order can change the meaning)';
            } elseif ($shared !== []) {
                $reasons[] = 'shares the words: '.implode(', ', $shared);
            }

            foreach ($fuzzy as [$x, $y]) {
                $edits = levenshtein($x, $y);
                $reasons[] = sprintf('%s ≈ %s (%d %s apart)', $x, $y, $edits, $edits === 1 ? 'character' : 'characters');
            }

            if ($distance > 0 && $distance <= 2) {
                $reasons[] = sprintf('names are %d %s apart', $distance, $distance === 1 ? 'character' : 'characters');
            }
        }

        if ($opposite !== null) {
            $reasons[] = sprintf('opposite meaning: %s / %s', $opposite[0], $opposite[1]);
        }

        return $reasons;
    }
}
