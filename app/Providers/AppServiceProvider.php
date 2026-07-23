<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Runtime\Metallama\MetallamaClient;
use App\Runtime\Metallama\ResourceCoordinator;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\WorktreeManager;
use App\Core\Events\EventRecorder;
use App\Core\Usage\UsageLedger;
use App\Support\RoleResolver;
use App\Agents\Providers\ProviderRegistry;

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
        
        $this->app->singleton(MemoryStore::class, fn () => MemoryStore::fromConfig());
        $this->app->singleton(WorktreeManager::class, fn () => WorktreeManager::fromConfig());

        $this->app->singleton(
            \App\Core\Workflow\WorkflowEngine::class,
            fn () => new \App\Core\Workflow\WorkflowEngine(\App\Core\Workflow\ImplementFeatureWorkflow::nodeMap()),
        );

        $this->app->singleton(EventRecorder::class, fn () => new EventRecorder());
        
        $this->app->singleton(UsageLedger::class, fn () => new UsageLedger());

        $this->app->singleton(\App\Integrations\Telegram\TelegramClient::class, fn () => \App\Integrations\Telegram\TelegramClient::fromConfig());

        $this->app->singleton(RoleResolver::class);
        $this->app->singleton(ProviderRegistry::class);

        // M15 Sandbox seam: no real backend yet, so bind the honest default that
        // refuses command execution rather than running unconfined on the host.
        // Swapping in a container/microVM implementation is a one-line rebind.
        $this->app->bind(\App\Sandbox\Sandbox::class, \App\Sandbox\UnavailableSandbox::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
