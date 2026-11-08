<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Exceptions;

use InvalidArgumentException;

/**
 * A setting in config/cache-contract.php (other than the definitions and the
 * key format) has a value the package does not accept.
 */
final class InvalidConfiguration extends InvalidArgumentException implements CacheContractException
{
    public static function forKey(string $key, string $reason): self
    {
        return new self(sprintf('Invalid value for "cache-contract.%s": %s', $key, $reason));
    }

    public static function missingAppKey(): self
    {
        return new self('Secret parameters need the application key to hash their values: set APP_KEY (php artisan key:generate).');
    }
}
