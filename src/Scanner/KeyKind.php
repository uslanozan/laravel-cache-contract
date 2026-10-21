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

    /** A literal with variable parts: "user:{$id}", 'feed:'.$id or sprintf('pref%d', $id). */
    case Template = 'template';

    /** Only a hash of its parts: md5($x), hash('sha256', ...). Needs a redesign before it can move into the contract. */
    case Opaque = 'opaque';

    /** No literal part at all: $key, self::KEY, a method call. */
    case Unresolved = 'unresolved';

    /** The call takes no key: flush(), clear(). */
    case None = 'none';
}
