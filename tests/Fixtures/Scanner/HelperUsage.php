<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Scanner;

// Scanner fixture: parsed by ScannerTest, never executed.
final class HelperUsage
{
    public function handle(int $id): void
    {
        cache('settings');
        cache(['settings' => []], 600);
        cache()->remember("team:{$id}", 600, fn () => []);
        cache()->store('file')->forget('settings');
    }
}
