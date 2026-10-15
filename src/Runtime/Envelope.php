<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Runtime;

/**
 * The one place that turns values into cache entries and back (ADR 0007).
 *
 * Every value is stored as a one-element array, [value], so null, false, 0,
 * '' and [] come back exactly as written, under any Redis serializer. An
 * absent key is the only miss; anything that is not an envelope (an entry
 * written by other code or by a future format) is treated as a miss too.
 *
 * @internal
 */
final class Envelope
{
    /**
     * @return array{0: mixed}
     */
    public static function wrap(mixed $value): array
    {
        return [$value];
    }

    /**
     * @phpstan-assert-if-true array{0: mixed} $entry
     */
    public static function isHit(mixed $entry): bool
    {
        return is_array($entry) && count($entry) === 1 && array_key_exists(0, $entry);
    }

    /**
     * @param  array{0: mixed}  $entry
     */
    public static function unwrap(array $entry): mixed
    {
        return $entry[0];
    }
}
