<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Runtime;

use Predis\CommunicationException as PredisCommunicationException;
use Predis\Response\ServerException as PredisServerException;
use RedisException;
use Throwable;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;

/**
 * The "redis_errors" setting: throw or report, per kind of operation
 * (ADR 0007), and how often errors are reported.
 *
 * Only errors from Redis itself follow the policy, on both supported clients
 * (ADR 0007, update 2026-11-06):
 *
 * - phpredis: a RedisException, for a connection error and for a command
 *   Redis rejects. Where phpredis answers a rejected command with false and
 *   keeps the error aside (a script, pull()), the package throws that error
 *   as a RedisException, so it follows the policy like an outage.
 * - predis 2.3+: a CommunicationException (the connection, its timeouts and
 *   the protocol) and a ServerException (a command Redis rejects: NOPERM,
 *   OOM, WRONGTYPE).
 *
 * Any other exception, such as a bug in a loader, an unknown store or a
 * predis ClientException (a command predis does not know), always
 * propagates.
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
        // Not only an outage: a command Redis rejects too, on both clients (see
        // the class docblock). The predis classes need not exist: instanceof
        // does not load them.
        return $e instanceof RedisException
            || $e instanceof PredisCommunicationException
            || $e instanceof PredisServerException;
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
                'expected a whole number of seconds, 0 or more, got %s%s',
                is_string($value) ? '"'.$value.'"' : (is_int($value) ? (string) $value : get_debug_type($value)),
                is_string($value) && is_numeric($value) ? '; a numeric string from env() must be cast: (int) env(...)' : '',
            ),
        );
    }
}
