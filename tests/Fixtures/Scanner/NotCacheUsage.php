<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Scanner;

use Illuminate\Support\Collection;

// Scanner fixture: the false positives the text search hit. None is a cache call.
final class NotCacheUsage
{
    /** @var Collection<string, string> */
    private Collection $cache;

    public static function cache(string $url): string
    {
        return $url;
    }

    public function handle(object $user): void
    {
        WarmFeedCache::dispatch();
        self::cache('https://radio.example');
        $this->cache->get('song');
        $user->cache('avatar');
        Support\Cache::get('local');
    }
}
