<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract;

use Illuminate\Support\ServiceProvider;
use Uslanozan\CacheContract\Contracts\DefinitionRegistry;
use Uslanozan\CacheContract\Definitions\ConfigDefinitionRegistry;

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
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // php artisan vendor:publish --tag=cache-contract-config
            $this->publishes([
                __DIR__.'/../config/cache-contract.php' => config_path('cache-contract.php'),
            ], 'cache-contract-config');
        }
    }
}
