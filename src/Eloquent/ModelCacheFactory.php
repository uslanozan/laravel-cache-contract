<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Eloquent;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Contracts\KeyFactory;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Definitions\DefinitionValidator;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Definitions\ResultType;
use Uslanozan\CacheContract\Exceptions\ModelNotCachable;
use Uslanozan\CacheContract\Runtime\RedisErrorPolicy;

/**
 * Builds the ModelCache of a model class: the service behind the ModelCache
 * facade.
 *
 * A model needs no definition in the config: this builds one that never
 * enters the registry (so it is not listed by cache-contract:list), with
 * section "model", the table as its name, an "id" parameter typed after the
 * model's key type, and the TTL and version from the "models" config.
 *
 * Holds no request state, so it is safe as a singleton.
 */
final readonly class ModelCacheFactory
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

    public function __construct(
        private CacheFactory $cache,
        private KeyFactory $keys,
        private RedisErrorPolicy $errors,
        private ModelCacheConfig $config,
        private ?string $store = null,
    ) {}

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return ModelCache<TModel>
     *
     * @throws ModelNotCachable
     */
    public function for(string $class): ModelCache
    {
        if (! is_subclass_of($class, Model::class)) {
            throw ModelNotCachable::notAModel($class);
        }

        $model = new $class;
        $table = $model->getTable();

        if (preg_match(self::NAME_PATTERN, $table) !== 1) {
            throw ModelNotCachable::unsupportedTable($class, $table);
        }

        $keyType = match ($model->getKeyType()) {
            'int', 'integer' => ParameterType::Int,
            'string' => ParameterType::String,
            default => throw ModelNotCachable::unsupportedKeyType($class, $model->getKeyType()),
        };

        $definition = new Definition(
            section: DefinitionValidator::MODEL_SECTION,
            name: $table,
            purpose: 'by_id',
            type: ResultType::Model,
            ttl: $this->config->ttlFor($class),
            description: sprintf('%s by primary key (convention key, ADR 0006).', $class),
            subject: $class,
            params: ['id' => $keyType],
            version: $this->config->versionFor($class),
        );

        return new ModelCache(
            $class,
            $definition,
            $this->cache,
            $this->keys,
            $this->errors,
            $this->config->safeScopesFor($class),
            $this->store,
            $this->config->chunkSize,
        );
    }
}
