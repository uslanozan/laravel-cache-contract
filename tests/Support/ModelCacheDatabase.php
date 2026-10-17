<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Article;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Member;
use Uslanozan\CacheContract\Tests\Fixtures\Models\MemberStatus;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Organization;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Project;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Ranking;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Token;

/**
 * An in-memory SQLite database on the "testing" connection with one row per
 * model-cache fixture: member 1, article 1, ranking 1, project 5 (tenant 7)
 * and token "abc-123". The current tenant is 7.
 */
final class ModelCacheDatabase
{
    public const CONNECTION = 'testing';

    public static function create(): void
    {
        config()->set('database.connections.'.self::CONNECTION, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('database.default', self::CONNECTION);
        DB::purge(self::CONNECTION);

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('members', function (Blueprint $table) {
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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->softDeletes();
        });
        Schema::create('rankings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tenant_id');
            $table->string('name');
        });
        Schema::create('tokens', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
        });

        app()->instance('fixture.tenant', 7);

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
        Article::create(['title' => 'Hello']);
        Ranking::create(['name' => 'First']);
        Project::create(['id' => 5, 'tenant_id' => 7, 'name' => 'Apollo']);
        Token::create(['id' => 'abc-123', 'name' => 'API']);
    }

    /**
     * Adds members after member 1, named "Member 2", "Member 3", …
     *
     * @return list<int> their IDs
     */
    public static function addMembers(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $id = Member::query()->max('id') + 1;
            Member::create([
                'organization_id' => 1,
                'name' => "Member {$id}",
                'password' => 'HASHED-SECRET',
                'settings' => [],
                'is_admin' => false,
                'status' => MemberStatus::Active,
                'last_login_at' => '2026-10-13 21:00:00',
            ]);
            $ids[] = $id;
        }

        return $ids;
    }
}
