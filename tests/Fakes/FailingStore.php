<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fakes;

use Illuminate\Cache\ArrayStore;
use RedisException;
use Throwable;

/**
 * An array store whose get, put or forget can be made to throw, to test the
 * Redis error policy without stopping Redis. Records every call it receives.
 *
 *     $store->fail('get');                                   // RedisException
 *     $store->fail('put', new RuntimeException('bug'));      // any other error
 */
final class FailingStore extends ArrayStore
{
    /** @var array<string, Throwable> */
    private array $failures = [];

    /** @var list<string> */
    public array $calls = [];

    public function fail(string $method, ?Throwable $error = null): self
    {
        $this->failures[$method] = $error ?? new RedisException('Connection refused');

        return $this;
    }

    public function get($key)
    {
        $this->attempt('get');

        return parent::get($key);
    }

    public function put($key, $value, $seconds)
    {
        $this->attempt('put');

        return parent::put($key, $value, $seconds);
    }

    public function forget($key)
    {
        $this->attempt('forget');

        return parent::forget($key);
    }

    private function attempt(string $method): void
    {
        $this->calls[] = $method;

        if (isset($this->failures[$method])) {
            throw $this->failures[$method];
        }
    }
}
