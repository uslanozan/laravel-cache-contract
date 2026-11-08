<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;
use Uslanozan\CacheContract\Tests\Support\RedisClient;

/*
 * The TTL Redis gives a cached null (LRVL-60): a definition's null_ttl and the
 * model cache's models.null_ttl, on the client REDIS_CLIENT names.
 */

beforeEach(function () {
    config()->set('database.redis.client', RedisClient::name());
    config()->set('database.redis.null_ttl', RedisClient::connection());
    config()->set('cache.stores.null_ttl', ['driver' => 'redis', 'connection' => 'null_ttl']);
    config()->set('cache-contract.store', 'null_ttl');
    // A fresh prefix per test: nothing to clean up, nothing shared between runs.
    config()->set('cache-contract.prefix', 'cc_test_'.bin2hex(random_bytes(6)));
    config()->set('cache-contract.definitions', [
        'license' => [
            'status' => [
                'purpose' => 'status', 'type' => 'array', 'params' => ['key' => 'string'],
                'ttl' => '1 hour', 'null_ttl' => '1 minute', 'description' => 'License status by key.',
            ],
        ],
    ]);

    ModelCacheDatabase::create(); // seeded without model events
});

/** The TTL Redis reports for a key the package wrote. */
function redisTtlOf(string $key): int
{
    /** @var RedisStore $store */
    $store = Cache::store('null_ttl')->getStore();

    return (int) $store->connection()->ttl($store->getPrefix().$key);
}

it('gives a cached null the null_ttl of its definition, and a value the ttl', function () {
    CacheContract::remember('license.status', ['key' => 'missing'], fn () => null);
    CacheContract::remember('license.status', ['key' => 'found'], fn () => ['valid' => true]);

    expect(redisTtlOf(CacheContract::key('license.status', ['key' => 'missing'])))->toBeGreaterThan(50)->toBeLessThanOrEqual(60)
        ->and(redisTtlOf(CacheContract::key('license.status', ['key' => 'found'])))->toBeGreaterThan(3590)->toBeLessThanOrEqual(3600);
});

it('gives a model cache "no such row" models.null_ttl, in find() and findMany()', function () {
    config()->set('cache-contract.models.null_ttl', 30);

    Member::cached(2);
    Member::cachedMany([1, 3]);

    expect(redisTtlOf(Member::modelCache()->key(2)))->toBeGreaterThan(20)->toBeLessThanOrEqual(30)
        ->and(redisTtlOf(Member::modelCache()->key(3)))->toBeGreaterThan(20)->toBeLessThanOrEqual(30)
        ->and(redisTtlOf(Member::modelCache()->key(1)))->toBeGreaterThan(3590)->toBeLessThanOrEqual(3600);
});
