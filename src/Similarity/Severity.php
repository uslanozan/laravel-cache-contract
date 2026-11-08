<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Similarity;

/**
 * How serious a finding is (docs/similarity-engine.md, decision D-11).
 *
 * - Error: a certain duplicate. Lint fails CI.
 * - Warning: probably a duplicate, worth a look. Lint reports, CI passes.
 * - Info: for the developer's awareness, likely intentional. Shown by the
 *   search and make commands only.
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';

    /** Sort order: most serious first. */
    public function rank(): int
    {
        return match ($this) {
            self::Error => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }
}
