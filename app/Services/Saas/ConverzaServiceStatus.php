<?php

namespace App\Services\Saas;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ¿Converza le cortó el servicio a este ISP? (KAN-126)
 *
 * Converza (Core Brain) le cobra el SaaS a cada ISP. Cuando una factura vence
 * sin pagar, corta su propio panel y publica el estado en
 * `GET {converza}/saas/estado/{tenant_id}`: Converza lee la base de ISPWatch en
 * solo lectura, así que no puede marcar nada aquí. ISPWatch pregunta y decide.
 *
 * LO QUE SE CORTA ES EL PANEL, NADA MÁS. Esto sólo lo consulta el middleware
 * EnsureSaasServiceActive, que vive en las rutas del panel. La facturación, los
 * cortes y las reconexiones de los suscriptores del ISP corren en el
 * planificador; la API partner y la firma remota del contrato tienen sus propias
 * rutas. Ninguno pasa por aquí: los clientes finales no se enteran de que su
 * proveedor nos debe.
 *
 * FALLA ABIERTO. Sólo un `"suspended": true` literal corta. Sin configuración,
 * con Converza caído, lento, con un 401/404/429/5xx o con una respuesta que no
 * se entiende, el ISP sigue trabajando: una caída de Converza no puede
 * convertirse en el corte de un ISP que sí pagó.
 */
class ConverzaServiceStatus
{
    /** Casi todos los ISP, casi siempre. Es el «~5 min» del contrato. */
    public const TTL_ACTIVE = 300;

    /**
     * Más corto a propósito: al registrar el pago, Converza levanta el corte en
     * el acto, y el ISP no debería esperar cinco minutos para volver a entrar.
     * Los suspendidos son pocos, así que preguntar más seguido no cuesta.
     */
    public const TTL_SUSPENDED = 60;

    /**
     * Error al preguntar: se deja pasar Y se recuerda un minuto. Sin esto, con
     * Converza caído, CADA petición del panel esperaría el timeout entero.
     */
    public const TTL_UNREACHABLE = 60;

    /** Tope de los textos que llegan de Converza y se pintan en la pantalla. */
    private const MAX_TEXT = 2000;

    public function isConfigured(): bool
    {
        return trim((string) config('services.converza.url')) !== ''
            && trim((string) config('services.converza.saas_status_token')) !== '';
    }

    /**
     * El estado que corresponde a quien hace la petición.
     *
     * El orden importa por rendimiento: corre en cada petición del panel, y la
     * consulta de «¿es un cliente final?» sólo se hace cuando el tenant SÍ está
     * suspendido, que es la excepción.
     *
     * @return array{suspended: bool, reason: ?string, since: ?string, message: ?string, payment_instructions: ?string}
     */
    public function forUser(?Authenticatable $user): array
    {
        if (! $this->isConfigured() || ! $user instanceof User || ! $user->tenant_id) {
            return self::active();
        }

        // El personal del operador de la plataforma no es un ISP que nos deba.
        if ($user->is_superadmin) {
            return self::active();
        }

        $status = $this->forTenant((int) $user->tenant_id);

        if (! $status['suspended'] || $this->isEndCustomer($user)) {
            return self::active();
        }

        return $status;
    }

    /**
     * @return array{suspended: bool, reason: ?string, since: ?string, message: ?string, payment_instructions: ?string}
     */
    public function forTenant(int $tenantId): array
    {
        if (! $this->isConfigured()) {
            return self::active();
        }

        $key = self::cacheKey($tenantId);

        try {
            $cached = $this->cache()->get($key);
        } catch (\Throwable $e) {
            // Sin caché, preguntar en cada petición convertiría una caída de
            // Converza en un panel inservible para todos. Se deja pasar.
            Log::error('Estado SaaS: la caché no responde; se deja pasar', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);

            return self::active();
        }

        if (is_array($cached) && array_key_exists('suspended', $cached)) {
            return $cached;
        }

        [$status, $ttl] = $this->fetch($tenantId);

        try {
            $this->cache()->put($key, $status, $ttl);
        } catch (\Throwable $e) {
            Log::error('Estado SaaS: no se pudo guardar en caché', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);
        }

        return $status;
    }

