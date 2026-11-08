<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Runtime;

/**
 * What a read found at a key, as the store decoded it: nothing, an envelope
 * (a hit), a tombstone, or a foreign value written by other code or by an
 * older format. A loader's write is compared against it (ADR 0012).
 *
 * @internal
 */
final readonly class StoredEntry
{
    public function __construct(public mixed $stored) {}

    public function isHit(): bool
    {
        return Envelope::isHit($this->stored);
    }

    /** The cached value; null when this is not a hit. */
    public function value(): mixed
    {
        return Envelope::isHit($this->stored) ? Envelope::unwrap($this->stored) : null;
    }

    public function isEmpty(): bool
    {
        return $this->stored === null;
    }

    public function isTombstone(): bool
    {
        return Tombstone::is($this->stored);
    }

    /** Neither nothing, an envelope nor a tombstone. */
    public function isForeign(): bool
    {
        return ! $this->isEmpty() && ! $this->isHit() && ! $this->isTombstone();
    }
}
