<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Scanner;

/**
 * One cache call found in the source.
 *
 * @internal prototype (LRVL-18), the public scanner API is designed in phase 5
 */
final readonly class Usage
{
    /**
     * @param  string  $method  the called method, "cache" for the helper itself
     * @param  string|null  $key  the literal for an exact key, the printed expression otherwise
     */
    public function __construct(
        public string $file,
        public int $line,
        public CallStyle $style,
        public string $method,
        public Operation $operation,
        public KeyKind $keyKind,
        public ?string $key,
    ) {}
}
