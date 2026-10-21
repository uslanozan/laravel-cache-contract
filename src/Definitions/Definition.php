<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Definitions;

use Illuminate\Database\Eloquent\Model;

/**
 * One cache entry type from config/cache-contract.php (ADR 0002).
 *
 * Pure data and immutable: the definition loader validates the config before
 * building these, so this class does not validate anything itself.
 */
final readonly class Definition
{
    /** "section.name", e.g. "user.active_list". */
    public string $id;

    /**
     * @param  class-string<Model>|null  $subject  model the data is about, if any
     * @param  array<string, ParameterType>  $params  every declared parameter is required at call time
     * @param  list<string>  $tags
     * @param  list<class-string<Model>>  $invalidatedBy  models whose changes clear this definition (Phase 3)
     */
    public function __construct(
        public string $section,
        public string $name,
        public string $purpose,
        public ResultType $type,
        public int $ttl,
        public string $description,
        public ?string $subject = null,
        public array $params = [],
        public int $version = 1,
        public array $tags = [],
        public array $invalidatedBy = [],
    ) {
        $this->id = $section.'.'.$name;
    }

    /**
     * Names of the generation counters this definition's entries depend on
     * (ADR 0009): one per tag, plus "def:{id}" when it has invalidated_by.
     *
     * @return list<string> sorted
     */
    public function generationCounters(): array
    {
        $names = $this->tags;

        if ($this->invalidatedBy !== []) {
            $names[] = 'def:'.$this->id;
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }
}
