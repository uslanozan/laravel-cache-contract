<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Scanner;

use Illuminate\Support\Facades\Cache;

// Scanner fixture: parsed by ScannerTest, never executed. Rebuilds Firefly III's CacheProperties.
final class CacheProperties
{
    private array $properties = [];

    public function addProperty(mixed $property): void
    {
        $this->properties[] = $property;
    }

    public function has(): bool
    {
        return Cache::has(md5(json_encode($this->properties)));
    }

    public function get(): mixed
    {
        return Cache::get(md5(json_encode($this->properties)));
    }

    public function store(mixed $value): void
    {
        Cache::forever(md5(json_encode($this->properties)), $value);
    }

    public static function flushAll(): void {}
}

final class Unrelated
{
    public function get(): mixed
    {
        return null;
    }
}

final class WrapperUsage
{
    public function __construct(private readonly CacheProperties $properties, private Unrelated $unrelated) {}

    public function created(int $id): mixed
    {
        $cache = new CacheProperties;
        $cache->addProperty($id);

        if ($cache->HAS()) {
            return $cache->get();
        }

        $cache->store($id);

        return (new CacheProperties)->get();
    }

    public function injected(?CacheProperties $fallback): void
    {
        $this->properties->store(1);
        $fallback?->get();
        $this->unrelated->get();
        CacheProperties::flushAll();
    }
}
