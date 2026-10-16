<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Keys\ParameterEncoder;
use Uslanozan\CacheContract\Tests\Support\DefinitionBuilder;

/**
 * @param  array<string, ParameterType>  $declared
 * @param  array<string, mixed>  $params
 * @param  array<string, scalar>  $context
 */
function encodeParams(array $declared, array $params, array $context = []): string
{
    return (new ParameterEncoder)->encode(DefinitionBuilder::make('user.list', params: $declared), $params, $context);
}

it('writes scalar parameters readably, sorted by name', function () {
    expect(encodeParams(
        ['tenant_id' => ParameterType::Int, 'role' => ParameterType::String, 'active' => ParameterType::Bool, 'ratio' => ParameterType::Float],
        ['tenant_id' => 7, 'role' => 'admin', 'active' => true, 'ratio' => 1.0],
    ))->toBe('active=true:ratio=1.0:role=admin:tenant_id=7');
});

it('gives the same segment whatever the argument order', function () {
    $declared = ['tenant_id' => ParameterType::Int, 'role' => ParameterType::String];

    expect(encodeParams($declared, ['tenant_id' => 7, 'role' => 'admin']))
        ->toBe(encodeParams($declared, ['role' => 'admin', 'tenant_id' => 7]));
});

it('writes "-" when there are no parameters and no context', function () {
    expect(encodeParams([], []))->toBe('-');
});

it('appends context after the parameters, marked with @ and sorted', function () {
    expect(encodeParams(['tenant_id' => ParameterType::Int], ['tenant_id' => 7], ['region' => 'eu', 'locale' => 'tr']))
        ->toBe('tenant_id=7:@locale=tr:@region=eu')
        ->and(encodeParams([], [], ['locale' => 'tr']))->toBe('@locale=tr');
});

it('encodes separators inside strings so different inputs never share a key', function () {
    $declared = ['name' => ParameterType::String, 'role' => ParameterType::String];

    $first = encodeParams($declared, ['name' => 'ali:role=admin', 'role' => 'user']);
    $second = encodeParams($declared, ['name' => 'ali', 'role' => 'admin:role=user']);

    expect($first)->toBe('name=ali%3Arole%3Dadmin:role=user')
        ->and($second)->toBe('name=ali:role=admin%3Arole%3Duser')
        ->and($first)->not->toBe($second);
});

it('hashes arrays: map key order does not matter, list order does', function () {
    $declared = ['filters' => ParameterType::Array];

    $a = encodeParams($declared, ['filters' => ['status' => 'active', 'ids' => [1, 2, 3]]]);
    $b = encodeParams($declared, ['filters' => ['ids' => [1, 2, 3], 'status' => 'active']]);
    $c = encodeParams($declared, ['filters' => ['status' => 'active', 'ids' => [3, 2, 1]]]);

    expect($a)->toMatch('/^filters=#[0-9a-f]{16}$/')
        ->and($a)->toBe($b)
        ->and($a)->not->toBe($c);
});

it('rejects values of the wrong type instead of converting them', function (ParameterType $type, mixed $value, string $given) {
    expect(fn () => encodeParams(['value' => $type], ['value' => $value]))
        ->toThrow(InvalidParameters::class, sprintf('"value" must be %s, %s given', $type->value, $given));
})->with([
    'numeric string for int' => [ParameterType::Int, '7', 'string'],
    'int for string' => [ParameterType::String, 7, 'int'],
    'int for bool' => [ParameterType::Bool, 1, 'int'],
    'int for float' => [ParameterType::Float, 1, 'int'],
    'infinite float' => [ParameterType::Float, INF, 'float'],
    'numeric string for float' => [ParameterType::Float, '1.5', 'string'],
    'boolean string for bool' => [ParameterType::Bool, 'true', 'string'],
    'string for array' => [ParameterType::Array, 'a,b', 'string'],
    'null for int' => [ParameterType::Int, null, 'null'],
]);

// Definition parameters stay strict (ADR 0004), so the message says how to fix
// a request string without a blind (int) cast.
it('explains how to pass a request value to a number or bool parameter', function (ParameterType $type, string $rule) {
    expect(fn () => encodeParams(['value' => $type], ['value' => '7']))
        ->toThrow(InvalidParameters::class, "Request values are strings: validate them first (e.g. \$request->validate([... => '{$rule}'])), then cast");
})->with([
    'int' => [ParameterType::Int, 'integer'],
    'float' => [ParameterType::Float, 'numeric'],
    'bool' => [ParameterType::Bool, 'boolean'],
]);

it('gives no request hint when the value is not a string', function () {
    expect(fn () => encodeParams(['value' => ParameterType::Int], ['value' => 1.5]))
        ->toThrow(fn (InvalidParameters $e) => expect($e->getMessage())->not->toContain('Request values'));
});

it('rejects missing and unexpected parameters', function () {
    $declared = ['tenant_id' => ParameterType::Int];

    expect(fn () => encodeParams($declared, []))->toThrow(InvalidParameters::class, 'missing tenant_id')
        ->and(fn () => encodeParams($declared, ['tenant_id' => 7, 'page' => 2]))->toThrow(InvalidParameters::class, 'unexpected page');
});

it('rejects invalid context', function () {
    expect(fn () => encodeParams([], [], ['Locale' => 'tr']))->toThrow(InvalidParameters::class, 'context name "Locale" must be snake_case')
        ->and(fn () => encodeParams([], [], ['tags' => ['a']]))->toThrow(InvalidParameters::class, 'context "tags" must be a string, int, float or bool, array given');
});
