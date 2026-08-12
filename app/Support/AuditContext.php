<?php

namespace App\Support;

use App\Models\User;

/**
 * Resuelve QUIÉN y DESDE DÓNDE se hizo un cambio, para estampárselo tanto a
 * audit_logs como a customer_credits.
 *
 * El "quién" no siempre es una persona: el scheduler factura, un comando de
 * consola repara datos, una carga masiva reasigna planes. Distinguir esos
 * orígenes es justamente lo que faltaba cuando el precio de un plan pasó de
 * 56.000 a 60.000 y no había forma de saber si lo cambió un operador desde la
 * pantalla o una importación de Excel.
 */
class AuditContext
{
    public const SOURCE_WEB       = 'web';
    public const SOURCE_API       = 'api';
    public const SOURCE_CONSOLE   = 'console';
    public const SOURCE_IMPORT    = 'import';
    public const SOURCE_SCHEDULER = 'scheduler';

    /**
     * Origen forzado por el código que está corriendo. Lo usan las cargas
     * masivas y los comandos para marcar sus escrituras sin tener que pasarle
     * el origen a cada llamada del stack.
     */
    protected static ?string $overrideSource = null;

    /** Ejecuta un bloque marcando todo lo que audite con un origen concreto. */
    public static function as(string $source, callable $callback)
    {
        $previous = static::$overrideSource;
        static::$overrideSource = $source;

        try {
            return $callback();
        } finally {
            static::$overrideSource = $previous;
        }
    }

    /** Usuario autenticado, o null si lo hizo el scheduler / un comando. */
    public static function actorId(): ?int
    {
        return auth()->check() ? auth()->id() : null;
    }

    public static function source(): string
    {
        if (static::$overrideSource !== null) {
            return static::$overrideSource;
        }

        if (app()->runningInConsole()) {
            return static::SOURCE_CONSOLE;
        }

        // Las llamadas de la API pública viajan con token de Sanctum sobre
        // /api/v1; el panel usa el mismo prefijo /api pero con sesión de staff.
        if (request()->is('api/v1/*')) {
            return static::SOURCE_API;
        }

        return static::SOURCE_WEB;
    }

    public static function ip(): ?string
    {
        return app()->runningInConsole() ? null : request()->ip();
    }

    public static function userAgent(): ?string
    {
        return app()->runningInConsole() ? null : request()->userAgent();
    }

    /**
     * Tenant al que pertenece un cliente.
     *
     * customer_profile no tiene tenant_id propio: cuelga del usuario, que no
     * lleva scope global de tenant, así que la consulta sirve igual desde el
     * panel que desde el scheduler (que corre sin sesión).
     */
    public static function tenantIdForCustomer(int $customerId): ?int
    {
        return User::whereKey($customerId)->value('tenant_id');
    }

    /** Tenant del actor actual, para registros que no cuelgan de un cliente. */
    public static function currentTenantId(): ?int
    {
        return auth()->check() ? auth()->user()->tenant_id : null;
    }
}
