<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Exceptions;

use InvalidArgumentException;

/**
 * A tag that is not declared under "tags" in config/cache-contract.php. A
 * typo must fail loudly: otherwise nothing would be invalidated, and nobody
 * would notice.
 */
final class UnknownTag extends InvalidArgumentException implements CacheContractException
{
    public static function withName(string $name, ?string $suggestion = null): self
    {
        return new self(sprintf(
            'Unknown cache tag "%s".%s Declare it under "tags" in config/cache-contract.php first.',
            $name,
            $suggestion === null ? '' : sprintf(' Did you mean "%s"?', $suggestion),
        ));
    }
}
