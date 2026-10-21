<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Contracts\KeyFactory;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Exceptions\InvalidKeyFormat;
use Uslanozan\CacheContract\Keys\DefaultKeyFactory;
use Uslanozan\CacheContract\Tests\Support\DefinitionBuilder;

it('is bound in the container as a shared instance', function () {
    expect(app(KeyFactory::class))
        ->toBeInstanceOf(DefaultKeyFactory::class)
        ->toBe(app(KeyFactory::class));
});

it('builds keys with the default format', function () {
    $keys = app(KeyFactory::class);

    expect($keys->make(DefinitionBuilder::make('user.active_list', params: ['tenant_id' => ParameterType::Int]), ['tenant_id' => 7]))
        ->toBe('cc:user:active_list:v1:tenant_id=7')
        ->and($keys->make(DefinitionBuilder::make('external.exchange_rates'), []))
        ->toBe('cc:external:exchange_rates:v1:-')
        ->and($keys->make(DefinitionBuilder::make('external.exchange_rates', params: ['region' => ParameterType::String]), ['region' => 'eu'], ['tenant_id' => 7]))
        ->toBe('cc:external:exchange_rates:v1:region=eu:@tenant_id=7');
});

it('appends the generation segment last (ADR 0009)', function () {
    $keys = app(KeyFactory::class);
    $profile = DefinitionBuilder::make('user.profile', params: ['user_id' => ParameterType::Int], version: 2, tags: ['users']);

    expect($keys->make($profile, ['user_id' => 42], [], ['users' => 8123]))
        ->toBe('cc:user:profile:v2:user_id=42:~g8123')
        ->and($keys->make($profile, ['user_id' => 42], ['tenant_id' => 7], ['users' => 8123]))
        ->toBe('cc:user:profile:v2:user_id=42:@tenant_id=7:~g8123')
        ->and($keys->make($profile, ['user_id' => 42]))
        ->toBe('cc:user:profile:v2:user_id=42');
});

it('keeps the generation segment with a custom key format', function () {
    // Not a key_format placeholder: no format can drop it (ADR 0004, update 2026-10-15).
    config()->set('cache-contract.key_format', '{section}:{name}:{params}');

    expect(app(KeyFactory::class)->make(DefinitionBuilder::make('user.profile', tags: ['users']), [], [], ['users' => 8123]))
        ->toBe('user:profile:~g8123');
});

it('separates tenants, versions and sections', function () {
    $keys = app(KeyFactory::class);
    $list = DefinitionBuilder::make('user.summary', params: ['tenant_id' => ParameterType::Int]);

    expect($keys->make($list, ['tenant_id' => 7]))
        ->not->toBe($keys->make($list, ['tenant_id' => 8]))
        ->not->toBe($keys->make(DefinitionBuilder::make('user.summary', params: ['tenant_id' => ParameterType::Int], version: 2), ['tenant_id' => 7]))
        ->not->toBe($keys->make(DefinitionBuilder::make('company.summary', params: ['tenant_id' => ParameterType::Int]), ['tenant_id' => 7]));
});

it('follows the configured format and prefix', function () {
    config()->set('cache-contract.key_format', 'app:{prefix}:{section}:{name}:{params}');
    config()->set('cache-contract.prefix', 'shop');

    expect(app(KeyFactory::class)->make(DefinitionBuilder::make('user.profile'), []))->toBe('app:shop:user:profile:-');
});

it('fails loudly on an invalid configured format', function () {
    config()->set('cache-contract.key_format', '{prefix}:{name}:{params}');

    expect(fn () => app(KeyFactory::class))->toThrow(InvalidKeyFormat::class, '{section} must appear exactly once');
});
