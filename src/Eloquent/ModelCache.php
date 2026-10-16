<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Eloquent;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Throwable;
use Uslanozan\CacheContract\Contracts\KeyFactory;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Exceptions\ModelNotCachable;
use Uslanozan\CacheContract\Runtime\Envelope;
use Uslanozan\CacheContract\Runtime\RedisErrorPolicy;

/**
 * Caches one model class by primary key, without a definition in the config
 * (a convention key, ADR 0006):
 *
 *     ModelCache::for(User::class)->find(5);
 *     ModelCache::for(User::class)->withContext(['tenant_id' => 7])->find(5);
 *
 * The key is "{prefix}:model:{table}:v{version}:id={id}" plus the connection
 * name, the database name and any context, e.g.
 * "cc:model:users:v1:id=5:@connection=mysql:@database=app". The database is
 * part of the key because multi-database tenancy keeps the connection name
 * and switches its database per tenant.
 * An entry holds the row's raw attributes in the ADR 0007 envelope, and a
 * read rebuilds the model with newFromBuilder() (ADR 0008). An ID with no row
 * is cached too, as null.
 *
 * Immutable: withContext() and withTrashed() return a copy, so a tenant set
 * for one call never reaches the next request (Octane, queue workers).
 *
 * @template TModel of Model
 */
final readonly class ModelCache
{
    /** Context names the model cache sets itself. */
    private const RESERVED_CONTEXT = ['connection', 'database', 'trashed'];

    /**
     * @param  class-string<TModel>  $class
     * @param  list<string>  $safeScopes  global scopes that never change which row an ID returns
     * @param  array<string, scalar>  $context
     */
    public function __construct(
        private string $class,
        private Definition $definition,
        private CacheFactory $cache,
        private KeyFactory $keys,
        private RedisErrorPolicy $errors,
        private array $safeScopes = [],
        private ?string $store = null,
        private array $context = [],
        private bool $withTrashed = false,
    ) {}

    /**
     * A copy that adds these values to every key, on top of the context this
     * instance already has. Pass whatever the model's global scopes depend
     * on, such as the tenant.
     *
     * @param  array<string, scalar>  $context  e.g. ['tenant_id' => 7]
     * @return self<TModel>
     *
     * @throws InvalidParameters when a name is reserved for the model cache
     */
    public function withContext(array $context): self
    {
        if ($reserved = array_intersect(array_keys($context), self::RESERVED_CONTEXT)) {
            throw InvalidParameters::forDefinition($this->definition->id, sprintf(
                'context name "%s" is reserved for the model cache',
                implode('", "', $reserved),
            ));
        }

        return new self(
            $this->class, $this->definition, $this->cache, $this->keys, $this->errors,
            $this->safeScopes, $this->store, array_merge($this->context, $context), $this->withTrashed,
        );
    }

    /**
     * A copy that also finds soft-deleted models. Its entries use their own
     * keys, so a trashed model is never returned to a caller who did not ask
     * for it.
     *
     * @return self<TModel>
     *
     * @throws ModelNotCachable when the model does not use SoftDeletes
     */
    public function withTrashed(): self
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($this->class), true)) {
            throw ModelNotCachable::notSoftDeletable($this->class);
        }

        return new self(
            $this->class, $this->definition, $this->cache, $this->keys, $this->errors,
            $this->safeScopes, $this->store, $this->context, true,
        );
    }

    /**
     * The physical key of this ID, for debugging.
     *
     * @throws InvalidParameters when the ID does not match the model's key type
     */
    public function key(int|string $id): string
    {
        $model = new $this->class;

        return $this->makeKey($model, $this->normalizeId($id));
    }

    /**
     * The model with this primary key: from the cache, or from the database
     * and then cached. Null when there is no such row (also cached).
     *
     * @return TModel|null
     *
     * @throws ModelNotCachable when the model has global scopes and no context was given
     * @throws InvalidParameters when the ID does not match the model's key type
     */
    public function find(int|string $id): ?Model
    {
        $model = new $this->class;
        $this->guardGlobalScopes($model);

        $id = $this->normalizeId($id);
        $key = $this->makeKey($model, $id);
        $entry = $this->read($key);

        if (Envelope::isHit($entry)) {
            $attributes = Envelope::unwrap($entry);

            if ($attributes === null) {
                return null;
            }

            if (is_array($attributes)) {
                return $model->newFromBuilder($attributes, $this->connectionName($model));
            }
        }

        /** @var TModel|null $found */
        $found = $this->query($model)->whereKey($id)->first();

        // When the read failed, Redis is down: skip the write that would fail too (ADR 0007).
        if ($entry !== false) {
            $this->write($key, $found?->getAttributes());
        }

        return $found;
    }

    /**
     * Global scopes can make the same ID return a different row, for example
     * per tenant. Without the context they depend on in the key, one tenant's
     * cached model would be served to another.
     */
    private function guardGlobalScopes(Model $model): void
    {
        if ($this->context !== []) {
            return;
        }

        $unsafe = array_values(array_diff(
            array_map(strval(...), array_keys($model->getGlobalScopes())),
            [SoftDeletingScope::class],
            $this->safeScopes,
        ));

        if ($unsafe !== []) {
            throw ModelNotCachable::scopesNeedContext($this->class, $unsafe);
        }
    }

    /**
     * Accepts "5" for an integer key, as Eloquent does, so the key is the same
     * whether the ID comes from a route or from code.
     */
    private function normalizeId(int|string $id): int|string
    {
        return match ($this->definition->params['id']) {
            ParameterType::Int => is_string($id) && ($int = filter_var($id, FILTER_VALIDATE_INT)) !== false ? $int : $id,
            default => (string) $id,
        };
    }

    private function makeKey(Model $model, int|string $id): string
    {
        $context = $this->context + [
            'connection' => $this->connectionName($model),
            'database' => (string) $model->getConnection()->getDatabaseName(),
        ];

        if ($this->withTrashed) {
            $context['trashed'] = 'with';
        }

        return $this->keys->make($this->definition, ['id' => $id], $context);
    }

    private function connectionName(Model $model): string
    {
        return (string) ($model->getConnectionName() ?? $model->getConnection()->getName());
    }

    /**
     * @return Builder<TModel>
     */
    private function query(Model $model): Builder
    {
        /** @var Builder<TModel> $query */
        $query = $model->newQuery();

        return $this->withTrashed ? $query->withoutGlobalScope(SoftDeletingScope::class) : $query;
    }

    /**
     * @return array{0: mixed}|false|null the envelope on a hit, null on a miss,
     *                                    false when Redis could not be reached
     */
    private function read(string $key): array|false|null
    {
        try {
            $entry = $this->repository()->get($key);

            return Envelope::isHit($entry) ? $entry : null;
        } catch (Throwable $e) {
            $this->errors->handle($e, $this->errors->read);

            return false;
        }
    }

    private function write(string $key, mixed $value): void
    {
        try {
            $this->repository()->put($key, Envelope::wrap($value), $this->definition->ttl);
        } catch (Throwable $e) {
            $this->errors->handle($e, $this->errors->write);
        }
    }

    private function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
