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
 * @param  array<string, int>  $generations
 */
function encodeParams(array $declared, array $params, array $context = [], array $generations = []): string
{
    return (new ParameterEncoder)->encode(DefinitionBuilder::make('user.list', params: $declared), $params, $context, $generations);
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

describe('generations (ADR 0009)', function () {
    it('appends one counter as its value, after the context', function () {
        expect(encodeParams(['tenant_id' => ParameterType::Int], ['tenant_id' => 7], ['locale' => 'tr'], ['model:users' => 8123]))
            ->toBe('tenant_id=7:@locale=tr:~g8123');
    });

    it('needs no name for a single counter', function () {
        expect(encodeParams([], [], [], ['model:users' => 8123]))
            ->toBe(encodeParams([], [], [], ['def:user.list' => 8123]));
    });

    it('hashes several counters, whatever order they come in', function () {
        $a = encodeParams([], [], [], ['model:users' => 8123, 'def:company.summary' => 9907]);
        $b = encodeParams([], [], [], ['def:company.summary' => 9907, 'model:users' => 8123]);

        expect($a)->toMatch('/^~g#[0-9a-f]{16}$/')
            ->and($a)->toBe($b);
    });

    it('gives a new key when any counter changes', function () {
        $before = ['model:users' => 8123, 'def:company.summary' => 9907];

        expect(encodeParams([], [], [], ['model:users' => 5501]))->not->toBe(encodeParams([], [], [], ['model:users' => 8123]))
            ->and(encodeParams([], [], [], [...$before, 'model:users' => 5501]))->not->toBe(encodeParams([], [], [], $before))
            ->and(encodeParams([], [], [], [...$before, 'def:company.summary' => 1]))->not->toBe(encodeParams([], [], [], $before));
    });

    it('leaves the segment unchanged without generations', function () {
        expect(encodeParams(['tenant_id' => ParameterType::Int], ['tenant_id' => 7], [], []))->toBe('tenant_id=7')
            ->and(encodeParams([], [], [], []))->toBe('-')
            ->and(encodeParams([], [], [], ['model:users' => 8123]))->toBe('~g8123');
    });

    it('cannot be imitated by a parameter or context value', function () {
        // ":" in values is URL-encoded, so no value can open a "~g" segment of its own.
        $declared = ['name' => ParameterType::String];

        expect(encodeParams($declared, ['name' => 'x:~g5']))->toBe('name=x%3A~g5')
            ->and(encodeParams($declared, ['name' => 'x:~g5']))->not->toBe(encodeParams($declared, ['name' => 'x'], [], ['model:users' => 5]))
            ->and(encodeParams([], [], ['tenant' => 'a:~g5']))->toBe('@tenant=a%3A~g5');
    });

    it('rejects a generation that is not an int under a non-empty name', function (array $generations, string $message) {
        expect(fn () => encodeParams([], [], [], $generations))->toThrow(InvalidParameters::class, $message);
    })->with([
        'numeric string value' => [['model:users' => '8123'], "'model:users' => string given"],
        'float value' => [['model:users' => 1.5], "'model:users' => float given"],
        'empty name' => [['' => 8123], "'' => int given"],
        'list instead of names' => [[8123], '0 => int given'],
    ]);
});
