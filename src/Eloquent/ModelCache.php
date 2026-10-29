<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Eloquent;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Throwable;
use Uslanozan\CacheContract\Contracts\KeyFactory;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Eloquent\Concerns\HasCache;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Exceptions\ModelNotCachable;
use Uslanozan\CacheContract\Invalidation\AfterCommit;
use Uslanozan\CacheContract\Runtime\Envelope;
use Uslanozan\CacheContract\Runtime\GenerationCounters;
use Uslanozan\CacheContract\Runtime\RedisErrorMode;
use Uslanozan\CacheContract\Runtime\RedisErrorPolicy;

/**
 * Caches one model class by primary key, without a definition in the config
 * (a convention key, ADR 0006):
 *
 *     ModelCache::for(User::class)->find(5);
 *     ModelCache::for(User::class)->findMany([3, 1, 2]);
 *     ModelCache::for(User::class)->withContext(['tenant_id' => 7])->find(5);
 *
 * The key is "{prefix}:model:{table}:v{version}:id={id}" plus the connection
 * name, the database name and any context, e.g.
 * "cc:model:users:v1:id=5:@connection=mysql:@database=app:~g8123". The
 * database is part of the key because multi-database tenancy keeps the
 * connection name and switches its database per tenant. The last segment is
 * the model's generation counter "model:{connection}:{database}:{table}"
 * (ADR 0009): flush() gives it a new value, so every entry of the model is
 * invalidated at once.
 * An entry holds the row's raw attributes in the ADR 0007 envelope, and a
 * read rebuilds the model with newFromBuilder() (ADR 0008). An ID with no row
 * is cached too, as null. While a transaction is open on the model's
 * connection, entries are read but nothing is written: the rows it sees may
 * not be committed, and a rollback would leave them in the cache.
 *
 * Immutable: withContext() and withTrashed() return a copy, so a tenant set
 * for one call never reaches the next request (Octane, queue workers).
 *
 * @template TModel of Model
 */
