<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Exceptions;

use InvalidArgumentException;

/**
 * Parameters passed at call time do not match the definition: one is
 * missing, unexpected or of the wrong type.
 */
final class InvalidParameters extends InvalidArgumentException implements CacheContractException
{
    public static function forDefinition(string $id, string $reason): self
    {
        return new self(sprintf('Invalid parameters for cache definition "%s": %s', $id, $reason));
    }
}
