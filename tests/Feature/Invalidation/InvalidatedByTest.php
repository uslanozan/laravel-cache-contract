<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Uslanozan\CacheContract\CacheContractServiceProvider;
use Uslanozan\CacheContract\Contracts\DefinitionRegistry;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;
use Uslanozan\CacheContract\Exceptions\InvalidDefinitions;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Runtime\GenerationCounters;
use Uslanozan\CacheContract\Runtime\RedisErrorPolicy;
use Uslanozan\CacheContract\Tests\Fakes\FailingStore;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Article;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Ledger;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Fixtures\Models\MemberStatus;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Organization;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;

/**
 * Sets the definitions and boots the provider again, as an application
 * would boot with them: the listeners are registered from the config at boot.
 *
 * @param  mixed  $definitions  the "definitions" config, valid or not
 */
function bootWithDefinitions(mixed $definitions): void
{
    config()->set('cache-contract.definitions', $definitions);
    app()->register(CacheContractServiceProvider::class, true);
}

/** @return array<string, array<string, array<string, mixed>>> */
function invalidatedByDefinitions(): array
{
    return [
        'member' => [
            'active_list' => [
                'purpose' => 'active_list', 'type' => 'array', 'params' => ['organization_id' => 'int'],
                'ttl' => 300, 'description' => 'Names of the active members of an organization.',
                'invalidated_by' => [Member::class],
            ],
            'count' => [
                'purpose' => 'count', 'type' => 'int', 'ttl' => 300, 'description' => 'Number of members.',
            ],
        ],
        'organization' => [
            'summary' => [
                'purpose' => 'summary', 'type' => 'array', 'ttl' => 300, 'description' => 'Organization and its members.',
                'invalidated_by' => [Organization::class, Member::class],
            ],
        ],
        'article' => [
            'titles' => [
                'purpose' => 'titles', 'type' => 'array', 'ttl' => 300, 'description' => 'Titles of the visible articles.',
                'invalidated_by' => [Article::class],
            ],
        ],
        'ledger' => [
            'names' => [
                'purpose' => 'names', 'type' => 'array', 'ttl' => 300, 'description' => 'Ledger names.',
                'invalidated_by' => [Ledger::class],
            ],
        ],
    ];
}

/** @return list<string> */
function activeMemberNames(int $organizationId): array
{
    /** @var list<string> */
    return Member::query()
        ->where('organization_id', $organizationId)
        ->where('status', MemberStatus::Active)
        ->orderBy('id')
        ->pluck('name')
        ->all();
}

function addActiveMember(string $name, int $organizationId = 1): Member
{
    return Member::create([
        'organization_id' => $organizationId, 'name' => $name, 'password' => 'x', 'settings' => [],
        'is_admin' => false, 'status' => MemberStatus::Active, 'last_login_at' => '2026-10-13 21:00:00',
    ]);
}

beforeEach(function () {
    ModelCacheDatabase::create(); // seeded without model events
    bootWithDefinitions(invalidatedByDefinitions());
});

describe('a change to a listed model', function () {
    it('invalidates a list when a new row enters it', function () {
        $list = fn () => CacheContract::remember('member.active_list', ['organization_id' => 1], fn () => activeMemberNames(1));

        expect($list())->toBe(['Ada']);

        addActiveMember('Grace');

        expect($list())->toBe(['Ada', 'Grace']);
    });

    it('invalidates when a row leaves the list', function () {
        $list = fn () => CacheContract::remember('member.active_list', ['organization_id' => 1], fn () => activeMemberNames(1));
        expect($list())->toBe(['Ada']);

        $member = Member::query()->findOrFail(1);
        $member->status = MemberStatus::Banned;
        $member->save();

        expect($list())->toBe([]);
    });

    it('invalidates on delete and on restore', function () {
        $titles = fn () => CacheContract::remember('article.titles', [], fn () => Article::query()->pluck('title')->all());
        expect($titles())->toBe(['Hello']);

        Article::query()->findOrFail(1)->delete();
        expect($titles())->toBe([]);

        Article::withTrashed()->findOrFail(1)->restore();
        expect($titles())->toBe(['Hello']);
    });

    it('works for a model without HasCache', function () {
        $loads = 0;
        $summary = function () use (&$loads) {
            return CacheContract::remember('organization.summary', [], function () use (&$loads) {
                $loads++;

                return Organization::query()->pluck('name')->all();
            });
        };
        expect($summary())->toBe(['Acme']);

        $organization = Organization::query()->findOrFail(1);
        $organization->name = 'Acme Inc';
        $organization->save();

        expect($summary())->toBe(['Acme Inc'])
            ->and($loads)->toBe(2);
    });

    it('invalidates every entry of the definition, for every parameter and context', function () {
        ModelCacheDatabase::addMembers(1);
        $tenant7 = CacheContract::withContext(['tenant_id' => 7]);
        $tenant7->put('member.active_list', ['organization_id' => 1], ['cached']);
        CacheContract::put('member.active_list', ['organization_id' => 1], ['cached']);
        CacheContract::put('member.active_list', ['organization_id' => 2], ['cached']);

        addActiveMember('Grace', 2);

        expect($tenant7->has('member.active_list', ['organization_id' => 1]))->toBeFalse()
            ->and(CacheContract::has('member.active_list', ['organization_id' => 1]))->toBeFalse()
            ->and(CacheContract::has('member.active_list', ['organization_id' => 2]))->toBeFalse();
    });

    it('invalidates every definition that lists the model, and no other', function () {
        CacheContract::put('member.active_list', ['organization_id' => 1], ['cached']);
        CacheContract::put('organization.summary', [], ['cached']);
        CacheContract::put('article.titles', [], ['cached']);
        CacheContract::put('member.count', [], 1);

        addActiveMember('Grace');

        expect(CacheContract::has('member.active_list', ['organization_id' => 1]))->toBeFalse()
            ->and(CacheContract::has('organization.summary'))->toBeFalse()
            ->and(CacheContract::has('article.titles'))->toBeTrue()
            ->and(CacheContract::get('member.count'))->toBe(1);
    });

    it('registers one listener per listed model and event, whatever the number of definitions', function () {
        expect(app('events')->getListeners('eloquent.saved: '.Member::class))->toHaveCount(2) // HasCache and invalidated_by
            ->and(app('events')->getListeners('eloquent.deleted: '.Organization::class))->toHaveCount(1)
            ->and(app('events')->getListeners('eloquent.restored: '.Organization::class))->toHaveCount(1);
    });
});

