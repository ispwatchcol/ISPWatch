<?php

namespace App\Providers;

use App\Models\CustomerInstallation;
use App\Policies\CustomerInstallationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS in production (required for DigitalOcean App Platform)
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        Gate::policy(CustomerInstallation::class, CustomerInstallationPolicy::class);
    }
}
