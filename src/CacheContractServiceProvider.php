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
use Uslanozan\CacheContract\Keys\DefaultKeyFactory;
use Uslanozan\CacheContract\Keys\KeyFormat;
use Uslanozan\CacheContract\Keys\ParameterEncoder;
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

        $this->app->singleton(KeyFactory::class, fn (Application $app): KeyFactory => new DefaultKeyFactory(
            $app->make(KeyFormat::class),
            new ParameterEncoder,
        ));

        // Stateless and deterministic: safe to share.
        $this->app->singleton(SimilarityEngine::class, DefaultSimilarityEngine::class);
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
}
