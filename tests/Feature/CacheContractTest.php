<?php

declare(strict_types=1);

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Exceptions\UnknownDefinition;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Runtime\CacheContractManager;
use Uslanozan\CacheContract\Runtime\GenerationCounters;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Company;

beforeEach(function () {
    config()->set('cache-contract.definitions', [
        'user' => [
            'profile' => [
                'purpose' => 'profile', 'type' => 'array', 'params' => ['user_id' => 'int'],
                'ttl' => 300, 'description' => 'Profile page data.',
            ],
            'active_list' => [
                'purpose' => 'active_list', 'type' => 'collection', 'params' => ['role' => 'string'],
                'ttl' => 60, 'description' => 'Active users of a tenant.',
            ],
        ],
        'external' => [
            'exchange_rates' => ['purpose' => 'exchange_rates', 'type' => 'array', 'ttl' => 600, 'description' => 'Rates.'],
        ],
    ]);
});

it('is a shared instance behind the facade', function () {
    expect(CacheContract::getFacadeRoot())
        ->toBeInstanceOf(CacheContractManager::class)
        ->toBe(app(CacheContractManager::class));
});

it('runs the loader once and stores the value under the generated key', function () {
    $calls = 0;
    $loader = function () use (&$calls) {
        $calls++;

        return ['name' => 'Ozan'];
    };

    expect(CacheContract::remember('user.profile', ['user_id' => 42], $loader))->toBe(['name' => 'Ozan'])
        ->and(CacheContract::remember('user.profile', ['user_id' => 42], $loader))->toBe(['name' => 'Ozan'])
        ->and($calls)->toBe(1)
        ->and(Cache::get('cc:user:profile:v1:user_id=42'))->toBe([['name' => 'Ozan']]);
});

