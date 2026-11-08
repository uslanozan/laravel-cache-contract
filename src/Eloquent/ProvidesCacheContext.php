<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Eloquent;

/**
 * A model whose global scopes need a context (for example a tenant) tells
 * the model cache that context from its own columns, so a change to the
 * model can clear exactly its entries:
 *
 *     class Project extends Model implements ProvidesCacheContext
 *     {
 *         use HasCache;
 *
 *         public function cacheContext(): array
 *         {
 *             return ['tenant_id' => $this->tenant_id];
 *         }
 *     }
 *
 * It must return the same values that reads pass to withContext().
 */
interface ProvidesCacheContext
{
    /**
     * @return array<string, scalar> e.g. ['tenant_id' => 7]
     */
    public function cacheContext(): array;
}
