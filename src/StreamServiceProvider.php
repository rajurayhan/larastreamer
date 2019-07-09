<?php

namespace Raju\Streamer;

use Illuminate\Support\ServiceProvider;

class StreamServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->make('Raju\Streamer\Controllers\StreamController');
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->publishes([
                __DIR__.'/../config/streamer.php' => config_path('larastreamer.php')
            ], 'larastreamer');
    }
}
