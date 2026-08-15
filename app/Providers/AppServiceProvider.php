<?php

namespace App\Providers;

use App\Models\Billing;
use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PersonalAccessToken;
use App\Models\Plan;
use App\Models\UserService;
use App\Observers\MoneyAuditObserver;
use App\Observers\PartnerEventObserver;
use App\Policies\CustomerInstallationPolicy;
use App\Support\TicketCatalogs;
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
        // Catálogos del ticket: una carga por petición, resolución en memoria.
        // Singleton del contenedor y no una estática del modelo, para que cada
        // test arranque con la caché limpia — una estática sobreviviría a
        // RefreshDatabase y resolvería ids de una base que ya no existe.
        $this->app->singleton(TicketCatalogs::class);
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

        $this->registerMoneyAudit();
        $this->registerPartnerEvents();
        $this->configureRateLimiting();
    }

    /**
     * Feed de cambios comerciales para integradores externos.
     *
     * Mismo razonamiento que la bitácora de dinero: los cambios entran por
     * panel, API, carga masiva y consola, y solo un observer los cubre todos.
     * Un cliente suspendido por el proceso automático de mora tiene que
     * producir el mismo evento que uno suspendido a mano, o el integrador
     * sincroniza una realidad a medias. Ver PartnerEventObserver.
     */
    protected function registerPartnerEvents(): void
    {
        foreach ([CustomerProfile::class, UserService::class] as $model) {
            $model::observe(PartnerEventObserver::class);
        }
    }

    /**
     * Bitácora de todo lo que mueve plata.
     *
     * Se engancha por observer y no dentro de los controladores porque los
     * cambios entran por cuatro puertas —panel, API, carga masiva y consola— y
     * solo el observer las cubre todas. Ver MoneyAuditObserver.
     */
    protected function registerMoneyAudit(): void
    {
        foreach ([Plan::class, CustomerProfile::class, Payment::class, Invoice::class, Billing::class] as $model) {
            $model::observe(MoneyAuditObserver::class);
        }
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

        // Firma remota del contrato: rutas SIN autenticación, así que el cubo
        // sólo puede ir por IP. El techo por token (5 intentos de verificación,
        // en ContractSignatureLink) protege un link concreto; esto protege al
        // servidor de que alguien barra tokens al azar desde una misma IP.
        // 20/min deja margen de sobra al cliente real —abrir, verificar,
        // firmar son 3 peticiones— y no a un barrido.
        RateLimiter::for('public-contract', fn (Request $request) => [
            Limit::perMinute(20)->by('public-contract|' . $request->ip()),
            Limit::perHour(120)->by('public-contract|' . $request->ip()),
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
