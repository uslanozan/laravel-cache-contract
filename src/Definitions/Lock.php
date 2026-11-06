<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Definitions;

/**
 * The stampede lock of a definition (ADR 0012): on a miss, remember() takes
 * a lock so one request runs the loader while the others wait for its value.
 *
 *     'lock' => true,                              // the defaults below
 *     'lock' => ['seconds' => 30, 'wait' => 20],   // per definition
 *
 * Pure data: the definition validator checks the config first.
 */
final readonly class Lock
{
    /** How long a lock lives: should cover the loader's slowest run. */
    public const DEFAULT_SECONDS = 10;

    /** How long a request waits for the lock: about the loader's usual run. */
    public const DEFAULT_WAIT = 10;

    public const FIELDS = ['seconds', 'wait'];

    public function __construct(
        public int $seconds = self::DEFAULT_SECONDS,
        public int $wait = self::DEFAULT_WAIT,
    ) {}

    /**
     * From a validated "lock" value; null when the definition has no lock.
     */
    public static function fromConfig(mixed $value): ?self
    {
        if ($value === true) {
            return new self;
        }

        if (! is_array($value)) {
            return null;
        }

        $seconds = $value['seconds'] ?? self::DEFAULT_SECONDS;
        $wait = $value['wait'] ?? self::DEFAULT_WAIT;

        return new self(is_int($seconds) ? $seconds : self::DEFAULT_SECONDS, is_int($wait) ? $wait : self::DEFAULT_WAIT);
    }
}
