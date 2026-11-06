<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Uslanozan\CacheContract\Runtime\RedisReads;

/*
 * RedisReads::many() on phpredis, with the two ways Laravel handles the false
 * phpredis returns for a rejected MGET (LRVL-79). The real behaviour is
 * checked against Redis in RejectedMgetRedisTest; Laravel 11.44.2 and 12.0.1
 * (the TypeError) only run there in the lowest-dependency CI jobs, so both
 * paths are pinned here too.
 */

/**
 * A repository on a RedisStore whose phpredis connection answers MGET with
 * $mget and whose last error is $lastError.
 */
function repositoryWithMget(Closure $mget, ?string $lastError): Repository
{
    $client = Mockery::mock(Redis::class);
    $client->shouldReceive('clearLastError')->andReturnTrue();
    $client->shouldReceive('getLastError')->andReturn($lastError);

    $connection = Mockery::mock(PhpRedisConnection::class);
    $connection->shouldReceive('client')->andReturn($client);
    $connection->shouldReceive('mget')->andReturnUsing($mget);
    $connection->shouldReceive('serialized')->andReturnFalse();
    $connection->shouldReceive('compressed')->andReturnFalse();

    $redis = Mockery::mock(RedisFactory::class);
    $redis->shouldReceive('connection')->andReturn($connection);

    return new Repository(new RedisStore($redis, '', 'default'));
}

it('throws a rejected MGET that Laravel turns into a TypeError (11.44.2, 12.0.1)', function () {
    $repository = repositoryWithMget(
        fn () => array_map(fn (mixed $value): mixed => $value, false), // what PhpRedisConnection::mget() does there
        "NOPERM User cc has no permissions to run the 'mget' command",
    );

    try {
        RedisReads::many($repository, ['a', 'b']);
        test()->fail('Expected a RedisException.');
    } catch (RedisException $e) {
        expect($e->getMessage())->toContain('NOPERM')
            ->and($e->getPrevious())->toBeInstanceOf(TypeError::class);
    }
});

it('throws a rejected MGET that Laravel answers with nulls (later releases)', function () {
    $repository = repositoryWithMget(fn () => [null, null], "NOPERM User cc has no permissions to run the 'mget' command");

    expect(fn () => RedisReads::many($repository, ['a', 'b']))->toThrow(RedisException::class, 'NOPERM');
});

it('lets a TypeError through when phpredis has no error aside', function () {
    $repository = repositoryWithMget(fn () => throw new TypeError('a bug elsewhere'), null);

    expect(fn () => RedisReads::many($repository, ['a']))->toThrow(TypeError::class, 'a bug elsewhere');
});

it('returns what MGET found when Redis accepts it', function () {
    $repository = repositoryWithMget(fn () => [serialize(['x']), null], null);

    expect(RedisReads::many($repository, ['a', 'b']))->toBe(['a' => ['x'], 'b' => null]);
});

it('leaves other stores to Repository::many()', function () {
    $repository = new Repository(new ArrayStore);
    $repository->put('a', 1, 60);

    expect(RedisReads::many($repository, ['a', 'b']))->toBe(['a' => 1, 'b' => null]);
});
