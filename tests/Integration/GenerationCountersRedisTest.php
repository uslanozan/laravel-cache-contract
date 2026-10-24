<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Runtime\GenerationCounters;
use Uslanozan\CacheContract\Runtime\RedisErrorMode;
use Uslanozan\CacheContract\Runtime\RedisErrorPolicy;

/*
 * Generation counters (ADR 0009) against real Redis: values are random ints
 * that survive Redis (envelope), stay stable until bumped, and follow the
 * Redis error policy when Redis is down.
 */

beforeEach(function () {
    config()->set('database.redis.client', 'phpredis');

    foreach (['counters' => (int) env('REDIS_PORT', 6379), 'unreachable' => 1] as $connection => $port) {
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

    $this->prefix = 'cc_test_'.bin2hex(random_bytes(6));
});

function countersFor(string $store, string $prefix): GenerationCounters
{
    return new GenerationCounters(app('cache'), new RedisErrorPolicy, $prefix, $store);
}

it('creates a counter once, as an int, and keeps it until it is bumped', function () {
    $counters = countersFor('counters', $this->prefix);

    $first = $counters->currentOrFail(['users', 'def:company.summary']);
    $again = $counters->currentOrFail(['users', 'def:company.summary']);

    expect($first['users'])->toBeInt()->toBeGreaterThan(0)
        ->and($again)->toBe($first)
        // Stored in the envelope, so Redis gives back an int, not "8123".
        ->and(Cache::store('counters')->get($this->prefix.':gen:users'))->toBe([$first['users']]);
});

it('gives a counter a new value when it is bumped', function () {
    $counters = countersFor('counters', $this->prefix);
    $before = $counters->currentOrFail(['users'])['users'];

    expect($counters->bump(['users']))->toBeTrue()
        ->and($counters->currentOrFail(['users'])['users'])->not->toBe($before);
});

it('uses a counter another process created first', function () {
    Cache::store('counters')->put($this->prefix.':gen:users', [4242], 60);

    expect(countersFor('counters', $this->prefix)->currentOrFail(['users']))->toBe(['users' => 4242]);
});

it('replaces an entry that is not a counter', function () {
    Cache::store('counters')->put($this->prefix.':gen:users', 'garbage', 60);
    $counters = countersFor('counters', $this->prefix);

    $value = $counters->currentOrFail(['users'])['users'];

    expect($value)->toBeInt()
        ->and($counters->currentOrFail(['users'])['users'])->toBe($value);
});

it('follows the Redis error policy when Redis is unreachable', function () {
    Exceptions::fake();
    $counters = countersFor('unreachable', $this->prefix);

    expect($counters->current(['users'], RedisErrorMode::Report))->toBeFalse()
        ->and($counters->bump(['users']))->toBeFalse()
        ->and(fn () => $counters->current(['users'], RedisErrorMode::Throw))->toThrow(RedisException::class)
        ->and(fn () => $counters->currentOrFail(['users']))->toThrow(RedisException::class);

    Exceptions::assertReported(RedisException::class);
});
