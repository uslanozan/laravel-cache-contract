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

// Several checks on one output: read it whole. Chained expectsOutputToContain()
// calls match output lines differently on Laravel 11 and 12+.
it('shows the definition and the key pattern', function () {
    $exit = Artisan::call('cache-contract:inspect', ['id' => 'user.profile']);
    $output = Artisan::output();

    expect($exit)->toBe(0);

    // "Profile page data" without the final period: Laravel 11's
    // twoColumnDetail() strips trailing punctuation from values.
    foreach (['3600 s (1 hour)', 'Profile page data', User::class, 'user_id: int', Company::class, 'users', 'cc:user:profile:v2:user_id={int}', 'Pass --param=name=value'] as $expected) {
        expect($output)->toContain($expected);
    }
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

describe('secret parameters', function () {
    beforeEach(function () {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('cache-contract.definitions.auth.session_owner', [
            'purpose' => 'session_owner', 'type' => 'array', 'params' => ['api_token' => 'secret', 'user_id' => 'int'],
            'ttl' => 300, 'description' => 'Owner of an API session.',
        ]);
    });

    it('asks for the value without echoing it and prints only the hash', function () {
        $this->artisan('cache-contract:inspect', ['id' => 'auth.session_owner', '--param' => ['user_id=42']])
            ->expectsQuestion('api_token (secret, not shown)', 'plain-token-value')
            ->expectsOutputToContain('api_token={secret}:user_id={int}')
            ->expectsOutputToContain('api_token=#')
            ->doesntExpectOutputToContain('plain-token-value')
            ->assertSuccessful();
    });

    it('refuses a secret value on the command line, where shell history and logs keep it', function () {
        $exit = Artisan::call('cache-contract:inspect', ['id' => 'auth.session_owner', '--param' => ['api_token=plain-token-value', 'user_id=42']]);
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->toContain('Secret parameter "api_token" is not accepted with --param')
            ->and($output)->not->toContain('plain-token-value');
    });

    it('shows only the pattern without a terminal to ask on', function () {
        $exit = Artisan::call('cache-contract:inspect', ['id' => 'auth.session_owner', '--param' => ['user_id=42'], '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('cc:auth:session_owner:v1:api_token={secret}:user_id={int}')
            ->and($output)->toContain('Secret parameters (api_token) are asked for interactively')
            ->and($output)->not->toMatch('/api_token=#[0-9a-f]{32}/');
    });

    it('asks for the other parameters first', function () {
        $exit = Artisan::call('cache-contract:inspect', ['id' => 'auth.session_owner']);

        expect($exit)->toBe(0)
            ->and(Artisan::output())->toContain('Pass --param=name=value');
    });
});

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
