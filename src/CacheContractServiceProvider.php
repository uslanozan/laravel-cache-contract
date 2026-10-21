<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Uslanozan\CacheContract\Commands\InspectCommand;
use Uslanozan\CacheContract\Commands\ListCommand;
use Uslanozan\CacheContract\Contracts\DefinitionRegistry;
use Uslanozan\CacheContract\Contracts\KeyFactory;
use Uslanozan\CacheContract\Contracts\SimilarityEngine;
use Uslanozan\CacheContract\Definitions\ConfigDefinitionRegistry;
use Uslanozan\CacheContract\Eloquent\ModelCacheConfig;
use Uslanozan\CacheContract\Eloquent\ModelCacheFactory;
use Uslanozan\CacheContract\Keys\DefaultKeyFactory;
use Uslanozan\CacheContract\Keys\KeyFormat;
use Uslanozan\CacheContract\Keys\ParameterEncoder;
use Uslanozan\CacheContract\Runtime\CacheContractManager;
use Uslanozan\CacheContract\Runtime\RedisErrorPolicy;
use Uslanozan\CacheContract\Similarity\DefaultSimilarityEngine;

/**
 * Entry point Laravel loads for this package (auto-discovered through
 * "extra.laravel.providers" in composer.json).
 *
 * register() binds things into the container; boot() runs after every
 * provider is registered. Anything that holds request state (tenant, user,
 * locale) must not be bound as a singleton here: see AGENTS.md.
 */
class CacheContractServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Values from the application's config/cache-contract.php win; keys
        // the application does not define fall back to the package defaults.
        $this->mergeConfigFrom(__DIR__.'/../config/cache-contract.php', 'cache-contract');

        // Definitions are the same for every request: safe to share.
        $this->app->singleton(DefinitionRegistry::class, ConfigDefinitionRegistry::class);

        // The key format is read from config once; an invalid format fails on
        // first use with InvalidKeyFormat.
        $this->app->singleton(KeyFormat::class, function (Application $app): KeyFormat {
            /** @var Repository $config */
            $config = $app->make('config');

            return KeyFormat::fromString(
                (string) $config->get('cache-contract.key_format'),
                (string) $config->get('cache-contract.prefix', ''),
            );
        });

        // APP_KEY is only used to hash secret parameters (ADR 0004).
        $this->app->singleton(KeyFactory::class, function (Application $app): KeyFactory {
            $appKey = $this->config($app)->get('app.key');

            return new DefaultKeyFactory(
                $app->make(KeyFormat::class),
                new ParameterEncoder(is_string($appKey) ? $appKey : null),
            );
        });

        // Stateless and deterministic: safe to share.
        $this->app->singleton(SimilarityEngine::class, DefaultSimilarityEngine::class);

        // One policy for every cache operation of the package (ADR 0007), so the
        // facade and the model cache follow the same "redis_errors" setting.
        $this->app->singleton(RedisErrorPolicy::class, fn (Application $app): RedisErrorPolicy => RedisErrorPolicy::fromConfig(
            (array) $this->config($app)->get('cache-contract.redis_errors', []),
        ));

        // Immutable and without context: withContext() returns a copy, so the
        // shared instance never carries a tenant into the next request.
        $this->app->singleton(CacheContractManager::class, fn (Application $app): CacheContractManager => new CacheContractManager(
            $app->make('cache'),
            $app->make(DefinitionRegistry::class),
            $app->make(KeyFactory::class),
            $app->make(RedisErrorPolicy::class),
            $this->store($app),
        ));

        // The "models" section is read once; an invalid value fails on first use
        // with InvalidConfiguration.
        $this->app->singleton(ModelCacheConfig::class, fn (Application $app): ModelCacheConfig => ModelCacheConfig::fromConfig(
            (array) $this->config($app)->get('cache-contract.models', []),
        ));

        // Holds no request state: the ModelCache it builds is immutable too.
        $this->app->singleton(ModelCacheFactory::class, fn (Application $app): ModelCacheFactory => new ModelCacheFactory(
            $app->make('cache'),
            $app->make(KeyFactory::class),
            $app->make(RedisErrorPolicy::class),
            $app->make(ModelCacheConfig::class),
            $this->store($app),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // php artisan vendor:publish --tag=cache-contract-config
            $this->publishes([
                __DIR__.'/../config/cache-contract.php' => config_path('cache-contract.php'),
            ], 'cache-contract-config');

            $this->commands([
                ListCommand::class,
                InspectCommand::class,
            ]);
        }
    }

    private function config(Application $app): Repository
    {
        /** @var Repository $config */
        $config = $app->make('config');

        return $config;
    }

    /** The cache store for package entries; null means the default store. */
    private function store(Application $app): ?string
    {
        $store = $this->config($app)->get('cache-contract.store');

        return is_string($store) && $store !== '' ? $store : null;
    }
}
