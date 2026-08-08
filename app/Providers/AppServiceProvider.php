<?php

namespace App\Providers;

use App\Models\CustomerInstallation;
use App\Models\PersonalAccessToken;
use App\Policies\CustomerInstallationPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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

        // Los tokens de Sanctum llevan metadatos propios de las llaves de la
        // API pública (allowlist de IPs, revocación). El guard debe devolver
        // esa subclase o el middleware no vería esas columnas casteadas.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

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
            ->by(self::throttleKey($request)));

        // Operaciones que hablan con el CORE por SSH: caras y serializadas.
        RateLimiter::for('router-ops', fn (Request $request) => Limit::perMinute(10)
            ->by(self::throttleKey($request)));

        // Operaciones masivas (importaciones, aprovisionamiento en bloque,
        // disparadores del ciclo de facturación).
        RateLimiter::for('bulk-ops', fn (Request $request) => Limit::perMinute(5)
            ->by(self::throttleKey($request)));

        // API pública de solo lectura. Cubo PROPIO por llave (token, no cliente:
        // dos llaves del mismo cliente no se estorban) para que el consumo del
        // integrador no pueda comerse la capacidad del panel — que es lo que usa
        // el personal del ISP para cobrar y reconectar.
        //
        // Dos límites simultáneos: 60/min corta las ráfagas y 5.000/hora corta
        // el barrido sostenido de toda la base de clientes, que es la forma
        // realista de exfiltrarla con una llave legítima.
        RateLimiter::for('api-key', fn (Request $request) => [
            Limit::perMinute(60)->by(self::apiKeyThrottleKey($request)),
            Limit::perHour(5000)->by(self::apiKeyThrottleKey($request)),
        ]);
    }

    /**
     * Clave de cubo namespaced por clase.
     *
     * Sin el prefijo, el usuario 7 y el ApiClient 7 compartirían cubo: ambos
     * son enteros y `->by(7)` es la misma cadena. El integrador agotaría el
     * límite de un empleado del ISP, o al revés.
     */
    protected static function throttleKey(Request $request): string
    {
        $user = $request->user();

        return $user
            ? class_basename($user) . ':' . $user->getKey()
            : 'ip:' . $request->ip();
    }

    /** Cubo por token; si aún no hay token resuelto, por IP de origen. */
    protected static function apiKeyThrottleKey(Request $request): string
    {
        $token = $request->user()?->currentAccessToken();

        return $token && $token->getKey()
            ? 'token:' . $token->getKey()
            : 'ip:' . $request->ip();
    }
}
