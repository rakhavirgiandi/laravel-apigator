<?php

namespace Virgiandi\Apigator;

use Illuminate\Support\ServiceProvider;
use Virgiandi\Apigator\Commands\GenerateApiCommand;

class ApigatorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateApiCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/apigator.php' => config_path('apigator.php'),
            ], 'apigator-config');
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/apigator.php',
            'apigator'
        );
    }
}
