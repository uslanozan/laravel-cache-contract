<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Scopes\TenantScope;

/** A model with a tenant global scope. Table: `projects`. */
#[ScopedBy([TenantScope::class])]
final class Project extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
