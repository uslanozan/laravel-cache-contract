<?php

declare(strict_types=1);

// Architecture rules checked on every test run.

arch('source files declare strict types')
    ->expect('Uslanozan\CacheContract')
    ->toUseStrictTypes();

arch('no debugging calls are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();
