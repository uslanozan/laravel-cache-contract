<?php

declare(strict_types=1);

// Architecture rules checked on every test run.

arch('source files declare strict types')
    ->expect('Uslanozan\CacheContract')
    ->toUseStrictTypes();

arch('contracts contain only interfaces')
    ->expect('Uslanozan\CacheContract\Contracts')
    ->toBeInterfaces();

arch('every package exception can be caught as CacheContractException')
    ->expect('Uslanozan\CacheContract\Exceptions')
    ->classes()
    ->toImplement('Uslanozan\CacheContract\Exceptions\CacheContractException');

arch('value objects are immutable')
    ->expect([
        'Uslanozan\CacheContract\Definitions\Definition',
        'Uslanozan\CacheContract\Similarity\Finding',
        'Uslanozan\CacheContract\Definitions\ValidationError',
        'Uslanozan\CacheContract\Similarity\NameScore',
        'Uslanozan\CacheContract\Similarity\TokenizedName',
    ])
    ->toBeReadonly()
    ->toBeFinal();

// The manager is a singleton: if it could change, a tenant set in one request
// could leak into the next one on the same Octane or queue worker.
arch('the cache manager is immutable')
    ->expect('Uslanozan\CacheContract\Runtime\CacheContractManager')
    ->toBeReadonly()
    ->toBeFinal();

arch('no debugging calls are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();
