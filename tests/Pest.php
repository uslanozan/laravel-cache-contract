<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Tests\TestCase;

// Every test in Feature/ and Unit/ runs inside the Testbench application.
pest()->extend(TestCase::class)->in('Feature', 'Unit');
