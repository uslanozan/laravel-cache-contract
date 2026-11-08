<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Exceptions;

use InvalidArgumentException;

/**
 * A cache definition ID that is not in the contract. The package never falls
 * back to an arbitrary key.
 */
final class UnknownDefinition extends InvalidArgumentException implements CacheContractException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Unknown cache definition "%s". Define it in config/cache-contract.php first.', $id));
    }
}
