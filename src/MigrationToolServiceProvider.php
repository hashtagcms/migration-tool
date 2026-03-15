<?php

namespace HashtagCms\MigrationTool;

use Illuminate\Support\ServiceProvider;

class MigrationToolServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/migration-tool.php', 'migration-tool'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'migration-tool');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Command\MigrateSiteCommand::class,
                Console\Command\MigrateTemplatesCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/migration-tool.php' => config_path('migration-tool.php'),
            ], 'migration-tool-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/migration-tool'),
            ], 'migration-tool-views');
        }
    }
}
