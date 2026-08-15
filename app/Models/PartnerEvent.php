<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Evento de cambio comercial publicado hacia integradores externos.
 *
 * Append-only: se inserta y nunca se actualiza ni se borra (salvo la poda por
 * antigüedad). El `id` es a la vez el cursor del feed y la revisión del
 * recurso — ver la migración 2026_08_14_110000 para el porqué.
 *
 * No usa BelongsToTenant: el tenant lo fija SIEMPRE quien registra el evento,
 * a partir del modelo que cambió. El scope global depende de auth()->user(),
 * y estos eventos se emiten también desde consola, colas y cargas masivas,
 * donde no hay usuario. Los lectores (la API pública) filtran por el tenant de
 * la llave de forma explícita.
 */
class PartnerEvent extends Model
{
    protected $table = 'partner_events';

    /** El log no se actualiza: solo `occurred_at`, fijado al insertar. */
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'event_type',
        'customer_id',
        'service_id',
        'changes',
        'occurred_at',
    ];

    protected $casts = [
        'changes'     => 'array',
        'occurred_at' => 'datetime',
    ];

    /**
     * Tipos de evento. Son contrato público: renombrar uno rompe a todo
     * integrador que filtre por él, así que se agregan valores nuevos en vez
     * de cambiar los existentes.
     */
    public const SERVICE_CREATED     = 'SERVICE_CREATED';
    public const SERVICE_ACTIVATED   = 'SERVICE_ACTIVATED';
    public const SERVICE_SUSPENDED   = 'SERVICE_SUSPENDED';
    public const SERVICE_REACTIVATED = 'SERVICE_REACTIVATED';
    public const PLAN_CHANGED        = 'PLAN_CHANGED';
    public const SERVICE_CANCELLED   = 'SERVICE_CANCELLED';
    public const CUSTOMER_UPDATED    = 'CUSTOMER_UPDATED';

    public const TYPES = [
        self::SERVICE_CREATED,
        self::SERVICE_ACTIVATED,
        self::SERVICE_SUSPENDED,
        self::SERVICE_REACTIVATED,
        self::PLAN_CHANGED,
        self::SERVICE_CANCELLED,
        self::CUSTOMER_UPDATED,
    ];

    /**
     * Registra un evento sin poder tumbar la operación que lo origina.
     *
     * Mismo blindaje que MoneyAuditObserver::write(), y por la misma razón
     * aprendida: en PostgreSQL una excepción dentro de una transacción la deja
     * ABORTADA, y a partir de ahí toda consulta posterior revienta con
     * «current transaction is aborted». O sea que sin protección este log
     * podría tumbar en cadena un corte masivo o una facturación entera.
     *
     * Atrapar la excepción no basta: la transacción queda abortada igual,
     * porque solo un ROLLBACK la recupera. Por eso la escritura va dentro de
     * `transaction()`, que emite un SAVEPOINT cuando ya hay transacción abierta
     * y hace ROLLBACK TO SAVEPOINT si falla — el daño queda acotado al log y la
     * transacción de negocio sigue utilizable.
     *
     * SQLite no tiene ese estado, así que un fallo así pasaría inadvertido en
     * los tests. De ahí que esto no sea negociable aunque en local "funcione".
     */
    public static function record(array $attributes): void
    {
        $attributes['occurred_at'] ??= now();

        try {
            static::query()->getConnection()->transaction(
                static fn () => static::query()->create($attributes)
            );
        } catch (\Throwable $e) {
            Log::error('PartnerEvent: no se pudo registrar el cambio comercial.', [
                'event_type'  => $attributes['event_type'] ?? null,
                'customer_id' => $attributes['customer_id'] ?? null,
                'exception'   => $e->getMessage(),
            ]);
        }
    }
}
