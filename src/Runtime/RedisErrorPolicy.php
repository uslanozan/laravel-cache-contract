<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Runtime;

use RedisException;
use Throwable;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;

/**
 * The "redis_errors" setting: throw or report, per kind of operation
 * (ADR 0007).
 *
 * Only connection errors follow the policy. Any other exception, such as a
 * bug in a loader or an unknown store, always propagates.
 */
final readonly class RedisErrorPolicy
{
    public function __construct(
        public RedisErrorMode $read = RedisErrorMode::Report,
        public RedisErrorMode $write = RedisErrorMode::Report,
        public RedisErrorMode $invalidate = RedisErrorMode::Report,
    ) {}

    /**
     * @param  array<mixed>  $config  the "redis_errors" array; missing keys use the defaults
     *
     * @throws InvalidConfiguration
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            self::mode($config, 'read'),
            self::mode($config, 'write'),
            self::mode($config, 'invalidate'),
        );
    }

    public static function isConnectionError(Throwable $e): bool
    {
        // phpredis. Predis' connection exception joins here if Predis is
        // supported later (D13).
        return $e instanceof RedisException;
    }

    /**
     * Rethrows $e unless it is a connection error and $mode is Report; then
     * reports it, and the caller continues without the cache.
     *
     * @throws Throwable
     */
    public function handle(Throwable $e, RedisErrorMode $mode): void
    {
        if ($mode === RedisErrorMode::Throw || ! self::isConnectionError($e)) {
            throw $e;
        }

        report($e);
    }

    /**
     * @param  array<mixed>  $config
     *
     * @throws InvalidConfiguration
     */
    private static function mode(array $config, string $operation): RedisErrorMode
    {
        $value = $config[$operation] ?? RedisErrorMode::Report->value;
        $mode = is_string($value) ? RedisErrorMode::tryFrom($value) : null;

        return $mode ?? throw InvalidConfiguration::forKey(
            "redis_errors.{$operation}",
            sprintf('expected "throw" or "report", got %s', is_string($value) ? '"'.$value.'"' : get_debug_type($value)),
        );
    }
}
