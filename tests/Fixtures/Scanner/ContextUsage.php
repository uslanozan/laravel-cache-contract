<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Scanner;

use Illuminate\Support\Facades\Cache;

// Scanner fixture: the class and function each call is reported in.
Cache::get('top-level');

function warm(): void
{
    Cache::put('warm', true, 60);
}

final class ContextUsage
{
    public function handle(): void
    {
        Cache::forget('method');
        array_map(fn () => Cache::get('arrow'), []);

        $listener = new class
        {
            public function __invoke(): void
            {
                Cache::get('anonymous');
            }
        };
    }
}
