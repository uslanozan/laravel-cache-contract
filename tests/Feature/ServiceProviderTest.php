<?php

declare(strict_types=1);

use Uslanozan\CacheContract\CacheContractServiceProvider;

it('registers the service provider', function () {
    expect(app()->getProviders(CacheContractServiceProvider::class))->not->toBeEmpty();
});

it('loads the default configuration', function () {
    expect(config('cache-contract.prefix'))->toBe('cc')
        ->and(config('cache-contract.key_format'))->toBe('{prefix}:{section}:{name}:v{version}:{params}')
        ->and(config('cache-contract.definitions'))->toBe([])
        ->and(config('cache-contract.store'))->toBeNull()
        ->and(config('cache-contract.redis_errors'))->toBe(['read' => 'report', 'write' => 'report', 'invalidate' => 'report']);
});
