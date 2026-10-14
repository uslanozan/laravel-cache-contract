<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Tests\TestCase;

// Every test runs inside the Testbench application. Integration/ talks to
// real Redis (REDIS_HOST / REDIS_PORT, set by docker-compose.yml and CI).
pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Integration');
