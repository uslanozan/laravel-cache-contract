<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Exceptions;

use InvalidArgumentException;

/**
 * The "key_format" in config/cache-contract.php cannot produce unique keys
 * or uses a placeholder that does not exist (ADR 0004).
 */
final class InvalidKeyFormat extends InvalidArgumentException implements CacheContractException
{
    public static function because(string $format, string $reason): self
    {
        return new self(sprintf('Invalid cache key format "%s": %s', $format, $reason));
    }
}