it('puts, reads and forgets a value', function () {
    expect(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse()
        ->and(CacheContract::put('user.profile', ['user_id' => 1], ['name' => 'Ayselin']))->toBeTrue()
        ->and(CacheContract::has('user.profile', ['user_id' => 1]))->toBeTrue()
        ->and(CacheContract::get('user.profile', ['user_id' => 1]))->toBe(['name' => 'Ayselin'])
        ->and(CacheContract::get('user.profile', ['user_id' => 2]))->toBeNull()
        ->and(CacheContract::forget('user.profile', ['user_id' => 1]))->toBeTrue()
        ->and(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse();
});

describe('add', function () {
    it('writes only when nothing is cached yet', function () {
        expect(CacheContract::add('user.profile', ['user_id' => 1], ['name' => 'first']))->toBeTrue()
            ->and(CacheContract::add('user.profile', ['user_id' => 1], ['name' => 'second']))->toBeFalse()
            ->and(CacheContract::get('user.profile', ['user_id' => 1]))->toBe(['name' => 'first']);
    });

    it('counts a cached null as cached', function () {
        CacheContract::put('user.profile', ['user_id' => 1], null);

        expect(CacheContract::add('user.profile', ['user_id' => 1], ['name' => 'late']))->toBeFalse()
            ->and(CacheContract::has('user.profile', ['user_id' => 1]))->toBeTrue()
            ->and(CacheContract::get('user.profile', ['user_id' => 1], 'default'))->toBeNull();
    });

    it('writes for the TTL of the definition', function () {
        CacheContract::add('user.active_list', ['role' => 'admin'], ['ada']);

        $this->travel(61)->seconds();

        expect(CacheContract::has('user.active_list', ['role' => 'admin']))->toBeFalse()
            ->and(CacheContract::add('user.active_list', ['role' => 'admin'], ['grace']))->toBeTrue();
    });
});

describe('pull', function () {
    it('returns the cached value and removes it', function () {
        CacheContract::put('user.profile', ['user_id' => 1], ['token' => 'abc']);

        expect(CacheContract::pull('user.profile', ['user_id' => 1]))->toBe(['token' => 'abc'])
            ->and(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse()
            ->and(CacheContract::pull('user.profile', ['user_id' => 1], 'used'))->toBe('used');
    });

    it('returns the default on a miss, also from a closure', function () {
        expect(CacheContract::pull('external.exchange_rates', [], 'none'))->toBe('none')
            ->and(CacheContract::pull('external.exchange_rates', [], fn () => 'computed'))->toBe('computed');
    });

    it('returns a cached null as null and removes it', function () {
        CacheContract::put('user.profile', ['user_id' => 1], null);

        expect(CacheContract::pull('user.profile', ['user_id' => 1], 'default'))->toBeNull()
            ->and(CacheContract::has('user.profile', ['user_id' => 1]))->toBeFalse();
    });

    // Stores without GETDEL; on Redis the read and the delete are one command.
    it('gives the value only to the call that removes it on other stores', function () {
        CacheContract::put('user.profile', ['user_id' => 1], ['token' => 'abc']);

        // Another request removes the entry between this pull's read and its delete.
        Event::listen(CacheHit::class, fn () => Cache::forget(CacheContract::key('user.profile', ['user_id' => 1])));

        expect(CacheContract::pull('user.profile', ['user_id' => 1], 'taken'))->toBe('taken');
    });
});

it('returns the default on a miss, also from a closure', function () {
    expect(CacheContract::get('external.exchange_rates', [], 'none'))->toBe('none')
        ->and(CacheContract::get('external.exchange_rates', [], fn () => 'computed'))->toBe('computed');
});

it('keeps falsy values as hits', function (mixed $value) {
    $calls = 0;
    $loader = function () use (&$calls, $value) {
        $calls++;

        return $value;
    };

    CacheContract::remember('external.exchange_rates', [], $loader);

    expect(CacheContract::remember('external.exchange_rates', [], $loader))->toBe($value)
        ->and($calls)->toBe(1)
        ->and(CacheContract::has('external.exchange_rates'))->toBeTrue()
        ->and(CacheContract::get('external.exchange_rates', [], 'default'))->toBe($value);
})->with([
    'null' => [null],
    'false' => [false],
    'zero' => [0],
    'empty string' => [''],
    'empty array' => [[]],
]);

it('refreshes a cached value', function () {
    CacheContract::put('external.exchange_rates', [], ['usd' => 1]);

    expect(CacheContract::refresh('external.exchange_rates', [], fn () => ['usd' => 2]))->toBe(['usd' => 2])
        ->and(CacheContract::get('external.exchange_rates'))->toBe(['usd' => 2]);
});

it('expires entries after the TTL of the definition', function () {
    CacheContract::put('user.active_list', ['role' => 'admin'], [1, 2]);

    $this->travel(59)->seconds();
    expect(CacheContract::has('user.active_list', ['role' => 'admin']))->toBeTrue();

    $this->travel(2)->seconds();
    expect(CacheContract::has('user.active_list', ['role' => 'admin']))->toBeFalse();
});

it('treats an entry written by other code as a miss and overwrites it', function () {
    Cache::put('cc:external:exchange_rates:v1:-', 'not an envelope', 60);

    expect(CacheContract::has('external.exchange_rates'))->toBeFalse()
        ->and(CacheContract::remember('external.exchange_rates', [], fn () => ['usd' => 1]))->toBe(['usd' => 1])
        ->and(Cache::get('cc:external:exchange_rates:v1:-'))->toBe([['usd' => 1]]);
});

it('fails loudly on an unknown definition or wrong parameters', function (Closure $call, string $exception) {
    expect($call)->toThrow($exception);
})->with([
    'unknown id' => [fn () => CacheContract::get('user.profle', ['user_id' => 1]), UnknownDefinition::class],
    'missing parameter' => [fn () => CacheContract::remember('user.profile', [], fn () => 1), InvalidParameters::class],
    'wrong type' => [fn () => CacheContract::put('user.profile', ['user_id' => '1'], 1), InvalidParameters::class],
]);

it('does not run the loader when the parameters are wrong', function () {
    $ran = false;

    try {
        CacheContract::remember('user.profile', ['id' => 1], function () use (&$ran) {
            $ran = true;
        });
    } catch (InvalidParameters) {
    }

    expect($ran)->toBeFalse();
});

it('uses the configured store', function () {
    config()->set('cache.stores.contract', ['driver' => 'array']);
    config()->set('cache-contract.store', 'contract');

    CacheContract::put('external.exchange_rates', [], ['usd' => 1]);

    expect(Cache::store('contract')->get('cc:external:exchange_rates:v1:-'))->toBe([['usd' => 1]])
        ->and(Cache::get('cc:external:exchange_rates:v1:-'))->toBeNull();
});

describe('context', function () {
    it('adds the context to the key', function () {
        expect(CacheContract::withContext(['tenant_id' => 7])->key('user.active_list', ['role' => 'admin']))
            ->toBe('cc:user:active_list:v1:role=admin:@tenant_id=7')
            ->and(CacheContract::withContext(['tenant_id' => 7])->withContext(['locale' => 'tr'])->context())
            ->toBe(['tenant_id' => 7, 'locale' => 'tr']);
    });

    it('keeps tenants apart and never leaks a context into the next call', function () {
        // Two requests or jobs in a row on the same worker (Octane, queues).
        CacheContract::withContext(['tenant_id' => 1])->put('user.active_list', ['role' => 'admin'], ['alice']);
        CacheContract::withContext(['tenant_id' => 2])->put('user.active_list', ['role' => 'admin'], ['bob']);

        expect(CacheContract::withContext(['tenant_id' => 1])->get('user.active_list', ['role' => 'admin']))->toBe(['alice'])
            ->and(CacheContract::withContext(['tenant_id' => 2])->get('user.active_list', ['role' => 'admin']))->toBe(['bob'])
            // The shared instance never picked up a tenant.
            ->and(CacheContract::context())->toBe([])
            ->and(app(CacheContractManager::class)->context())->toBe([])
            ->and(CacheContract::get('user.active_list', ['role' => 'admin']))->toBeNull();
    });

    it('returns a new instance instead of changing the shared one', function () {
        $shared = app(CacheContractManager::class);
        $scoped = $shared->withContext(['tenant_id' => 7]);

        expect($scoped)->not->toBe($shared)
            ->and($shared->context())->toBe([]);
    });
});

describe('generation counters', function () {
    beforeEach(function () {
        config()->set('cache-contract.definitions.company', [
            'summary' => [
                'purpose' => 'summary', 'type' => 'array', 'params' => ['company_id' => 'int'], 'ttl' => 300,
                'description' => 'Company summary.', 'invalidated_by' => [Company::class],
            ],
            'billing' => [
                'purpose' => 'billing', 'type' => 'array', 'ttl' => 300, 'description' => 'Billing data.',
                'tags' => ['billing', 'companies'], 'invalidated_by' => [Company::class],
            ],
            'logo' => [
                'purpose' => 'logo', 'type' => 'string', 'ttl' => 300, 'description' => 'Logo URL.', 'tags' => ['companies'],
            ],
        ]);
    });

    it('ends the key with the current value of the definition\'s counter', function () {
        $generation = app(GenerationCounters::class)->currentOrFail(['def:company.summary'])['def:company.summary'];

        expect(CacheContract::key('company.summary', ['company_id' => 3]))->toBe("cc:company:summary:v1:company_id=3:~g{$generation}");
    });

    it('adds a counter per tag, so a definition with tags only has one too', function () {
        $generations = app(GenerationCounters::class)->currentOrFail(['billing', 'companies', 'def:company.billing']);

        expect(CacheContract::key('company.billing'))->toBe('cc:company:billing:v1:~g'.implode('.', $generations))
            ->and(CacheContract::key('company.logo'))->toBe("cc:company:logo:v1:~g{$generations['companies']}");
    });

    it('leaves the key of a definition without tags or invalidated_by as it was', function () {
        expect(CacheContract::key('external.exchange_rates'))->toBe('cc:external:exchange_rates:v1:-');
    });

    it('stops reading every entry of the definition once its counter gets a new value', function () {
        $calls = 0;
        $loader = function () use (&$calls) {
            return ++$calls;
        };
        CacheContract::remember('company.summary', ['company_id' => 3], $loader);
        CacheContract::withContext(['tenant_id' => 7])->put('company.summary', ['company_id' => 4], 'cached');

        app(GenerationCounters::class)->bump(['def:company.summary']);

        expect(CacheContract::remember('company.summary', ['company_id' => 3], $loader))->toBe(2)
            ->and(CacheContract::withContext(['tenant_id' => 7])->has('company.summary', ['company_id' => 4]))->toBeFalse()
            ->and(CacheContract::get('company.summary', ['company_id' => 3]))->toBe(2);
    });

    it('writes a loaded value under the generation read before the loader ran', function () {
        // An invalidation while the loader runs: the value it loaded may be stale.
        $value = CacheContract::remember('company.summary', ['company_id' => 3], function () {
            app(GenerationCounters::class)->bump(['def:company.summary']);

            return 'loaded before the change';
        });

        expect($value)->toBe('loaded before the change')
            ->and(CacheContract::has('company.summary', ['company_id' => 3]))->toBeFalse();
    });
});
