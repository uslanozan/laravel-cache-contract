<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Only orders the rows: it never changes which row an ID returns.
 *
 * @implements Scope<Model>
 */
final class LatestFirstScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->orderByDesc($model->getQualifiedKeyName());
    }
}
