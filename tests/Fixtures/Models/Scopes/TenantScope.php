<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Limits rows to the current tenant, read from the container ("fixture.tenant"). */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('tenant_id'), app('fixture.tenant'));
    }
}
