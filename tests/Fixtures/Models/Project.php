<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Eloquent\Concerns\HasCache;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Scopes\LatestFirstScope;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Scopes\TenantScope;

/** A model with a tenant global scope and a safe ordering one. Table: `projects`. */
#[ScopedBy([TenantScope::class, LatestFirstScope::class])]
final class Project extends Model
{
    use HasCache;

    protected $guarded = [];

    public $timestamps = false;
}
