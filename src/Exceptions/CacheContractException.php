<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Exceptions;

use Throwable;

/**
 * Implemented by every exception this package throws, so applications can
 * catch all of them with one type.
 */
interface CacheContractException extends Throwable {}
