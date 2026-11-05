<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Counts the database queries a closure runs, on the default connection.
 *
 * One shared helper instead of a copy per test file: Pest test functions are
 * global, so each copy needed its own name (LRVL-74). The query log is
 * disabled again even when $operation throws.
 */
final class QueryCounter
{
    /** Number of database queries $operation runs. */
    public static function count(Closure $operation): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $operation();
        } finally {
            DB::disableQueryLog();
        }

        return count(DB::getQueryLog());
    }
}
