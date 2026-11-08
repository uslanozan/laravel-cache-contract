<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Exceptions;

use LogicException;
use Uslanozan\CacheContract\Eloquent\ProvidesCacheContext;

/**
 * The model cache cannot cache this model, or not in the way it was asked to.
 * Each message says what to change.
 */
final class ModelNotCachable extends LogicException implements CacheContractException
{
    public static function notAModel(string $class): self
    {
        return new self(sprintf('"%s" is not an Eloquent model class.', $class));
    }

    public static function unsupportedTable(string $class, string $table): self
    {
        return new self(sprintf(
            'Cannot build a cache key for %s: its table name "%s" must be snake_case (lowercase letters, digits and single underscores).',
            $class,
            $table,
        ));
    }

    public static function unsupportedKeyType(string $class, string $keyType): self
    {
        return new self(sprintf('Cannot cache %s: its key type "%s" is neither "int" nor "string".', $class, $keyType));
    }

    public static function notSoftDeletable(string $class): self
    {
        return new self(sprintf('withTrashed() needs a model that uses SoftDeletes; %s does not.', $class));
    }

    public static function missingCacheContext(string $class): self
    {
        return new self(sprintf(
            '%s needs a context and uses HasCache, so a change to it can only clear its cache entries if it '
            .'implements %s: add cacheContext() returning the values you pass to withContext(), '
            .'e.g. [\'tenant_id\' => $this->tenant_id].',
            $class,
            ProvidesCacheContext::class,
        ));
    }

    /**
     * @param  list<string>  $scopes
     */
    public static function scopesNeedContext(string $class, array $scopes): self
    {
        return new self(sprintf(
            '%s has global scopes (%s) that can change which row an ID returns, for example per tenant. '
            .'Pass the values they depend on with withContext([...]) so they become part of the key '
            .'(the context must contain them, such as the tenant; an unrelated value turns this check off), '
            .'or list the scopes under cache-contract.models.overrides.%s.safe_scopes if their result does not depend on '
            .'the request, the user or the tenant (they only order rows, or apply the same filter on every request).',
            $class,
            implode(', ', $scopes),
            $class,
        ));
    }
}
