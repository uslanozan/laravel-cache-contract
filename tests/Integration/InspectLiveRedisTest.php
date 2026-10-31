<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Uslanozan\CacheContract\Runtime\GenerationCounters;

/*
 * cache-contract:inspect --live against real Redis: it reads the generation
 * counters the application uses, and never writes one.
 */

beforeEach(function () {
    config()->set('database.redis.client', 'phpredis');

    foreach (['inspect_live' => (int) env('REDIS_PORT', 6379), 'unreachable' => 1] as $connection => $port) {
        config()->set("database.redis.{$connection}", [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => $port,
            'database' => 1,
            'timeout' => 1.0,
            'read_timeout' => 1.0,
            'max_retries' => 0,
        ]);
        config()->set("cache.stores.{$connection}", ['driver' => 'redis', 'connection' => $connection]);
    }

    config()->set('cache-contract.store', 'inspect_live');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));
    config()->set('cache-contract.definitions', [
        'user' => [
            'profile' => [
                'purpose' => 'profile', 'type' => 'array', 'params' => ['user_id' => 'int'],
                'ttl' => 300, 'tags' => ['users', 'admin'], 'description' => 'Profile page data.',
            ],
        ],
    ]);
});

it('shows the key with the counters read from Redis', function () {
    $values = app(GenerationCounters::class)->currentOrFail(['admin', 'users']);
    $prefix = config('cache-contract.prefix');

    $exit = Artisan::call('cache-contract:inspect', ['id' => 'user.profile', '--param' => ['user_id=42'], '--live' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain("{$prefix}:user:profile:v1:user_id=42:~g{$values['admin']}.{$values['users']}");
});

it('reports a counter that does not exist yet, and does not create it', function () {
    app(GenerationCounters::class)->currentOrFail(['admin']);

    $exit = Artisan::call('cache-contract:inspect', ['id' => 'user.profile', '--param' => ['user_id=42'], '--live' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Not in Redis yet: users')
        // The pattern still shows ~g{admin}.{users}; no key with real values is printed.
        ->and($output)->not->toMatch('/~g\d/')
        ->and(app(GenerationCounters::class)->peek(['users']))->toBe(['users' => null]);
});

it('fails with one line when Redis cannot be reached', function () {
    config()->set('cache-contract.store', 'unreachable');

    $exit = Artisan::call('cache-contract:inspect', ['id' => 'user.profile', '--param' => ['user_id=42'], '--live' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Could not read the generation counters from Redis');
});
