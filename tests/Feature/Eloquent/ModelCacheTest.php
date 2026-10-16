<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Uslanozan\CacheContract\Eloquent\ModelCacheFactory;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Exceptions\ModelNotCachable;
use Uslanozan\CacheContract\Exceptions\UnknownDefinition;
use Uslanozan\CacheContract\Facades\CacheContract;
use Uslanozan\CacheContract\Facades\ModelCache;
use Uslanozan\CacheContract\Runtime\RedisErrorPolicy;
use Uslanozan\CacheContract\Tests\Fakes\FailingStore;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Article;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Fixtures\Models\MemberStatus;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Project;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Ranking;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Scopes\LatestFirstScope;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Scopes\TenantScope;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Token;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;

/** Number of database queries $operation runs. */
function modelCacheQueries(Closure $operation): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $operation();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

/** Points the "tenant" connection at this SQLite file, as a tenancy package would. */
function modelCacheUseTenantDatabase(string $path): void
{
    config()->set('database.connections.tenant', ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']);
    config()->set('database.default', 'tenant');
    DB::purge('tenant');
}

beforeEach(function () {
    ModelCacheDatabase::create();
});

describe('find', function () {
    it('loads a model once and serves it from the cache afterwards', function () {
        $first = null;
        $second = null;

        expect(modelCacheQueries(function () use (&$first) {
            $first = ModelCache::for(Member::class)->find(1);
        }))->toBe(1)
            ->and(modelCacheQueries(function () use (&$second) {
                $second = ModelCache::for(Member::class)->find(1);
            }))->toBe(0);

        expect($second)->toBeInstanceOf(Member::class)
            ->and($second->exists)->toBeTrue()
            ->and($second->isDirty())->toBeFalse()
            ->and($second->getConnectionName())->toBe(ModelCacheDatabase::CONNECTION)
            ->and($second->getAttributes())->toBe($first->getAttributes())
            ->and($second->settings)->toBe(['theme' => 'dark'])
            ->and($second->status)->toBe(MemberStatus::Active)
            ->and($second->last_login_at)->toBeInstanceOf(Carbon::class);
    });

    it('caches an ID with no row as null', function () {
        expect(ModelCache::for(Member::class)->find(999))->toBeNull()
            ->and(modelCacheQueries(fn () => expect(ModelCache::for(Member::class)->find(999))->toBeNull()))->toBe(0);
    });

    it('builds the key from the table, the version, the ID, the connection and its database', function () {
        expect(ModelCache::for(Member::class)->key(1))->toBe('cc:model:members:v1:id=1:@connection=testing:@database=%3Amemory%3A');
    });

    it('accepts a numeric string for an integer key and rejects anything else', function () {
        $cache = ModelCache::for(Member::class);

        expect($cache->key('1'))->toBe($cache->key(1))
            ->and(fn () => $cache->find('one'))->toThrow(InvalidParameters::class);
    });

    it('supports string primary keys', function () {
        $cache = ModelCache::for(Token::class);

        expect($cache->find('abc-123')?->name)->toBe('API')
            ->and($cache->key('abc-123'))->toBe('cc:model:tokens:v1:id=abc-123:@connection=testing:@database=%3Amemory%3A');
    });

    it('keeps model entries out of the definition registry', function () {
        ModelCache::for(Member::class)->find(1);

        expect(fn () => CacheContract::get('model.members', ['id' => 1]))->toThrow(UnknownDefinition::class);
    });
});

describe('configuration', function () {
    it('expires entries after the default TTL', function () {
        ModelCache::for(Member::class)->find(1);
        $this->travel(3601)->seconds();

        expect(modelCacheQueries(fn () => ModelCache::for(Member::class)->find(1)))->toBe(1);
    });

    it('uses the TTL and version of a model override', function () {
        config()->set('cache-contract.models.overrides', [Member::class => ['ttl' => 60, 'version' => 2]]);

        ModelCache::for(Member::class)->find(1);
        $this->travel(61)->seconds();

        expect(ModelCache::for(Member::class)->key(1))->toBe('cc:model:members:v2:id=1:@connection=testing:@database=%3Amemory%3A')
            ->and(modelCacheQueries(fn () => ModelCache::for(Member::class)->find(1)))->toBe(1);
    });

    it('rejects invalid model settings', function (array $models, string $message) {
        config()->set('cache-contract.models', $models);

        expect(fn () => ModelCache::for(Member::class))->toThrow(InvalidConfiguration::class, $message);
    })->with([
        'zero TTL' => [['ttl' => 0], 'models.ttl'],
        'missing TTL' => [['overrides' => []], 'models.ttl'],
        'not a model' => [['ttl' => 60, 'overrides' => [stdClass::class => []]], 'models.overrides.stdClass'],
        'unknown setting' => [['ttl' => 60, 'overrides' => [Member::class => ['tll' => 60]]], 'unknown setting "tll"'],
        'version zero' => [['ttl' => 60, 'overrides' => [Member::class => ['version' => 0]]], '.version'],
        'scopes not a list' => [['ttl' => 60, 'overrides' => [Member::class => ['safe_scopes' => 'x']]], '.safe_scopes'],
    ]);

    it('rejects a class that is not a model', function () {
        expect(fn () => ModelCache::for(stdClass::class))->toThrow(ModelNotCachable::class, 'not an Eloquent model');
    });
});

describe('databases', function () {
    it('keeps two databases behind one connection name apart', function () {
        // Multi-database tenancy: the connection name stays "tenant", its database changes per tenant.
        $databases = [];
        foreach (['tenant_a' => 'Ayşe', 'tenant_b' => 'Mehmet'] as $tenant => $name) {
            $databases[$tenant] = tempnam(sys_get_temp_dir(), $tenant.'_');
            modelCacheUseTenantDatabase($databases[$tenant]);
            Schema::create('tokens', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
            });
            Token::create(['id' => 'user-5', 'name' => $name]);
        }

        modelCacheUseTenantDatabase($databases['tenant_a']);
        $keyA = ModelCache::for(Token::class)->key('user-5');
        expect(ModelCache::for(Token::class)->find('user-5')?->name)->toBe('Ayşe');

        modelCacheUseTenantDatabase($databases['tenant_b']);
        expect(ModelCache::for(Token::class)->key('user-5'))->not->toBe($keyA)
            ->and(ModelCache::for(Token::class)->find('user-5')?->name)->toBe('Mehmet');

        array_map(unlink(...), $databases);
    });
});

