<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Definitions\TagRegistry;
use Uslanozan\CacheContract\Exceptions\InvalidDefinitions;

it('knows the declared tags and their descriptions', function () {
    config()->set('cache-contract.tags', ['users' => 'Data shown about users.', 'billing' => 'Invoices and payments.']);
    $tags = app(TagRegistry::class);

    expect($tags->has('users'))->toBeTrue()
        ->and($tags->has('usres'))->toBeFalse()
        ->and($tags->all())->toBe(['users' => 'Data shown about users.', 'billing' => 'Invoices and payments.'])
        ->and($tags->closest('usres'))->toBe('users')
        ->and($tags->closest('reports'))->toBeNull();
});

it('is shared and reads the config once', function () {
    config()->set('cache-contract.tags', ['users' => 'Data shown about users.']);
    app(TagRegistry::class)->has('users');

    config()->set('cache-contract.tags', []);

    expect(app(TagRegistry::class))->toBe(app(TagRegistry::class))
        ->and(app(TagRegistry::class)->has('users'))->toBeTrue();
});

it('fails on first use, not when it is built, if the "tags" config is broken', function () {
    config()->set('cache-contract.tags', ['model:users' => 'x']);
    $tags = app(TagRegistry::class);

    expect(fn () => $tags->has('users'))->toThrow(InvalidDefinitions::class, 'tags.model:users: tags starting with "model:" are reserved');
});
