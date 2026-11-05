<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Runtime;

/**
 * The value a removal leaves at a key instead of deleting it (ADR 0012): a
 * fixed string followed by a random token, new for every removal.
 *
 * It is not null and not an envelope, so every read treats it as a miss, and
 * a loader that read before the removal finds the key changed and does not
 * write its old value back.
 *
 * @internal
 */
final class Tombstone
{
    public const PREFIX = 'cache-contract:tombstone:';

    public static function make(): string
    {
        return self::PREFIX.bin2hex(random_bytes(16));
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    public static function is(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }
}
