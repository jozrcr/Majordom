<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Runtime\Metallama\MetallamaClient;
use App\Runtime\Metallama\ResourceCoordinator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MetallamaClient::class, fn () => MetallamaClient::fromConfig());
        $this->app->singleton(ResourceCoordinator::class, fn ($app) => new ResourceCoordinator($app->make(MetallamaClient::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
