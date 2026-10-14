<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Company;
use Uslanozan\CacheContract\Tests\Fixtures\Models\User;

beforeEach(function () {
    config()->set('cache-contract.definitions', [
        'user' => [
            'profile' => [
                'subject' => User::class, 'purpose' => 'profile', 'type' => 'array',
                'params' => ['user_id' => 'int'], 'ttl' => 3600, 'version' => 2,
                'tags' => ['users'], 'invalidated_by' => [Company::class],
                'description' => 'Profile page data.',
            ],
            'active_list' => [
                'subject' => User::class, 'purpose' => 'active_list', 'type' => 'collection',
                'params' => ['tenant_id' => 'int', 'role' => 'string', 'filters' => 'array', 'only_paid' => 'bool'],
                'ttl' => 300, 'description' => 'Active users of a tenant.',
            ],
        ],
        'external' => [
            'exchange_rates' => ['purpose' => 'exchange_rates', 'type' => 'array', 'ttl' => 600, 'description' => 'Rates.'],
        ],
    ]);
});

it('shows the definition and the key pattern', function () {
    $this->artisan('cache-contract:inspect', ['id' => 'user.profile'])
        ->expectsOutputToContain('Profile page data.')
        ->expectsOutputToContain(User::class)
        ->expectsOutputToContain('user_id: int')
        ->expectsOutputToContain(Company::class)
        ->expectsOutputToContain('cc:user:profile:v2:user_id={int}')
        ->expectsOutputToContain('Pass --param=name=value')
        ->assertSuccessful();
});

it('builds a concrete key from terminal parameters', function () {
    $this->artisan('cache-contract:inspect', ['id' => 'user.profile', '--param' => ['user_id=42'], '--context' => ['locale=tr']])
        ->expectsOutputToContain('cc:user:profile:v2:user_id=42:@locale=tr')
        ->assertSuccessful();
});

it('converts every parameter type from text', function () {
    $exit = Artisan::call('cache-contract:inspect', ['id' => 'user.active_list', '--param' => [
        'tenant_id=7', 'role=admin', 'filters={"status":"active"}', 'only_paid=true',
    ]]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toMatch('/cc:user:active_list:v1:filters=#[0-9a-f]{16}:only_paid=true:role=admin:tenant_id=7/');
});

it('shows the key of a definition without parameters right away', function () {
    $this->artisan('cache-contract:inspect', ['id' => 'external.exchange_rates'])
        ->expectsOutputToContain('cc:external:exchange_rates:v1:-')
        ->doesntExpectOutputToContain('Pass --param')
        ->assertSuccessful();
});

it('rejects a value that does not fit the declared type', function (string $param, string $message) {
    $this->artisan('cache-contract:inspect', ['id' => 'user.profile', '--param' => [$param]])
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'not a number' => ['user_id=abc', '"user_id" must be int, "abc" given'],
    'missing equals sign' => ['user_id', '--param "user_id" must be name=value'],
    'unknown parameter' => ['page=2', 'missing user_id'],
]);

it('suggests similar ids for an unknown id', function () {
    $this->artisan('cache-contract:inspect', ['id' => 'user.profle'])
        ->expectsOutputToContain('Unknown cache definition "user.profle".')
        ->expectsOutputToContain('Did you mean: user.profile?')
        ->assertFailed();
});

it('points to the list when nothing is similar', function () {
    $this->artisan('cache-contract:inspect', ['id' => 'order.weekly_totals'])
        ->expectsOutputToContain('Run php artisan cache-contract:list')
        ->assertFailed();
});
