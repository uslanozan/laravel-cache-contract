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
    /** Known in full: 'settings', a constant of the same class, a variable assigned a literal once. */
    case Exact = 'exact';

    /** A literal with runtime parts, written as "feed:{id}" for 'feed:'.$id, "user:{$id}" or sprintf('feed:%d', $id). */
    case Template = 'template';

    /** Only a hash of its parts: md5($x), hash('sha256', ...). Needs a redesign before it can move into the contract. */
    case Opaque = 'opaque';

    /** No literal part to read: a parameter, a key-builder call, a variable written more than once. */
    case Unresolved = 'unresolved';

    /** The call takes no key: flush(), clear(). */
    case None = 'none';
}
