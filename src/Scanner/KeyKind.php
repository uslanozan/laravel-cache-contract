<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Scanner;

/**
 * How much of a cache key the scanner can read from the source.
 *
 * @internal prototype (LRVL-18), the public scanner API is designed in phase 5
 */
enum KeyKind: string
{
    /** A string literal: 'settings'. */
    case Exact = 'exact';

    /** A literal with variable parts: "user:{$id}" or 'feed:'.$id. */
    case Template = 'template';

    /** No literal part at all: $key, self::KEY, a method call. */
    case Unresolved = 'unresolved';

    /** The call takes no key: flush(), clear(). */
    case None = 'none';
}
