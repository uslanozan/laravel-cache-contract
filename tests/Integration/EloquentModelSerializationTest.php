<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Fixtures\Models\MemberStatus;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Organization;

/*
 * Characterization tests for ADR 0008: how an Eloquent model survives Laravel's
 * Redis cache when stored (a) whole, through serialize(), or (b) as its raw
 * attributes, rebuilt with newFromBuilder(). They pin Laravel's behaviour, not
 * package code. Values are wrapped in the one-element envelope of ADR 0007.
 */

const SERIALIZATION_PREFIX = 'cache_contract_serialization:';

function serializationRedis(): RedisManager
{
    return new RedisManager(app(), 'phpredis', [
        'options' => ['prefix' => ''],
        'cache' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => 1,
            'timeout' => 1.0,
            'read_timeout' => 1.0,
        ],
    ]);
}

function serializationCache(RedisManager $redis): Repository
{
    return new Repository(new RedisStore($redis, SERIALIZATION_PREFIX, 'cache'));
}

function serializationKey(): string
{
    return 'k:'.bin2hex(random_bytes(8));
}

function rebuild(mixed $attributes): Member
{
    return (new Member)->newFromBuilder($attributes);
}

beforeEach(function () {
    config()->set('database.connections.serialization', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('database.default', 'serialization');
    DB::purge('serialization');

    Schema::create('organizations', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('members', function ($table) {
        $table->id();
        $table->foreignId('organization_id');
        $table->string('name');
        $table->string('password');
        $table->json('settings');
        $table->boolean('is_admin');
        $table->string('status');
        $table->timestamp('last_login_at');
        $table->timestamps();
    });

    Organization::create(['name' => 'Acme']);
    Member::create([
        'organization_id' => 1,
        'name' => 'Ada',
        'password' => 'HASHED-SECRET',
        'settings' => ['theme' => 'dark'],
        'is_admin' => true,
        'status' => MemberStatus::Active,
        'last_login_at' => '2026-10-13 21:00:00',
    ]);

    $this->redis = serializationRedis();
    $this->cache = serializationCache($this->redis);
});

it('round-trips casts through both payloads', function () {
    $member = Member::query()->findOrFail(1);
    [$whole, $attributes] = [serializationKey(), serializationKey()];

    $this->cache->put($whole, [$member], 60);
    $this->cache->put($attributes, [$member->getAttributes()], 60);

    foreach ([$this->cache->get($whole)[0], rebuild($this->cache->get($attributes)[0])] as $read) {
        expect($read)->toBeInstanceOf(Member::class)
            ->and($read->exists)->toBeTrue()
            ->and($read->isDirty())->toBeFalse()
            ->and($read->settings)->toBe(['theme' => 'dark'])
            ->and($read->is_admin)->toBeTrue()
            ->and($read->status)->toBe(MemberStatus::Active)
            ->and($read->last_login_at)->toBeInstanceOf(Carbon::class);
    }
});

it('keeps the cast configuration a whole model was written with', function () {
    // A class cannot be redefined inside one process, so a cast changed on the
    // instance stands in for a cast changed in the class after the write.
    $member = Member::query()->findOrFail(1)->mergeCasts(['settings' => 'collection']);
    [$whole, $attributes] = [serializationKey(), serializationKey()];

    $this->cache->put($whole, [$member], 60);
    $this->cache->put($attributes, [$member->getAttributes()], 60);

    expect($this->cache->get($whole)[0]->settings)->toBeInstanceOf(Collection::class)
        ->and(rebuild($this->cache->get($attributes)[0])->settings)->toBe(['theme' => 'dark']);
});

it('reads a whole model whose class no longer exists as an incomplete object', function () {
    $key = serializationKey();
    $this->cache->put($key, [Member::query()->findOrFail(1)], 60);

    $renamed = 'Gone\\RenamedMember';
    $payload = str_replace(
        'O:'.strlen(Member::class).':"'.Member::class.'"',
        'O:'.strlen($renamed).':"'.$renamed.'"',
        (string) $this->redis->connection('cache')->get(SERIALIZATION_PREFIX.$key),
    );
    $this->redis->connection('cache')->setex(SERIALIZATION_PREFIX.$key, 60, $payload);

    expect($this->cache->get($key)[0])->toBeInstanceOf(__PHP_Incomplete_Class::class);
});

it('embeds loaded relations in a whole model, where they go stale', function () {
    $key = serializationKey();
    $this->cache->put($key, [Member::query()->with('organization')->findOrFail(1)], 60);

    Organization::query()->whereKey(1)->update(['name' => 'Renamed Inc']);

    expect($this->cache->get($key)[0]->organization->name)->toBe('Acme');
});

it('stores columns only, without relations, in an attributes payload', function () {
    $member = Member::query()->with('organization')->findOrFail(1);

    expect($member->getAttributes())->toHaveKeys(['id', 'organization_id', 'name', 'settings'])
        ->not->toHaveKey('organization')
        ->and(rebuild($member->getAttributes())->relationLoaded('organization'))->toBeFalse();
});

it('writes hidden columns to Redis in both payloads', function () {
    $member = Member::query()->findOrFail(1);
    [$whole, $attributes] = [serializationKey(), serializationKey()];

    $this->cache->put($whole, [$member], 60);
    $this->cache->put($attributes, [$member->getAttributes()], 60);

    foreach ([$whole, $attributes] as $key) {
        expect((string) $this->redis->connection('cache')->get(SERIALIZATION_PREFIX.$key))
            ->toContain('HASHED-SECRET');
    }
});

it('fires the retrieved event only when rebuilding from attributes', function () {
    $member = Member::query()->findOrFail(1);
    [$whole, $attributes] = [serializationKey(), serializationKey()];
    $this->cache->put($whole, [$member], 60);
    $this->cache->put($attributes, [$member->getAttributes()], 60);

    $retrieved = 0;
    Member::retrieved(function (Model $model) use (&$retrieved) {
        $retrieved++;
    });

    $this->cache->get($whole);
    expect($retrieved)->toBe(0);

    rebuild($this->cache->get($attributes)[0]);
    expect($retrieved)->toBe(1);
});
