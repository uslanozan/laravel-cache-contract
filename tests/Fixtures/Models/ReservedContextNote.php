<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Eloquent\Concerns\HasCache;
use Uslanozan\CacheContract\Eloquent\ProvidesCacheContext;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Scopes\TenantScope;

/**
 * A tenant-scoped model whose cache context uses a reserved name, so the
 * model cache rejects it. Table: `notes`.
 */
#[ScopedBy([TenantScope::class])]
final class ReservedContextNote extends Model implements ProvidesCacheContext
{
    use HasCache;

    protected $table = 'notes';

    protected $guarded = [];

    public $timestamps = false;

    public function cacheContext(): array
    {
        return ['connection' => 'tenant'];
    }
}
