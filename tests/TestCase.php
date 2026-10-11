<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Uslanozan\CacheContract\CacheContractServiceProvider;

/**
 * Base class for every test. Orchestra Testbench boots a minimal Laravel
 * application in memory, so the package can be tested without a real app.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            CacheContractServiceProvider::class,
        ];
    }
}
