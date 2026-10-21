<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Definitions;

/**
 * Reads a TTL from the config: a positive number of seconds, or a duration
 * with units that says what it means (ADR 0002, update 2026-10-21):
 *
 *     3600, '1 hour', '15 minutes', '1h 30m', '2 weeks'
 *
 * Units: seconds, minutes, hours, days and weeks, long or short. Months and
 * years are rejected because their length in seconds is not fixed, and a
 * bare numeric string ('300') is rejected because its unit is not visible.
 *
 * A TTL is at most 365 days. It catches typos such as an extra factor of
 * 1000, a total that no longer fits in an int, and values Redis rejects
 * (SETEX with PHP_INT_MAX fails on every write).
 */
final class Ttl
{
    private const SECONDS = [
        's' => 1, 'sec' => 1, 'secs' => 1, 'second' => 1, 'seconds' => 1,
        'm' => 60, 'min' => 60, 'mins' => 60, 'minute' => 60, 'minutes' => 60,
        'h' => 3600, 'hr' => 3600, 'hrs' => 3600, 'hour' => 3600, 'hours' => 3600,
        'd' => 86400, 'day' => 86400, 'days' => 86400,
        'w' => 604800, 'week' => 604800, 'weeks' => 604800,
    ];

    private const NOT_FIXED = ['mo', 'month', 'months', 'y', 'yr', 'yrs', 'year', 'years'];

    /** 365 days: longer than any sensible cache entry, far below what Redis or an int can hold. */
    public const MAX_SECONDS = 31_536_000;

    private const TOO_LONG = 'must be at most 365 days (31536000 seconds)';

    /** Largest units first, for forHumans(). */
    private const NAMES = [604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute', 1 => 'second'];

    /**
     * Why $value is not a valid TTL, or null when it is.
     */
    public static function problem(mixed $value): ?string
    {
        return self::parse($value)[1];
    }

    /**
     * The TTL in seconds; call problem() first.
     *
     * @return positive-int
     */
    public static function seconds(mixed $value): int
    {
        [$seconds, $problem] = self::parse($value);

        if ($seconds === null) {
            throw new \InvalidArgumentException((string) $problem);
        }

        return $seconds;
    }

    /** "1 day", "1 hour 30 minutes", "45 seconds". */
    public static function forHumans(int $seconds): string
    {
        $parts = [];

        foreach (self::NAMES as $size => $name) {
            if ($seconds >= $size) {
                $count = intdiv($seconds, $size);
                $seconds %= $size;
                $parts[] = $count.' '.$name.($count === 1 ? '' : 's');
            }
        }

        return $parts === [] ? '0 seconds' : implode(' ', $parts);
    }

    /**
     * @return array{0: positive-int, 1: null}|array{0: null, 1: string}
     */
    private static function parse(mixed $value): array
    {
        if (is_int($value)) {
            return match (true) {
                $value <= 0 => [null, 'must be positive (entries without expiry are not allowed)'],
                $value > self::MAX_SECONDS => [null, self::TOO_LONG],
                default => [$value, null],
            };
        }

        if (! is_string($value)) {
            return [null, 'must be a positive number of seconds or a duration such as "15 minutes" or "1 day" (entries without expiry are not allowed)'];
        }

        $text = strtolower(trim($value));

        if (preg_match('/^\d+$/', $text) === 1) {
            return [null, sprintf('"%s" needs a unit, e.g. "%s seconds", or write it as the number %s', $value, $text, $text)];
        }

        // One or more "<number> <unit>" parts and nothing else: "1h 30m", "2 hours".
        if (preg_match('/^(?:\d+\s*[a-z]+\s*)+$/', $text) !== 1) {
            return [null, sprintf('"%s" is not a duration; use e.g. "15 minutes", "6 hours" or "1 day"', $value)];
        }

        preg_match_all('/(\d+)\s*([a-z]+)/', $text, $parts, PREG_SET_ORDER);
        $seconds = 0;

        foreach ($parts as [, $amount, $unit]) {
            if (in_array($unit, self::NOT_FIXED, true)) {
                return [null, sprintf('"%s": months and years have no fixed length; use days or weeks, e.g. "30 days"', $value)];
            }

            if (! isset(self::SECONDS[$unit])) {
                return [null, sprintf('"%s": unknown unit "%s"; use seconds, minutes, hours, days or weeks', $value, $unit)];
            }

            // Checked before adding, so the total never grows into a float.
            // (int) of a huge digit string saturates at PHP_INT_MAX.
            if ((int) $amount > intdiv(self::MAX_SECONDS - $seconds, self::SECONDS[$unit])) {
                return [null, sprintf('"%s": %s', $value, self::TOO_LONG)];
            }

            $seconds += (int) $amount * self::SECONDS[$unit];
        }

        return $seconds > 0 ? [$seconds, null] : [null, 'must be positive (entries without expiry are not allowed)'];
    }
}
