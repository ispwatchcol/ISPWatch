<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Un servicio adicional del catálogo asignado a un cliente concreto.
 *
 * Las asignaciones no se borran cuando el cliente deja de tener el servicio: se
 * desactivan (is_active) o se les pone fecha de fin (ends_at). Las facturas ya
 * emitidas apuntan aquí, y el historial de cobro tiene que poder explicarse.
 */
class CustomerAdditionalService extends Model
{
    use BelongsToTenant;

    protected $table = 'customer_additional_services';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'additional_service_id',
        'price',
        'quantity',
        'starts_at',
        'ends_at',
        'is_active',
        'assigned_at',
        'assigned_by',
        'notes',
    ];

    /**
     * Los mismos defaults que la migración, repetidos aquí a propósito.
     *
     * Sin esto, una instancia recién creada tiene is_active y quantity en null
     * hasta que se relee de la base: coversPeriod() la daría por inactiva y, peor,
     * multiplicar por una cantidad null dejaría el cobro en cero. El default en la
     * base sólo protege a la fila, no al objeto que se está usando.
     */
    protected $attributes = [
        'quantity'  => 1,
        'is_active' => true,
    ];

    protected $casts = [
        'price'       => 'decimal:2',
        'quantity'    => 'integer',
        'starts_at'   => 'date',
        'ends_at'     => 'date',
        'is_active'   => 'boolean',
        'assigned_at' => 'datetime',
    ];

    public function service()
    {
        return $this->belongsTo(AdditionalService::class, 'additional_service_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * Quién activó el servicio.
     *
     * Se llama `assigner` y no `assignedBy` a propósito: Eloquent serializa la
     * relación con el nombre en snake_case, así que `assignedBy` saldría en el
     * JSON como `assigned_by` y **pisaría la columna FK del mismo nombre**. La
     * misma clave significaría un id o un objeto según si la relación venía
     * cargada, y quien consume la API no tiene forma de saberlo.
     */
    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** En qué facturas se ha cobrado esta asignación. */
    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class, 'customer_additional_service_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Precio unitario que se le cobra a ESTE cliente.
     *
     * null en la asignación significa "sigue el catálogo", así que un cambio de
     * precio de lista lo alcanza. Con valor propio queda congelado.
     *
     * No está en $appends a propósito: leerlo sin haber cargado `service` hace
     * una consulta por fila. Quien lo use en un listado debe traer la relación
     * (->with('service')) y añadirlo explícitamente.
     */
    public function getEffectivePriceAttribute(): float
    {
        return (float) ($this->price ?? $this->service?->price ?? 0);
    }

    /**
     * ¿Esta asignación debe cobrarse en el periodo [$periodStart, $periodEnd]?
     *
     * Sólo la ventana de vigencia — ni la cortesía ni el prorrateo ni la
     * idempotencia, que dependen de la factura y viven en BillingService.
     */
    public function coversPeriod(Carbon $periodStart, Carbon $periodEnd): bool
    {
        if (!$this->is_active) {
            return false;
        }

        // Todavía no arranca: se activó después de que terminó el periodo.
        if ($this->starts_at && $this->starts_at->gt($periodEnd)) {
            return false;
        }

        // Ya terminó antes de que el periodo empezara.
        if ($this->ends_at && $this->ends_at->lt($periodStart)) {
            return false;
        }

        return true;
    }

    /**
     * ¿El servicio arrancó DENTRO de este periodo? Es la pregunta que decide si
     * se aplica proration_mode o si se cobra el mes completo como a cualquier
     * asignación antigua.
     */
    public function startsInsidePeriod(Carbon $periodStart, Carbon $periodEnd): bool
    {
        return $this->starts_at
            && $this->starts_at->gt($periodStart)
            && $this->starts_at->lte($periodEnd);
    }
}
