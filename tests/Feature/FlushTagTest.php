<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\Exceptions\InvalidDefinitions;
use Uslanozan\CacheContract\Exceptions\UnknownTag;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Tests\Fakes\FailingStore;

beforeEach(function () {
    config()->set('cache-contract.tags', [
        'users' => 'Data shown about users.',
        'billing' => 'Invoices and payments.',
    ]);
    config()->set('cache-contract.definitions', [
        'user' => [
            'profile' => [
                'purpose' => 'profile', 'type' => 'array', 'params' => ['user_id' => 'int'], 'ttl' => 300,
                'description' => 'Profile page data.', 'tags' => ['users'],
            ],
            'invoices' => [
                'purpose' => 'invoices', 'type' => 'array', 'params' => ['user_id' => 'int'], 'ttl' => 300,
                'description' => 'Invoices of a user.', 'tags' => ['users', 'billing'],
            ],
        ],
        'billing' => [
            'totals' => ['purpose' => 'totals', 'type' => 'array', 'ttl' => 300, 'description' => 'Totals.', 'tags' => ['billing']],
        ],
        'external' => [
            'exchange_rates' => ['purpose' => 'exchange_rates', 'type' => 'array', 'ttl' => 600, 'description' => 'Rates.'],
        ],
    ]);
});

it('invalidates every entry of every definition with the tag, for every parameter and context', function () {
    $tenant7 = CacheContract::withContext(['tenant_id' => 7]);
    CacheContract::put('user.profile', ['user_id' => 1], 'cached');
    CacheContract::put('user.profile', ['user_id' => 2], 'cached');
    $tenant7->put('user.profile', ['user_id' => 1], 'cached');
    CacheContract::put('user.invoices', ['user_id' => 1], 'cached');

    expect(CacheContract::flushTag('users'))->toBeTrue()
        ->and(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse()
        ->and(CacheContract::has('user.profile', ['user_id' => 2]))->toBeFalse()
        ->and($tenant7->has('user.profile', ['user_id' => 1]))->toBeFalse()
        ->and(CacheContract::has('user.invoices', ['user_id' => 1]))->toBeFalse();
});

it('leaves definitions without the tag alone', function () {
    CacheContract::put('billing.totals', [], 'cached');
    CacheContract::put('external.exchange_rates', [], 'cached');

    CacheContract::flushTag('users');

    expect(CacheContract::get('billing.totals'))->toBe('cached')
        ->and(CacheContract::get('external.exchange_rates'))->toBe('cached');
});

it('invalidates an entry when any one of its tags is flushed', function () {
    CacheContract::put('user.invoices', ['user_id' => 1], 'cached');

    CacheContract::flushTag('billing');

    expect(CacheContract::has('user.invoices', ['user_id' => 1]))->toBeFalse();
});

it('ignores the context of the instance it is called on', function () {
    CacheContract::put('user.profile', ['user_id' => 1], 'cached');

    CacheContract::withContext(['tenant_id' => 7])->flushTag('users');

    expect(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse();
});

it('caches again under the new generation', function () {
    CacheContract::flushTag('users');
    CacheContract::put('user.profile', ['user_id' => 1], 'fresh');

    expect(CacheContract::get('user.profile', ['user_id' => 1]))->toBe('fresh');
});

it('rejects a tag that is not declared, with a suggestion', function () {
    expect(fn () => CacheContract::flushTag('usres'))
        ->toThrow(UnknownTag::class, 'Unknown cache tag "usres". Did you mean "users"? Declare it under "tags" in config/cache-contract.php first.')
        ->and(fn () => CacheContract::flushTag('reports'))
        ->toThrow(UnknownTag::class, 'Unknown cache tag "reports". Declare it under "tags"');
});

it('fails loudly when the "tags" config is broken', function () {
    config()->set('cache-contract.tags', ['users', 'billing']);

    expect(fn () => CacheContract::flushTag('users'))->toThrow(InvalidDefinitions::class, '"tags" must map each tag name to a description')
        ->and(fn () => CacheContract::get('external.exchange_rates'))->toThrow(InvalidDefinitions::class);
});

it('rejects a definition with an undeclared tag on the first cache call', function () {
    config()->set('cache-contract.definitions.billing.totals.tags', ['bills']);

    expect(fn () => CacheContract::get('external.exchange_rates'))
        ->toThrow(InvalidDefinitions::class, 'billing.totals: tag "bills" is not declared under "tags"');
});

describe('when Redis is down', function () {
    beforeEach(function () {
        // Cache::extend() rebinds $this inside the closure, so capture the store.
        $store = $this->store = new FailingStore;
        Cache::extend('failing', fn () => Cache::repository($store));
        config()->set('cache.stores.failing', ['driver' => 'failing']);
        config()->set('cache-contract.store', 'failing');
        Exceptions::fake();
    });

    it('returns false and reports, with the default "report"', function () {
        $this->store->fail('put');

        expect(CacheContract::flushTag('users'))->toBeFalse();
        Exceptions::assertReported(RedisException::class);
    });

    it('throws with "invalidate" set to "throw"', function () {
        config()->set('cache-contract.redis_errors.invalidate', 'throw');
        $this->store->fail('put');

        expect(fn () => CacheContract::flushTag('users'))->toThrow(RedisException::class);
    });

    it('checks the tag before touching Redis', function () {
        expect(fn () => CacheContract::flushTag('usres'))->toThrow(UnknownTag::class)
            ->and($this->store->calls)->toBe([]);
    });
});
