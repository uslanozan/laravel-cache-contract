<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Similarity;

/**
 * Detects words with opposite meaning, so that names like active_users and
 * inactive_users are reported as similar but never as the same data.
 *
 * Two curated sources, chosen for precision over recall:
 * - a negating prefix (in-, un-, dis-, non-) on a known negatable word:
 *   active/inactive, paid/unpaid, connected/disconnected. Applying prefixes to any
 *   word would be wrong: discount/count and invoice/voice are not opposites.
 * - pairs that share no prefix: enabled/disabled, public/private, online/offline…
 *
 * Both lists are expected to grow with real names (LRVL-16, LRVL-24).
 */
final class Antonyms
{
    private const PREFIXES = ['in', 'un', 'dis', 'non'];

    /** Words whose prefixed form is their opposite. */
    private const NEGATABLE = [
        'active', 'valid', 'visible', 'complete', 'correct', 'secure',
        'paid', 'read', 'published', 'verified', 'approved', 'available',
        'locked', 'subscribed', 'assigned', 'confirmed', 'seen', 'sent',
        'processed', 'archived', 'resolved', 'used', 'billed', 'shipped',
        'connected', 'allowed', 'qualified',
        'blocking', 'existent', 'member',
    ];

    private const PAIRS = [
        ['enabled', 'disabled'],
        ['enable', 'disable'],
        ['public', 'private'],
        ['online', 'offline'],
        ['on', 'off'],
        ['open', 'closed'],
        ['visible', 'hidden'],
        ['allowed', 'denied'],
        ['include', 'exclude'],
        ['included', 'excluded'],
        ['input', 'output'],
        ['success', 'failure'],
        ['min', 'max'],
        ['first', 'last'],
        ['old', 'new'],
        ['true', 'false'],
    ];

    /**
     * @param  list<string>  $a  tokens of the first name
     * @param  list<string>  $b  tokens of the second name
     * @return array{string, string}|null the first opposite pair found, as [word in a, word in b]
     */
    public function find(array $a, array $b): ?array
    {
        $onlyA = array_values(array_diff($a, $b));
        $onlyB = array_values(array_diff($b, $a));

        foreach ($onlyA as $x) {
            foreach ($onlyB as $y) {
                if ($this->areOpposite($x, $y)) {
                    return [$x, $y];
                }
            }
        }

        return null;
    }

    public function areOpposite(string $x, string $y): bool
    {
        return $this->negates($x, $y) || $this->negates($y, $x) || $this->isPair($x, $y);
    }

    private function negates(string $negated, string $base): bool
    {
        if (! in_array($base, self::NEGATABLE, true)) {
            return false;
        }

        foreach (self::PREFIXES as $prefix) {
            if ($negated === $prefix.$base) {
                return true;
            }
        }

        return false;
    }

    private function isPair(string $x, string $y): bool
    {
        foreach (self::PAIRS as [$left, $right]) {
            if (($x === $left && $y === $right) || ($x === $right && $y === $left)) {
                return true;
            }
        }

        return false;
    }
}
