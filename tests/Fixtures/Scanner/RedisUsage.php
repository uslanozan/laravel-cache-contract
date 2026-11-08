<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Scanner;

use Illuminate\Support\Facades\Redis;

// Scanner fixture: parsed by ScannerTest, never executed.
final class RedisUsage
{
    public function handle(int $id, int $postId): void
    {
        Redis::get('online:count');
        Redis::zadd("feed:{$id}", time(), $postId);
        Redis::connection('cache')->del('online:count');
    }
}
