<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Definitions;

/**
 * Type of a definition parameter (the values in "params", ADR 0002).
 * Scalars appear readably in the key; arrays are hashed (ADR 0004).
 */
enum ParameterType: string
{
    case Int = 'int';
    case String = 'string';
    case Bool = 'bool';
    case Float = 'float';
    case Array = 'array';
}