describe('transactions', function () {
    it('invalidates after the commit, not before', function () {
        $list = fn () => CacheContract::remember('member.active_list', ['organization_id' => 1], fn () => activeMemberNames(1));
        expect($list())->toBe(['Ada']);

        DB::transaction(function () {
            addActiveMember('Grace');

            // Still the committed list inside the transaction.
            expect(CacheContract::get('member.active_list', ['organization_id' => 1]))->toBe(['Ada']);
        });

        expect($list())->toBe(['Ada', 'Grace']);
    });

    it('invalidates nothing when the transaction rolls back', function () {
        CacheContract::put('member.active_list', ['organization_id' => 1], ['Ada']);

        DB::beginTransaction();
        addActiveMember('Grace');
        DB::rollBack();

        expect(CacheContract::get('member.active_list', ['organization_id' => 1]))->toBe(['Ada']);
    });

    it('follows a transaction on the model\'s own connection, not another one', function () {
        CacheContract::put('ledger.names', [], ['Old']);

        try {
            DB::connection(ModelCacheDatabase::CONNECTION)->transaction(function () {
                $ledger = Ledger::query()->findOrFail(1);
                $ledger->name = 'New';
                $ledger->save(); // stored at once: no transaction on its own connection

                throw new RuntimeException('roll back the other connection');
            });
        } catch (RuntimeException) {
        }

        expect(CacheContract::has('ledger.names'))->toBeFalse();

        CacheContract::put('ledger.names', [], ['New']);
        DB::connection(ModelCacheDatabase::SECOND_CONNECTION)->beginTransaction();
        $ledger = Ledger::query()->findOrFail(1);
        $ledger->name = 'Newer';
        $ledger->save();
        DB::connection(ModelCacheDatabase::SECOND_CONNECTION)->rollBack();

        expect(CacheContract::get('ledger.names'))->toBe(['New']);
    });
});

describe('a save never fails because of the cache', function () {
    it('saves and reports an invalid setting', function () {
        Exceptions::fake();
        config()->set('cache-contract.redis_errors.invalidate', 'ignore');
        app()->forgetInstance(RedisErrorPolicy::class);
        app()->forgetInstance(GenerationCounters::class);

        $organization = Organization::query()->findOrFail(1);
        $organization->name = 'Acme Inc';
        $organization->save();

        expect(Organization::query()->findOrFail(1)->name)->toBe('Acme Inc');
        Exceptions::assertReported(InvalidConfiguration::class);
    });

    it('saves and reports when Redis is down, with the default "report"', function () {
        Exceptions::fake();
        $store = new FailingStore;
        Cache::extend('failing', fn () => Cache::repository($store));
        config()->set('cache.stores.failing', ['driver' => 'failing']);
        config()->set('cache-contract.store', 'failing');
        app()->forgetInstance(GenerationCounters::class);
        $store->fail('put');

        $organization = Organization::query()->findOrFail(1);
        $organization->name = 'Acme Inc';
        $organization->save();

        expect(Organization::query()->findOrFail(1)->name)->toBe('Acme Inc');
        Exceptions::assertReported(RedisException::class);
    });
});

describe('boot', function () {
    it('does not load or validate the definitions', function () {
        $loaded = false;
        app()->afterResolving(DefinitionRegistry::class, function () use (&$loaded) {
            $loaded = true;
        });

        bootWithDefinitions(invalidatedByDefinitions());

        expect($loaded)->toBeFalse();
    });

    it('boots with invalid definitions, which still fail on the first cache call', function () {
        $definitions = invalidatedByDefinitions();
        unset($definitions['organization']['summary']['ttl']);
        $definitions['broken'] = [
            'not_a_list' => ['purpose' => 'x', 'invalidated_by' => Member::class],
            'not_classes' => ['purpose' => 'y', 'invalidated_by' => [42, null, '']],
            'not_an_array' => 'oops',
        ];

        bootWithDefinitions($definitions);

        expect(fn () => CacheContract::get('member.active_list', ['organization_id' => 1]))
            ->toThrow(InvalidDefinitions::class, '"ttl" is required');
    });

    it('boots when the definitions are not an array at all', function () {
        expect(fn () => bootWithDefinitions('oops'))->not->toThrow(Throwable::class)
            ->and(fn () => CacheContract::get('member.active_list', ['organization_id' => 1]))->toThrow(InvalidDefinitions::class);
    });
});