    /** Olvida lo recordado de un tenant (pruebas y soporte desde tinker). */
    public function forget(int $tenantId): void
    {
        $this->cache()->forget(self::cacheKey($tenantId));
    }

    public static function cacheKey(int $tenantId): string
    {
        return "converza:saas-status:{$tenantId}";
    }

    /**
     * @return array{suspended: false, reason: null, since: null, message: null, payment_instructions: null}
     */
    public static function active(): array
    {
        return [
            'suspended'            => false,
            'reason'               => null,
            'since'                => null,
            'message'              => null,
            'payment_instructions' => null,
        ];
    }

    /**
     * Una sola llamada a Converza, con su tiempo de caché según lo que pasó.
     *
     * @return array{0: array, 1: int}
     */
    private function fetch(int $tenantId): array
    {
        $timeout = max(0.5, (float) config('services.converza.saas_status_timeout', 3));
        $url     = rtrim((string) config('services.converza.url'), '/') . '/saas/estado/' . $tenantId;

        try {
            $response = Http::acceptJson()
                ->withToken((string) config('services.converza.saas_status_token'))
                ->connectTimeout(min(2.0, $timeout))
                ->timeout($timeout)
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('Estado SaaS: Converza no respondió; se deja pasar', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ]);

            return [self::active(), self::TTL_UNREACHABLE];
        }

        // 401 = el token no coincide con el de Converza; 404 = Converza no tiene
        // token configurado. Los dos apagan el corte en silencio, por eso van al
        // registro con el código: es la pista para encontrarlos.
        if (! $response->successful()) {
            Log::warning('Estado SaaS: Converza respondió con error; se deja pasar', [
                'tenant_id' => $tenantId,
                'status'    => $response->status(),
            ]);

            return [self::active(), self::TTL_UNREACHABLE];
        }

        $body = $response->json();

        if (! is_array($body) || ! array_key_exists('suspended', $body)) {
            Log::warning('Estado SaaS: respuesta de Converza sin `suspended`; se deja pasar', [
                'tenant_id' => $tenantId,
            ]);

            return [self::active(), self::TTL_UNREACHABLE];
        }

        // Estricto: un "true" en texto o un 1 no cortan. Ante la duda, pasa.
        if ($body['suspended'] !== true) {
            return [self::active(), self::TTL_ACTIVE];
        }

        $reason = $body['reason'] ?? null;

        return [[
            'suspended'            => true,
            'reason'               => in_array($reason, ['billing', 'manual'], true) ? $reason : null,
            'since'                => self::text($body['since'] ?? null, 64),
            'message'              => self::text($body['message'] ?? null)
                ?? 'El servicio de ISPWatch está suspendido. Comunícate con soporte para reactivarlo.',
            'payment_instructions' => self::text($body['payment_instructions'] ?? null),
        ], self::TTL_SUSPENDED];
    }

    /**
     * Un suscriptor del ISP que entra con su usuario no es «el equipo del ISP».
     * Mostrarle la pantalla sería contarle a un cliente final que su proveedor
     * nos debe. Cubre el rol `client` de los tenants nuevos y el rol global
     * «Cliente» (sin código), que se reconoce por tener ficha de cliente.
     */
    private function isEndCustomer(User $user): bool
    {
        $user->loadMissing(['role' => fn ($q) => $q->withoutGlobalScope('tenant')]);

        return $user->role?->code === 'client' || $user->customerProfile()->exists();
    }

    private function cache(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store(config('services.converza.saas_status_cache_store') ?: null);
    }

    private static function text(mixed $value, int $max = self::MAX_TEXT): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
