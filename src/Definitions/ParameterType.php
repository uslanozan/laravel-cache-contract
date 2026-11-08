<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Definitions;

/**
 * Type of a definition parameter (the values in "params", ADR 0002).
 * Scalars appear readably in the key; arrays are hashed (ADR 0004); secrets
 * appear only as a keyed hash, never as their value.
 */
enum ParameterType: string
{
    case Int = 'int';
    case String = 'string';
    case Bool = 'bool';
    case Float = 'float';
    case Array = 'array';

    /** A string that must not appear in the key, such as an API token. */
    case Secret = 'secret';
}
