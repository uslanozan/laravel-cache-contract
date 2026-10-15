<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Scopes\LatestFirstScope;

/** A model whose only global scope orders rows. Table: `rankings`. */
#[ScopedBy([LatestFirstScope::class])]
final class Ranking extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
