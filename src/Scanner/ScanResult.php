<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Scanner;

/**
 * The cache calls found under a set of paths, and the files that could not
 * be scanned.
 *
 * @internal prototype (LRVL-18), the public scanner API is designed in phase 5
 */
final readonly class ScanResult
{
    /**
     * @param  list<CacheUsage>  $usages
     * @param  array<string, string>  $errors  file or path => why it was skipped
     */
    public function __construct(
        public array $usages,
        public array $errors,
    ) {}
}
