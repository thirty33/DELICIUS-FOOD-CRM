<?php

namespace App\Providers;

use App\Services\ImageSignerService;
use Illuminate\Support\ServiceProvider;

class ImageSignerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('image-signer', function ($app) {
            return new ImageSignerService();
        });

        $this->app->alias('image-signer', \App\Facades\ImageSigner::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}