describe('context', function () {
    it('adds the context to the key and returns a new instance', function () {
        $shared = ModelCache::for(Project::class);
        $tenant = $shared->withContext(['tenant_id' => 7]);

        expect($tenant)->not->toBe($shared)
            ->and($tenant->key(5))->toBe('cc:model:projects:v1:id=5:@connection=testing:@database=%3Amemory%3A:@tenant_id=7')
            ->and(app(ModelCacheFactory::class))->toBe(app(ModelCacheFactory::class));
    });

    it('rejects context names the model cache sets itself', function (string $name) {
        expect(fn () => ModelCache::for(Member::class)->withContext([$name => 'x']))
            ->toThrow(InvalidParameters::class, 'reserved');
    })->with(['connection', 'database', 'trashed']);
});

describe('global scopes', function () {
    it('refuses a model with a global scope when no context is given', function () {
        expect(fn () => ModelCache::for(Project::class)->find(5))
            ->toThrow(ModelNotCachable::class, TenantScope::class);
    });

    it('keeps tenants apart', function () {
        expect(ModelCache::for(Project::class)->withContext(['tenant_id' => 7])->key(5))
            ->not->toBe(ModelCache::for(Project::class)->withContext(['tenant_id' => 8])->key(5));

        expect(ModelCache::for(Project::class)->withContext(['tenant_id' => 7])->find(5)?->name)->toBe('Apollo');

        app()->instance('fixture.tenant', 8);
        expect(ModelCache::for(Project::class)->withContext(['tenant_id' => 8])->find(5))->toBeNull();

        app()->instance('fixture.tenant', 7);
        expect(modelCacheQueries(fn () => expect(
            ModelCache::for(Project::class)->withContext(['tenant_id' => 7])->find(5)?->name,
        )->toBe('Apollo')))->toBe(0);
    });

    it('refuses a scope that is not listed as safe', function () {
        expect(fn () => ModelCache::for(Ranking::class)->find(1))->toThrow(ModelNotCachable::class, LatestFirstScope::class);
    });

    it('accepts scopes listed as safe for the model', function () {
        config()->set('cache-contract.models.overrides', [Ranking::class => ['safe_scopes' => [LatestFirstScope::class]]]);

        expect(ModelCache::for(Ranking::class)->find(1)?->name)->toBe('First');
    });

    it('keeps refusing while one scope is not listed as safe', function () {
        config()->set('cache-contract.models.overrides', [Project::class => ['safe_scopes' => [LatestFirstScope::class]]]);

        // Only the unlisted scope is named: the safe one is allowed, scope by scope.
        expect(fn () => ModelCache::for(Project::class)->find(5))
            ->toThrow(ModelNotCachable::class, '('.TenantScope::class.')');
    });

    it('needs no context for SoftDeletes alone', function () {
        expect(ModelCache::for(Article::class)->find(1)?->title)->toBe('Hello');
    });
});

