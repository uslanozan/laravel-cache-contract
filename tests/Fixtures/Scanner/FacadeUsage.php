<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Scanner;

use Illuminate\Support\Facades\Cache;

// Scanner fixture: parsed by ScannerTest, never executed.
final class FacadeUsage
{
    public function handle(int $id, string $key): void
    {
        Cache::get('settings');
        Cache::put("user:{$id}:profile", [], 3600);
        Cache::remember('feed:'.$id, 3600, fn () => []);
        Cache::forget($key);
        Cache::store('redis')->add('warmup:lock', true, 60);
        Cache::tags(['posts'])->flush();
        \Cache::increment('visits');
        Cache::get(sprintf('preference%d%s', $id, $key));
        Cache::forever(hash('sha256', $key), []);
    }
}
