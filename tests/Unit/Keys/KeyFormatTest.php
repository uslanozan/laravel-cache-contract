<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Exceptions\CacheContractException;
use Uslanozan\CacheContract\Exceptions\InvalidKeyFormat;
use Uslanozan\CacheContract\Keys\KeyFormat;
use Uslanozan\CacheContract\Tests\Support\DefinitionBuilder;

it('renders the default format', function () {
    $format = KeyFormat::fromString('{prefix}:{section}:{name}:v{version}:{params}', 'cc');

    expect($format->render(DefinitionBuilder::make('user.active_list', version: 3), 'tenant_id=7'))
        ->toBe('cc:user:active_list:v3:tenant_id=7');
});

it('allows leaving out the prefix and the version', function () {
    $format = KeyFormat::fromString('{section}.{name}|{params}');

    expect($format->render(DefinitionBuilder::make('company.summary'), '-'))->toBe('company.summary|-');
});

it('rejects a format that cannot produce unique keys', function (string $pattern, string $reason) {
    expect(fn () => KeyFormat::fromString($pattern, 'cc'))
        ->toThrow(InvalidKeyFormat::class, $reason);
})->with([
    'no section' => ['{prefix}:{name}:{params}', '{section} must appear exactly once'],
    'no name' => ['{prefix}:{section}:{params}', '{name} must appear exactly once'],
    'no params' => ['{prefix}:{section}:{name}', '{params} must appear exactly once'],
    'params twice' => ['{section}:{name}:{params}:{params}', '{params} must appear exactly once'],
    'unknown placeholder' => ['{section}:{name}:{tenant}:{params}', 'unknown placeholder {tenant}'],
]);

it('rejects {prefix} with an empty prefix setting', function () {
    expect(fn () => KeyFormat::fromString('{prefix}:{section}:{name}:{params}', ''))
        ->toThrow(InvalidKeyFormat::class, 'the "prefix" setting is empty');
});

it('is a package exception', function () {
    expect(InvalidKeyFormat::because('x', 'y'))->toBeInstanceOf(CacheContractException::class);
});
