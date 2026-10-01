<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Evento de cambio comercial publicado hacia integradores externos.
 *
 * Append-only: se inserta y nunca se reescribe, salvo UNA vez la columna `seq`,
 * que asigna PartnerEventSequencer al publicarlo.
 *
 * `id` Y `seq` SON COSAS DISTINTAS
 * ---------------------------------
 * `id` se toma al INSERTAR, dentro de la transacción del cambio que lo
 * origina; `seq` se asigna DESPUÉS del commit, en serie. El feed y la
 * revisión usan `seq`, nunca `id`: dos transacciones pueden confirmar en orden
 * inverso a sus ids, y un cursor sobre `id` se saltaría para siempre la que
 * confirmó tarde. Ver PartnerEventSequencer y la migración 2026_09_30_120000.
 *
 * Hacia afuera `seq` se llama `event_id` y `revision`; `id` no sale nunca.
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
        'seq'         => 'integer',
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
    public const CUSTOMER_DELETED    = 'CUSTOMER_DELETED';
    public const ROUTER_CHANGED      = 'ROUTER_CHANGED';
    public const NETWORK_CHANGED     = 'NETWORK_CHANGED';

    public const TYPES = [
        self::SERVICE_CREATED,
        self::SERVICE_ACTIVATED,
        self::SERVICE_SUSPENDED,
        self::SERVICE_REACTIVATED,
        self::PLAN_CHANGED,
        self::SERVICE_CANCELLED,
        self::CUSTOMER_UPDATED,
        self::CUSTOMER_DELETED,
        self::ROUTER_CHANGED,
        self::NETWORK_CHANGED,
    ];

    /** Solo lo ya publicado: lo que todavía no tiene `seq` no existe para el feed. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('seq');
    }

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

    /**
     * Registra muchos eventos en inserciones por bloque.
     *
     * Para los escritores masivos que no pasan por Eloquent (carga de clientes,
     * cambio de RADIUS en un router): un `insert()` masivo no dispara el
     * observer, así que el evento hay que emitirlo a mano — y por fila sería
     * un round-trip por cliente, que es lo que tumbaba la importación con 504.
     *
     * Mismo blindaje que record(): un fallo aquí no tumba la operación.
     *
     * @param array<int,array{tenant_id:int,event_type:string,customer_id:int,service_id?:?int,changes?:?array}> $rows
     */
    public static function recordMany(array $rows, int $chunk = 500): void
    {
        if (!$rows) {
            return;
        }

        $now = now();

        $rows = array_map(static fn (array $row) => [
            'tenant_id'   => $row['tenant_id'],
            'event_type'  => $row['event_type'],
            'customer_id' => $row['customer_id'],
            'service_id'  => $row['service_id'] ?? null,
            // insert() no aplica los casts del modelo.
            'changes'     => isset($row['changes']) ? json_encode($row['changes']) : null,
            'occurred_at' => $row['occurred_at'] ?? $now,
        ], $rows);

        try {
            static::query()->getConnection()->transaction(static function () use ($rows, $chunk) {
                foreach (array_chunk($rows, $chunk) as $batch) {
                    static::query()->insert($batch);
                }
            });
        } catch (\Throwable $e) {
            Log::error('PartnerEvent: no se pudo registrar un lote de cambios comerciales.', [
                'event_type' => $rows[0]['event_type'] ?? null,
                'count'      => count($rows),
                'exception'  => $e->getMessage(),
            ]);
        }
    }
}