describe('soft deletes', function () {
    it('hides trashed models unless asked, under a separate key', function () {
        Article::query()->findOrFail(1)->delete();
        $cache = ModelCache::for(Article::class);

        expect($cache->find(1))->toBeNull()
            ->and($cache->withTrashed()->find(1)?->trashed())->toBeTrue()
            ->and($cache->withTrashed()->key(1))->toBe('cc:model:articles:v1:id=1:@connection=testing:@database=%3Amemory%3A:@trashed=with')
            ->and($cache->find(1))->toBeNull();
    });

    it('rejects withTrashed on a model without SoftDeletes', function () {
        expect(fn () => ModelCache::for(Member::class)->withTrashed())->toThrow(ModelNotCachable::class, 'SoftDeletes');
    });
});

describe('Redis errors', function () {
    beforeEach(function () {
        $store = $this->store = new FailingStore;
        Cache::extend('failing', fn () => Cache::repository($store));
        config()->set('cache.stores.failing', ['driver' => 'failing']);
        config()->set('cache-contract.store', 'failing');
        Exceptions::fake();
    });

    it('loads from the database and skips the write when the read fails', function () {
        $this->store->fail('get');

        expect(ModelCache::for(Member::class)->find(1)?->name)->toBe('Ada')
            ->and($this->store->calls)->toBe(['get']);

        Exceptions::assertReported(RedisException::class);
    });

    it('returns the loaded model when the write fails', function () {
        $this->store->fail('put');

        expect(ModelCache::for(Member::class)->find(1)?->name)->toBe('Ada');

        Exceptions::assertReported(RedisException::class);
    });

    it('follows the same policy as the facade', function () {
        config()->set('cache-contract.redis_errors.read', 'throw');
        config()->set('cache-contract.definitions.external.rates', [
            'purpose' => 'rates', 'type' => 'array', 'ttl' => 600, 'description' => 'Rates.',
        ]);
        $this->store->fail('get');

        expect(app(RedisErrorPolicy::class))->toBe(app(RedisErrorPolicy::class))
            ->and(fn () => CacheContract::get('external.rates'))->toThrow(RedisException::class)
            ->and(fn () => ModelCache::for(Member::class)->find(1))->toThrow(RedisException::class);
    });
});
