<?php

declare(strict_types=1);

use Predis\ClientException;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
use Predis\Protocol\ProtocolException;
use Predis\Response\ServerException;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;
use Uslanozan\CacheContract\Runtime\RedisErrorPolicy;

/*
 * Which exceptions follow the Redis error policy, on both supported clients
 * (ADR 0007, update 2026-11-06): errors from Redis itself, the connection or
 * a command Redis rejects, but nothing else.
 */

it('treats errors from Redis itself as Redis errors, on phpredis and on predis', function (Closure $error) {
    expect(RedisErrorPolicy::isConnectionError($error()))->toBeTrue();
})->with([
    'phpredis: connection refused' => [fn () => new RedisException('Connection refused')],
    'phpredis: a rejected command' => [fn () => new RedisException('NOPERM this user has no permissions to run the \'get\' command')],
    'predis: connection refused' => [fn () => new ConnectionException(Mockery::mock(NodeConnectionInterface::class), 'Connection refused [tcp://redis:1]')],
    'predis: a broken reply' => [fn () => new ProtocolException(Mockery::mock(NodeConnectionInterface::class), 'Unknown response prefix')],
    'predis: a rejected command' => [fn () => new ServerException('NOPERM User cc has no permissions to run the \'get\' command')],
    'predis: WRONGTYPE' => [fn () => new ServerException('WRONGTYPE Operation against a key holding the wrong kind of value')],
]);

it('lets any other exception through', function (Closure $error) {
    expect(RedisErrorPolicy::isConnectionError($error()))->toBeFalse();
})->with([
    'predis: a command predis does not know' => [fn () => new ClientException('Command `NOSUCHCMD` is not a registered Redis command.')],
    'a loader bug' => [fn () => new RuntimeException('Undefined index')],
    'the package\'s own error' => [fn () => InvalidConfiguration::forKey('store', 'unknown')],
]);
