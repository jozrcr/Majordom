<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Runtime\Metallama\MetallamaClient;
use App\Runtime\Metallama\ResourceCoordinator;
use App\Projects\Memory\MemoryStore;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MetallamaClient::class, fn () => MetallamaClient::fromConfig());
        $this->app->singleton(ResourceCoordinator::class, fn ($app) => new ResourceCoordinator($app->make(MetallamaClient::class)));
        
        $this->app->bind(\App\Agents\Harness\Harness::class, fn () => \App\Agents\Harness\AiderHarness::fromConfig());
        $this->app->bind(\App\Agents\Providers\Provider::class, fn () => \App\Agents\Providers\OpenAiCompatibleProvider::openrouter());
        
        $this->app->singleton(MemoryStore::class, fn () => MemoryStore::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