final readonly class ModelCache
{
    /**
     * "0", or an optional minus and digits without a leading zero; FILTER_VALIDATE_INT
     * checks the range. \z, not $: $ also matches before a trailing newline ("5\n").
     */
    private const CANONICAL_INT = '/^(?:0|-?[1-9][0-9]*)\z/';

    /** Context names the model cache sets itself. */
    private const RESERVED_CONTEXT = ['connection', 'database', 'trashed'];

    /**
     * @param  class-string<TModel>  $class
     * @param  list<string>  $safeScopes  global scopes whose result does not depend on the request, user or tenant
     * @param  positive-int  $chunkSize  keys per MGET and IDs per query in findMany()
     * @param  array<string, scalar>  $context
     */
    public function __construct(
        private string $class,
        private Definition $definition,
        private CacheFactory $cache,
        private KeyFactory $keys,
        private RedisErrorPolicy $errors,
        private GenerationCounters $counters,
        private array $safeScopes = [],
        private ?string $store = null,
        private int $chunkSize = ModelCacheConfig::DEFAULT_CHUNK_SIZE,
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
            $this->class, $this->definition, $this->cache, $this->keys, $this->errors, $this->counters,
            $this->safeScopes, $this->store, $this->chunkSize, array_merge($this->context, $context), $this->withTrashed,
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
            $this->class, $this->definition, $this->cache, $this->keys, $this->errors, $this->counters,
            $this->safeScopes, $this->store, $this->chunkSize, $this->context, true,
        );
    }

    /**
     * The physical key of this ID, for debugging. Reads the model's
     * generation counter, so it needs Redis.
     *
     * @throws InvalidParameters when the ID does not match the model's key type
     */
    public function key(int|string $id): string
    {
        $model = new $this->class;
        $counter = $this->counterName($model);

        return $this->makeKey($model, $this->normalizeId($id), $this->counters->currentOrFail([$counter])[$counter]);
    }

    /**
     * Whether this model's global scopes need a context: any scope other than
     * SoftDeletes and the ones listed in its "safe_scopes".
     */
    public function needsContext(): bool
    {
        return $this->unsafeScopes(new $this->class) !== [];
    }

    /**
     * Removes the entries of this ID for this instance's context: the normal
     * one and, for a soft-deletable model, the withTrashed() one. A failure
     * follows the "invalidate" policy (ADR 0007).
     *
     * @return bool false when Redis could not be reached
     *
     * @throws InvalidParameters when the ID does not match the model's key type
     */
    public function forget(int|string $id): bool
    {
        $model = new $this->class;
        $id = $this->normalizeId($id);
        $this->makeKey($model, $id, 0); // checks the ID before any I/O

        $generation = $this->generation($model, $this->errors->invalidate);

        if ($generation === false) {
            return false;
        }

        $variants = [false];
        if (in_array(SoftDeletes::class, class_uses_recursive($this->class), true)) {
            $variants[] = true;
        }

        try {
            foreach ($variants as $trashed) {
                $this->repository()->forget($this->makeKey($model, $id, $generation, $trashed));
            }
        } catch (Throwable $e) {
            $this->errors->handle($e, $this->errors->invalidate);

            return false;
        }

        return true;
    }

    /**
     * Invalidates every entry of this model, for every ID and context, by
     * giving its generation counter a new value (ADR 0009). Old entries are no
     * longer read and expire through their TTL.
     *
     * @return bool false when Redis could not be reached
     */
    public function flush(): bool
    {
        return $this->counters->bump([$this->counterName(new $this->class)]);
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
        $this->makeKey($model, $id, 0); // checks the ID before any I/O

        $generation = $this->generation($model, $this->errors->read);

        // Redis is down: answer from the database and write nothing (ADR 0007).
        if ($generation === false) {
            /** @var TModel|null */
            return $this->query($model)->whereKey($id)->first();
        }

        $key = $this->makeKey($model, $id, $generation);
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
        if ($entry !== false && ! AfterCommit::isOpen($model->getConnection())) {
            $this->write($key, $found?->getAttributes());
        }

        return $found;
    }

    /**
     * The models with these primary keys, in the order asked for: cached ones
     * from one MGET, the rest from one query, then written back in one go.
     * Large lists are split into chunks of "models.chunk_size" (1,000 by
     * default) for both the MGET and the query.
     *
     * Behaves like Eloquent's findMany(): an ID with no row is left out of
     * the result (and cached as null), a repeated ID is returned once, and
     * "5" is the same ID as 5. The result is an Eloquent collection, so
     * relations can be loaded for all models at once: ->load('company').
     *
     * Every ID is checked before any I/O: one invalid ID throws and nothing
     * is read or written.
     *
     * @param  array<array-key, int|string>  $ids
     * @return Collection<int, TModel>
     *
     * @throws ModelNotCachable when the model has global scopes and no context was given
     * @throws InvalidParameters when an ID does not match the model's key type
     */
    public function findMany(array $ids): Collection
    {
        $model = new $this->class;
        $this->guardGlobalScopes($model);

        // Every ID is checked before any I/O.
        /** @var array<array-key, int|string> $unique ID => ID, first occurrence first */
        $unique = [];
        foreach ($ids as $id) {
            $id = $this->normalizeId($id);
            if (! array_key_exists($id, $unique)) {
                $this->makeKey($model, $id, 0);
                $unique[$id] = $id;
            }
        }

        if ($unique === []) {
            return $model->newCollection();
        }

        $connection = $this->connectionName($model);
        $generation = $this->generation($model, $this->errors->read);
        $readFailed = $generation === false;

        /** @var array<array-key, array{id: int|string, key: string}> $requested ID => its key */
        $requested = array_map(
            fn (int|string $id): array => ['id' => $id, 'key' => $readFailed ? '' : $this->makeKey($model, $id, (int) $generation)],
            $unique,
        );

        /** @var array<array-key, TModel|null> $found ID => model, or null when the ID has no row */
        $found = [];

        foreach ($readFailed ? [] : array_chunk($requested, $this->chunkSize, true) as $chunk) {
            $entries = $this->readMany(array_column($chunk, 'key'));

            // Redis is down: load everything that is left from the database and write nothing.
            if ($entries === false) {
                $readFailed = true;

                break;
            }

            foreach ($chunk as $id => ['key' => $key]) {
                $entry = $entries[$key] ?? null;

                if (! Envelope::isHit($entry)) {
                    continue;
                }

                $attributes = Envelope::unwrap($entry);

                if ($attributes === null) {
                    $found[$id] = null;
                } elseif (is_array($attributes)) {
                    $found[$id] = $model->newFromBuilder($attributes, $connection);
                }
            }
        }

        $missing = array_diff_key($requested, $found);

        if ($missing !== []) {
            $loaded = [];
            foreach (array_chunk(array_column($missing, 'id'), $this->chunkSize) as $chunk) {
                /** @var TModel $row */
                foreach ($this->query($model)->whereKey($chunk)->get() as $row) {
                    $loaded[$this->normalizeId($row->getKey())] = $row;
                }
            }

            $writes = [];
            foreach ($missing as $id => ['key' => $key]) {
                $found[$id] = $loaded[$id] ?? null;
                $writes[$key] = Envelope::wrap($found[$id]?->getAttributes());
            }

            if (! $readFailed && ! AfterCommit::isOpen($model->getConnection())) {
                $this->writeMany($writes);
            }
        }

        return $model->newCollection(array_values(array_filter(
            array_map(fn (array $entry) => $found[$entry['id']] ?? null, $requested),
        )));
    }

    /**
     * Global scopes can make the same ID return a different row, for example
     * per tenant. Without the context they depend on in the key, one tenant's
     * cached model would be served to another.
     */
    private function guardGlobalScopes(Model $model): void
    {
        $unsafe = $this->unsafeScopes($model);

        if ($unsafe === []) {
            return;
        }

        if ($this->context === []) {
            throw ModelNotCachable::scopesNeedContext($this->class, $unsafe);
        }

        // With HasCache, changes clear the entries of the context the model
        // gives (ProvidesCacheContext). Fail here, on the first read, rather
        // than when a change cannot clear them.
        if (! $model instanceof ProvidesCacheContext && in_array(HasCache::class, class_uses_recursive($this->class), true)) {
            throw ModelNotCachable::missingCacheContext($this->class);
        }
    }

    /**
     * @return list<string>
     */
    private function unsafeScopes(Model $model): array
    {
        return array_values(array_diff(
            array_map(strval(...), array_keys($model->getGlobalScopes())),
            [SoftDeletingScope::class],
            $this->safeScopes,
        ));
    }

    /**
     * The model's generation counter, created if missing; false when Redis
     * could not be reached (handled with $mode, ADR 0007).
     */
    private function generation(Model $model, RedisErrorMode $mode): int|false
    {
        $counter = $this->counterName($model);
        $values = $this->counters->current([$counter], $mode);

        return $values === false ? false : $values[$counter];
    }

    /** "model:{connection}:{database}:{table}", so two databases never share a counter. */
    private function counterName(Model $model): string
    {
        return sprintf(
            'model:%s:%s:%s',
            $this->connectionName($model),
            (string) $model->getConnection()->getDatabaseName(),
            $model->getTable(),
        );
    }

    /**
     * Accepts "5" for an integer key, as Eloquent does, so the key is the same
     * whether the ID comes from a route or from code. Only a canonical integer
     * string is converted (ADR 0004): "05", " 5", "5\n", "+5", "-0" and "5abc" stay
     * strings and the key factory rejects them.
     */
    private function normalizeId(int|string $id): int|string
    {
        return match ($this->definition->params['id']) {
            ParameterType::Int => is_string($id) && preg_match(self::CANONICAL_INT, $id) === 1
                && ($int = filter_var($id, FILTER_VALIDATE_INT)) !== false ? $int : $id,
            default => (string) $id,
        };
    }

    private function makeKey(Model $model, int|string $id, int $generation, ?bool $trashed = null): string
    {
        $context = $this->context + [
            'connection' => $this->connectionName($model),
            'database' => (string) $model->getConnection()->getDatabaseName(),
        ];

        if ($trashed ?? $this->withTrashed) {
            $context['trashed'] = 'with';
        }

        $counter = $this->counterName($model);

        return $this->keys->make($this->definitionWith($counter), ['id' => $id], $context, [$counter => $generation]);
    }

    /**
     * The convention definition with the model's counter as its tag, so the
     * key factory accepts exactly that generation. The counter depends on the
     * connection's current database, so it is set per call.
     */
    private function definitionWith(string $counter): Definition
    {
        $d = $this->definition;

        return new Definition(
            section: $d->section,
            name: $d->name,
            purpose: $d->purpose,
            type: $d->type,
            ttl: $d->ttl,
            description: $d->description,
            subject: $d->subject,
            params: $d->params,
            version: $d->version,
            tags: [$counter],
        );
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

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>|false the entries by key, or false when Redis could not be reached
     */
    private function readMany(array $keys): array|false
    {
        try {
            /** @var array<string, mixed> */
            return $this->repository()->many($keys);
        } catch (Throwable $e) {
            $this->errors->handle($e, $this->errors->read);

            return false;
        }
    }

    /**
     * Writes in chunks; stops at the first failure, since the next chunk would
     * wait for the same unreachable Redis.
     *
     * @param  array<string, mixed>  $values
     */
    private function writeMany(array $values): void
    {
        foreach (array_chunk($values, $this->chunkSize, true) as $chunk) {
            try {
                $this->repository()->putMany($chunk, $this->definition->ttl);
            } catch (Throwable $e) {
                $this->errors->handle($e, $this->errors->write);

                return;
            }
        }
    }

    private function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
