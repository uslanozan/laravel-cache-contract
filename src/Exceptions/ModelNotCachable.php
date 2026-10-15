<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Exceptions;

use LogicException;

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

    /**
     * @param  list<string>  $scopes
     */
    public static function scopesNeedContext(string $class, array $scopes): self
    {
        return new self(sprintf(
            '%s has global scopes (%s) that can change which row an ID returns, for example per tenant. '
            .'Pass what they depend on with withContext([...]) so it becomes part of the key, '
            .'or list the scopes under cache-contract.models.overrides.%s.safe_scopes if they never change the row.',
            $class,
            implode(', ', $scopes),
            $class,
        ));
    }
}
