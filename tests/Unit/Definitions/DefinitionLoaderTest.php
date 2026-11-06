<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Definitions\DefinitionLoader;
use Uslanozan\CacheContract\Definitions\DefinitionValidator;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Definitions\ResultType;
use Uslanozan\CacheContract\Exceptions\CacheContractException;
use Uslanozan\CacheContract\Exceptions\InvalidDefinitions;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Company;
use Uslanozan\CacheContract\Tests\Fixtures\Models\User;

beforeEach(function () {
    $this->loader = new DefinitionLoader(new DefinitionValidator);
});

it('builds definitions with enums and defaults', function () {
    $definitions = $this->loader->load([
        'user' => [
            'profile' => [
                'subject' => User::class,
                'purpose' => 'profile',
                'type' => 'array',
                'params' => ['user_id' => 'int'],
                'ttl' => 3600,
                'description' => 'Profile page data.',
                'invalidated_by' => [Company::class],
            ],
        ],
        'external' => [
            'exchange_rates' => ['purpose' => 'exchange_rates', 'type' => 'array', 'ttl' => '10 minutes', 'description' => 'Rates.'],
        ],
    ], []);

    expect($definitions)->toHaveCount(2)
        ->and($definitions[1]->ttl)->toBe(600);

    [$profile, $rates] = $definitions;

    expect($profile)
        ->id->toBe('user.profile')
        ->type->toBe(ResultType::Array)
        ->params->toBe(['user_id' => ParameterType::Int])
        ->subject->toBe(User::class)
        ->invalidatedBy->toBe([Company::class])
        ->version->toBe(1);

    expect($rates)
        ->id->toBe('external.exchange_rates')
        ->subject->toBeNull()
        ->params->toBe([])
        ->tags->toBe([])
        ->lock->toBeNull();
});

it('builds the lock with its defaults and overrides', function (mixed $lock, ?array $expected) {
    [$definition] = $this->loader->load([
        'report' => ['totals' => ['purpose' => 'totals', 'type' => 'array', 'ttl' => 600, 'description' => 'Totals.', 'lock' => $lock]],
    ], []);

    expect($definition->lock === null ? null : [$definition->lock->seconds, $definition->lock->wait])->toBe($expected);
})->with([
    'off' => [false, null],
    'on, with the defaults' => [true, [10, 10]],
    'empty array, with the defaults' => [[], [10, 10]],
    'one setting' => [['wait' => 0], [10, 0]],
    'both settings' => [['seconds' => 30, 'wait' => 20], [30, 20]],
]);

it('throws one exception listing every problem', function () {
    $load = fn () => $this->loader->load(['user' => [
        'active_list' => ['purpose' => 'active_list', 'type' => 'collection', 'ttll' => 300, 'description' => 'x'],
    ]], []);

    expect($load)->toThrow(function (InvalidDefinitions $e) {
        expect($e)->toBeInstanceOf(CacheContractException::class)
            ->and($e->errors)->toHaveCount(2)
            ->and($e->getMessage())->toBe(
                "2 problems in config/cache-contract.php:\n"
                ."  - user.active_list: unknown field \"ttll\" (did you mean \"ttl\"?)\n"
                .'  - user.active_list: "ttl" is required'
            );
    });
});
