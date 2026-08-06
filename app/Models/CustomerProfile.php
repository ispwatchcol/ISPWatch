<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerProfile extends Model
{
    protected $table = 'customer_profile';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public $timestamps = false;

    /**
     * Estados de servicio que SIGUEN generando factura mensual.
     *
     * Un cliente cortado por mora ('suspendido') sigue facturando: el servicio
     * se le puede restablecer pagando, así que la deuda debe reflejar los meses
     * transcurridos. El freno lo pone el tope de facturación del router
     * (Billing::invoiceStopThreshold), no el corte.
     *
     * 'retirado' y 'cancelado' son bajas definitivas: nunca vuelven a facturar.
     */
    public const BILLABLE_SERVICE_STATUSES = ['activo', 'gratis', 'suspendido'];

    protected $fillable = [
        'user_id',
        'name',
        'last_name',
        'is_company',
        'cedula',
        'department',
        'position',
        'address',
        'precinto',
        'city',
        'installation_date',
        'estrato',
        'exclude_from_billing',
        'notify_invoice',
        // null = hereda del plan y, si el plan tampoco define, del router
        'first_invoice_mode',
        'first_invoice_free_months',
        'comments',
        'state',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'credit_balance',
        'ip_user',
        'service_id',
        'sectorial_id',
        'olt_id',
        'nap_port',
        'is_fiber',
        'router_id',
        'pppoe_username',
        'pppoe_password',
        'pppoe_local_address',
        'hotspot_username',
        'hotspot_password',
        'mac_address',
        'status',
        'service_status',
        'last_ip',
        'retired_at',
        'retired_reason',
    ];

    protected $casts = [
        'is_company'           => 'boolean',
        'is_fiber'             => 'boolean',
        'exclude_from_billing' => 'boolean',
        'notify_invoice'       => 'boolean',
        'first_invoice_free_months' => 'integer',
        'retired_at'           => 'datetime',

        // Contraseñas de servicio cifradas en reposo (migración
        // 2026_07_31_000002). Se cifran sólo las CONTRASEÑAS: pppoe_username
        // tiene un índice único por router y un valor cifrado no es consultable.
        'pppoe_password'   => 'encrypted',
        'hotspot_password' => 'encrypted',
    ];

    /**
     * Clientes que el ciclo mensual debe considerar: activos, gratis y
     * cortados por mora. Se incluyen las filas antiguas con service_status en
     * NULL (anteriores a la migración que introdujo la columna), que en la
     * práctica son clientes normales.
     */
    public function scopeBillableServiceStatus($query)
    {
        return $query->where(function ($q) {
            $q->whereIn('service_status', self::BILLABLE_SERVICE_STATUSES)
                ->orWhereNull('service_status');
        });
    }

    /** ¿Este cliente sigue dentro del ciclo de facturación? (espejo del scope) */
    public function hasBillableServiceStatus(): bool
    {
        return in_array($this->service_status ?: 'activo', self::BILLABLE_SERVICE_STATUSES, true);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class, 'router_id');
    }

    /** OLT de fibra (sectorial element_type=olt) asignada al cliente. */
    public function olt()
    {
        return $this->belongsTo(Sectorial::class, 'olt_id');
    }

    /** Sector/AP inalámbrico asignado al cliente (distinto de la OLT de fibra). */
    public function sectorial()
    {
        return $this->belongsTo(Sectorial::class, 'sectorial_id');
    }
}
