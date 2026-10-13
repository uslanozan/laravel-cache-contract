<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Definitions;

use Stringable;

/**
 * One problem found in the definitions config, e.g.
 * 'user.active_list: unknown field "ttll" (did you mean "ttl"?)'.
 */
final readonly class ValidationError implements Stringable
{
    /**
     * @param  string|null  $definition  "section.name", the section name, or null for the whole config
     */
    public function __construct(
        public ?string $definition,
        public string $message,
    ) {}

    public function __toString(): string
    {
        return $this->definition === null ? $this->message : "{$this->definition}: {$this->message}";
    }
}
