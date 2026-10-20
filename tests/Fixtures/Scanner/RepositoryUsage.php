<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Scanner;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;

// Scanner fixture: parsed by ScannerTest, never executed.
final class RepositoryUsage
{
    /** @var CacheManager */
    private $manager;

    public function __construct(private readonly Repository $cache, CacheManager $manager)
    {
        $this->manager = $manager;
    }

    public function handle(?Repository $fallback): void
    {
        $this->cache->get('artist:image');
        $this->manager->store('redis')->put('album:cover', 'x', 60);
        $forget = fn () => $fallback?->forget('artist:image');
    }
}
