<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register Firestore REST Client
        $this->app->singleton(\App\Services\Firebase\FirestoreRestClient::class, function ($app) {
            return new \App\Services\Firebase\FirestoreRestClient();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
