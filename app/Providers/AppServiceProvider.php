<?php

namespace App\Providers;

use App\Models\CustomerInstallation;
use App\Policies\CustomerInstallationPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->configureRateLimiting();
    }

    /**
     * Límites de peticiones de la API.
     *
     * Hasta 2026-07-30 la API no tenía ninguno: el único límite del sistema era
     * el RateLimiter manual dentro de AuthController::login. Eso dejaba abierta
     * la enumeración de clientes y, sobre todo, el abuso de los endpoints de
     * aprovisionamiento: cada llamada abre una sesión SSH al CORE y tarda 17-34 s,
     * así que un puñado de peticiones concurrentes agota el pool de conexiones
     * del CORE y tumba el aprovisionamiento y el corte para todos los tenants.
     */
    protected function configureRateLimiting(): void
    {
        // Límite general de la API. Por usuario autenticado; por IP si no lo hay.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Operaciones que hablan con el CORE por SSH: caras y serializadas.
        RateLimiter::for('router-ops', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));

        // Operaciones masivas (importaciones, aprovisionamiento en bloque,
        // disparadores del ciclo de facturación).
        RateLimiter::for('bulk-ops', fn (Request $request) => Limit::perMinute(5)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
