<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Servicio adicional del catálogo: la plantilla reutilizable ("Soporte técnico
 * mensual", "Alquiler de router extra") que se asigna a varios clientes y se
 * cobra dentro de la mensualidad de cada uno.
 *
 * El precio de aquí es el de lista. Cada asignación puede congelar el suyo
 * (ver CustomerAdditionalService::$price), así que subirlo NO le cambia el
 * cobro a quien tenga precio propio.
 *
 * OJO en contexto de consola. El scope global de BelongsToTenant deriva el
 * tenant del usuario autenticado, y el cobro mensual corre en el scheduler,
 * sin sesión: ahí el scope no filtra nada. Toda consulta que haga el servicio
 * de facturación debe llevar ->where('tenant_id', ...) explícito, como ya hace
 * BillingService::applyPendingCarryoversTo con los arrastres.
 */
class AdditionalService extends Model
{
    use BelongsToTenant;

    protected $table = 'additional_services';

    /**
     * Qué cobrar el mes en que arranca el servicio. Es EL MISMO vocabulario que
     * la política de primera factura de los planes (none / prorated / full):
     * mismas palabras y mismo significado, para que el operador no tenga que
     * aprender dos idiomas para la misma decisión.
     */
    public const PRORATION_MODES = Billing::FIRST_INVOICE_MODES;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'price',
        'charge_on_courtesy_month',
        'proration_mode',
        'is_active',
        'sort_order',
    ];

    protected $attributes = [
        'charge_on_courtesy_month' => true,
        'proration_mode'           => Billing::FIRST_INVOICE_FULL,
        'is_active'                => true,
        'sort_order'               => 0,
    ];

    protected $casts = [
        'price'                    => 'decimal:2',
        'charge_on_courtesy_month' => 'boolean',
        'is_active'                => 'boolean',
        'sort_order'               => 'integer',
    ];

    public function assignments()
    {
        return $this->hasMany(CustomerAdditionalService::class);
    }

    /** Sólo las vigentes: es el número que interesa mostrar en el catálogo. */
    public function activeAssignments()
    {
        return $this->hasMany(CustomerAdditionalService::class)->where('is_active', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
