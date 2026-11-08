<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Definitions;

/**
 * What a definition's cached value is (the "type" field, ADR 0002).
 * Used for structural similarity in v1.
 */
enum ResultType: string
{
    case Model = 'model';
    case Collection = 'collection';
    case Array = 'array';
    case String = 'string';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
}
