<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Uslanozan\CacheContract\Eloquent\Concerns\HasCache;
use Uslanozan\CacheContract\Eloquent\ProvidesCacheContext;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Scopes\TenantScope;

/**
 * A tenant-scoped model whose cacheContext() throws, like one that reads a
 * missing tenant. Table: `notes`.
 */
#[ScopedBy([TenantScope::class])]
final class ThrowingContextNote extends Model implements ProvidesCacheContext
{
    use HasCache;

    protected $table = 'notes';

    protected $guarded = [];

    public $timestamps = false;

    public function cacheContext(): array
    {
        throw new RuntimeException('No tenant');
    }
}
