<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Scanner;

use Cache;
use Illuminate\Support\Facades\Cache as AppCache;
use Illuminate\Support\Facades\Redis as Kv;

// Scanner fixture: the facades imported under the global alias or another name.
final class AliasUsage
{
    public function handle(): void
    {
        Cache::get('global-alias');
        AppCache::put('renamed', true, 60);
        Kv::del('renamed-redis');
    }
}
