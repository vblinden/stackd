<?php

namespace App\Providers;

use App\Services\MailpitService;
use App\Services\MySqlService;
use App\Services\ServiceRegistry;
use App\Services\ValkeyService;
use App\Support\BinaryDownloader;
use App\Support\InstanceManager;
use App\Support\InstanceRepository;
use App\Support\LaunchAgentManager;
use App\Support\ProcessManager;
use App\Support\ServiceOpener;
use App\Support\StackdPaths;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/stackd.php', 'stackd');

        $this->app->singleton(StackdPaths::class, fn () => StackdPaths::make());
        $this->app->singleton(ProcessManager::class);
        $this->app->singleton(BinaryDownloader::class);
        $this->app->singleton(InstanceRepository::class);
        $this->app->singleton(InstanceManager::class);
        $this->app->singleton(LaunchAgentManager::class);
        $this->app->singleton(ServiceOpener::class);

        $this->app->singleton(ServiceRegistry::class, function ($app) {
            $registry = new ServiceRegistry;

            foreach ([
                MailpitService::class,
                ValkeyService::class,
                MySqlService::class,
            ] as $serviceClass) {
                $registry->register($app->make($serviceClass));
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        //
    }
}
