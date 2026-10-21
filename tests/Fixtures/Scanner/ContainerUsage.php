<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Scanner;

use Illuminate\Contracts\Foundation\Application;

// Scanner fixture: parsed by ScannerTest, never executed.
final class ContainerUsage
{
    /** @var Application */
    private $app;

    public function handle(): void
    {
        app('cache')->get('permissions');
        resolve('cache.store')->forget('permissions');
        $this->app['cache']->put('report:token', 'x', 60);
        $this->app['config']->get('cache.default');
    }
}
