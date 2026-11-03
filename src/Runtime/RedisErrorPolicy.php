<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Runtime;

use RedisException;
use Throwable;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;

/**
 * The "redis_errors" setting: throw or report, per kind of operation
 * (ADR 0007), and how often errors are reported.
 *
 * Only connection errors follow the policy. Any other exception, such as a
 * bug in a loader or an unknown store, always propagates.
 */
final readonly class RedisErrorPolicy
{
    public const DEFAULT_REPORT_INTERVAL = 60;

    /**
     * @param  int  $reportInterval  seconds between two reported errors; 0 reports every one
     * @param  RedisErrorReporter  $reporter  holds the state of the process: share one instance
     */
    public function __construct(
        public RedisErrorMode $read = RedisErrorMode::Report,
        public RedisErrorMode $write = RedisErrorMode::Report,
        public RedisErrorMode $invalidate = RedisErrorMode::Report,
        public int $reportInterval = self::DEFAULT_REPORT_INTERVAL,
        private RedisErrorReporter $reporter = new RedisErrorReporter,
    ) {}

    /**
     * @param  array<mixed>  $config  the "redis_errors" array; missing keys use the defaults
     *
     * @throws InvalidConfiguration
     */
    public static function fromConfig(array $config, RedisErrorReporter $reporter = new RedisErrorReporter): self
    {
        return new self(
            self::modeFrom($config, RedisOperation::Read),
            self::modeFrom($config, RedisOperation::Write),
            self::modeFrom($config, RedisOperation::Invalidate),
            self::interval($config),
            $reporter,
        );
    }

    public function mode(RedisOperation $operation): RedisErrorMode
    {
        return match ($operation) {
            RedisOperation::Read => $this->read,
            RedisOperation::Write => $this->write,
            RedisOperation::Invalidate => $this->invalidate,
        };
    }

    public static function isConnectionError(Throwable $e): bool
    {
        // phpredis. Predis' connection exception joins here if Predis is
        // supported later (D13).
        return $e instanceof RedisException;
    }

    /**
     * Rethrows $e unless it is a connection error and the mode of $operation
     * is Report; then reports it, at most once per report interval, and the
     * caller continues without the cache.
     *
     * @throws Throwable
     */
    public function handle(Throwable $e, RedisOperation $operation): void
    {
        if ($this->mode($operation) === RedisErrorMode::Throw || ! self::isConnectionError($e)) {
            throw $e;
        }

        $this->reporter->report($e, $operation, $this->reportInterval);
    }

    /**
     * @param  array<mixed>  $config
     *
     * @throws InvalidConfiguration
     */
    private static function modeFrom(array $config, RedisOperation $operation): RedisErrorMode
    {
        $value = $config[$operation->value] ?? RedisErrorMode::Report->value;
        $mode = is_string($value) ? RedisErrorMode::tryFrom($value) : null;

        return $mode ?? throw InvalidConfiguration::forKey(
            "redis_errors.{$operation->value}",
            sprintf('expected "throw" or "report", got %s', is_string($value) ? '"'.$value.'"' : get_debug_type($value)),
        );
    }

    /**
     * @param  array<mixed>  $config
     *
     * @throws InvalidConfiguration
     */
    private static function interval(array $config): int
    {
        $value = $config['report_interval'] ?? self::DEFAULT_REPORT_INTERVAL;

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        throw InvalidConfiguration::forKey(
            'redis_errors.report_interval',
            sprintf(
                'expected a whole number of seconds, 0 or more, got %s',
                is_string($value) ? '"'.$value.'"' : (is_int($value) ? (string) $value : get_debug_type($value)),
            ),
        );
    }
}
