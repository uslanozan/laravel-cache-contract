<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Scanner;

/**
 * One cache call found in the source.
 *
 * @internal prototype (LRVL-18), the public scanner API is designed in phase 5
 */
final readonly class CacheUsage
{
    /**
     * @param  string|null  $class  the class the call is in, null outside a class or in an anonymous one
     * @param  string|null  $function  the method or function the call is in; a closure reports the one around it
     * @param  string  $method  the called method, "cache" for the helper itself
     * @param  string|null  $key  the key for an exact key, "feed:{id}" for a template, the printed expression otherwise
     */
    public function __construct(
        public string $file,
        public int $line,
        public ?string $class,
        public ?string $function,
        public CallStyle $style,
        public string $method,
        public Operation $operation,
        public KeyKind $keyKind,
        public ?string $key,
    ) {}
}
