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
            'flags' => ['purpose' => 'flags', 'type' => 'array', 'ttl' => 600, 'tags' => ['flags'], 'description' => 'Feature flags.'],
        ],
    ]);
});

describe('generation counters', function () {
    it('ends the key pattern with the counters, in name order', function () {
        $exit = Artisan::call('cache-contract:inspect', ['id' => 'user.profile']);

        expect($exit)->toBe(0)
            ->and(Artisan::output())->toContain('cc:user:profile:v2:user_id={int}:~g{def:user.profile}.{users}');
    });

    it('writes only the counters for a definition without parameters', function () {
        $exit = Artisan::call('cache-contract:inspect', ['id' => 'external.flags']);

        expect($exit)->toBe(0)
            ->and(Artisan::output())->toContain('cc:external:flags:v1:~g{flags}');
    });

    it('builds the full key from --generation', function () {
        $this->artisan('cache-contract:inspect', [
            'id' => 'user.profile',
            '--param' => ['user_id=42'],
            '--generation' => ['users=8123', 'def:user.profile=9907'],
        ])
            ->expectsOutputToContain('cc:user:profile:v2:user_id=42:~g9907.8123')
            ->doesntExpectOutputToContain('At runtime')
            ->assertSuccessful();
    });

    it('rejects generations that are not exactly the counters', function (array $generations, string $message) {
        $this->artisan('cache-contract:inspect', ['id' => 'user.profile', '--param' => ['user_id=42'], '--generation' => $generations])
            ->expectsOutputToContain($message)
            ->assertFailed();
    })->with([
        'missing counter' => [['users=8123'], 'expected [def:user.profile, users], got [users]'],
        'not an int' => [['users=abc', 'def:user.profile=1'], 'generation "users" must be a non-negative int, "abc" given'],
        'negative' => [['users=-1', 'def:user.profile=1'], 'generation "users" must be a non-negative int, "-1" given'],
        // Beyond PHP_INT_MAX: a cast would silently give 9223372036854775807.
        'too large' => [['users=99999999999999999999', 'def:user.profile=1'], 'generation "users" must be a non-negative int, "99999999999999999999" given'],
    ]);

    it('rejects --generation together with --live before anything else', function (array $arguments) {
        $exit = Artisan::call('cache-contract:inspect', $arguments + ['--generation' => ['users=1'], '--live' => true]);
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->toContain('pass either --generation or --live, not both')
            ->and($output)->not->toContain('Pass --param');
    })->with([
        'with parameters' => [['id' => 'user.profile', '--param' => ['user_id=42']]],
        // Without --param the command would otherwise stop at the "Pass --param" hint.
        'without parameters' => [['id' => 'user.profile']],
    ]);

    it('rejects the conflict before asking for a secret', function () {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('cache-contract.definitions.auth.session_owner', [
            'purpose' => 'session_owner', 'type' => 'array', 'params' => ['api_token' => 'secret'],
            'ttl' => 300, 'tags' => ['sessions'], 'description' => 'Owner of an API session.',
        ]);

        // No expectsQuestion(): the command must fail without prompting.
        $this->artisan('cache-contract:inspect', ['id' => 'auth.session_owner', '--generation' => ['sessions=1'], '--live' => true])
            ->expectsOutputToContain('pass either --generation or --live, not both')
            ->assertFailed();
    });

    it('needs no Redis with --live for a definition without counters', function () {
        config()->set('cache-contract.store', 'unreachable');

        $this->artisan('cache-contract:inspect', ['id' => 'external.exchange_rates', '--live' => true])
            ->expectsOutputToContain('cc:external:exchange_rates:v1:-')
            ->assertSuccessful();
    });
});

// Several checks on one output: read it whole. Chained expectsOutputToContain()
// calls match output lines differently on Laravel 11 and 12+.
it('shows the definition and the key pattern', function () {
    $exit = Artisan::call('cache-contract:inspect', ['id' => 'user.profile']);
    $output = Artisan::output();

    expect($exit)->toBe(0);

    // "Profile page data" without the final period: Laravel 11's
    // twoColumnDetail() strips trailing punctuation from values.
    foreach (['3600 s (1 hour)', 'Profile page data', User::class, 'user_id: int', Company::class, 'users', 'def:user.profile, users', 'cc:user:profile:v2:user_id={int}', 'Pass --param=name=value'] as $expected) {
        expect($output)->toContain($expected);
    }
});

it('builds a concrete key from terminal parameters', function () {
    $this->artisan('cache-contract:inspect', ['id' => 'user.profile', '--param' => ['user_id=42'], '--context' => ['locale=tr']])
        ->expectsOutputToContain('cc:user:profile:v2:user_id=42:@locale=tr')
        ->expectsOutputToContain('At runtime the key also ends with "~g"')
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
        ->doesntExpectOutputToContain('At runtime')
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
