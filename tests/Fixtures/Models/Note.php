<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Eloquent\Concerns\HasCache;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Scopes\TenantScope;

/**
 * A tenant-scoped model with HasCache that does not give its cache context
 * (no ProvidesCacheContext), on purpose. Table: `notes`.
 */
#[ScopedBy([TenantScope::class])]
final class Note extends Model
{
    use HasCache;

    protected $guarded = [];

    public $timestamps = false;
}
