<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Redis\RedisManager;

/*
 * Characterization tests: they pin how Laravel's own Redis cache store behaves,
 * not how this package behaves. ADR 0007 is based on these results, so if a
 * Laravel release changes any of them, this file fails and the ADR needs a
 * second look. They run against real Redis because the `array` store behaves
 * differently (it returns integers as integers).
 */

/**
 * A Laravel cache repository on its own Redis connection, built the same way
 * Laravel builds the `redis` store.
 *
 * @param  array<string, mixed>  $options  phpredis client options, e.g. a serializer
 */
function semanticsCache(array $options = [], ?int $port = null): Repository
{
    $manager = new RedisManager(app(), 'phpredis', [
        'options' => ['prefix' => ''] + $options,
        'cache' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => $port ?? (int) env('REDIS_PORT', 6379),
            'database' => 1,
            'timeout' => 1.0,
            'read_timeout' => 1.0,
        ],
    ]);

    return new Repository(new RedisStore($manager, 'cache_contract_semantics:', 'cache'));
}

function semanticsKey(): string
{
    return 'k:'.bin2hex(random_bytes(8));
}

describe('default serialization', function () {
    it('reads a cached null as a miss', function () {
        $cache = semanticsCache();
        $key = semanticsKey();

        $cache->put($key, null, 60);

        expect($cache->get($key, 'default'))->toBe('default')
            ->and($cache->has($key))->toBeFalse()
            ->and($cache->many([$key, semanticsKey()]))->each->toBeNull();
    });

    it('never caches null in remember', function () {
        $cache = semanticsCache();
        $key = semanticsKey();
        $calls = 0;
        $loader = function () use (&$calls) {
            $calls++;

            return null;
        };

        $cache->remember($key, 60, $loader);
        $cache->remember($key, 60, $loader);

        expect($calls)->toBe(2);
    });

    it('returns integers and floats as strings', function (int|float $value, string $read) {
        $cache = semanticsCache();
        $key = semanticsKey();

        $cache->put($key, $value, 60);

        expect($cache->get($key))->toBe($read);
    })->with([
        'int 0' => [0, '0'],
        'int 5' => [5, '5'],
        'float 1.5' => [1.5, '1.5'],
        'float 1.0' => [1.0, '1'],
    ]);

    it('round-trips false, true, empty strings and arrays', function (mixed $value) {
        $cache = semanticsCache();
        $key = semanticsKey();

        $cache->put($key, $value, 60);

        expect($cache->get($key, 'default'))->toBe($value);
    })->with([
        'false' => [false],
        'true' => [true],
        'empty string' => [''],
        'empty array' => [[]],
        'list' => [[1, 2]],
    ]);
});

describe('phpredis PHP serializer', function () {
    it('keeps number types but reads a cached false as a miss', function () {
        $cache = semanticsCache(['serializer' => Redis::SERIALIZER_PHP]);
        [$number, $false] = [semanticsKey(), semanticsKey()];

        $cache->put($number, 5, 60);
        $cache->put($false, false, 60);

        expect($cache->get($number))->toBe(5)
            ->and($cache->get($false, 'default'))->toBe('default')
            ->and($cache->has($false))->toBeFalse();
    });
});

describe('a one-element envelope', function () {
    it('round-trips every value under both serializers', function (array $options) {
        $cache = semanticsCache($options);
        $values = [null, false, true, 0, 5, -1, 0.0, 1.0, 1.5, '', '0', '05', [], [1, 2]];

        foreach ($values as $value) {
            $key = semanticsKey();
            $cache->put($key, [$value], 60);

            expect($cache->get($key))->toBe([$value]);
        }

        expect($cache->get(semanticsKey()))->toBeNull();
    })->with([
        'default' => [[]],
        'php serializer' => [['serializer' => Redis::SERIALIZER_PHP]],
    ]);
});

describe('unreachable Redis', function () {
    it('throws a RedisException from every operation', function (Closure $operation) {
        $cache = semanticsCache(port: 1);

        expect(fn () => $operation($cache))->toThrow(RedisException::class);
    })->with([
        'get' => [fn (Repository $cache) => $cache->get('k')],
        'many' => [fn (Repository $cache) => $cache->many(['a', 'b'])],
        'put' => [fn (Repository $cache) => $cache->put('k', 1, 60)],
        'forget' => [fn (Repository $cache) => $cache->forget('k')],
        'tags flush' => [fn (Repository $cache) => $cache->tags(['t'])->flush()],
        'lock' => [fn (Repository $cache) => $cache->lock('l', 5)->get()],
    ]);

    it('throws from remember before the loader runs', function () {
        $cache = semanticsCache(port: 1);
        $ran = false;

        expect(fn () => $cache->remember('k', 60, function () use (&$ran) {
            $ran = true;

            return 1;
        }))->toThrow(RedisException::class)
            ->and($ran)->toBeFalse();
    });
});
