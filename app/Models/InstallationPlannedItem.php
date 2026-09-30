<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Una línea del plan de una instalación: qué producto se prevé llevar y cuánto.
 *
 * NO mueve inventario. El consumo real es InstallationEquipment, que pasa por
 * InventoryLedger; esto es sólo la lista con la que el técnico sale a la
 * visita. `label` y `unit` se congelan al crear la línea para que renombrar o
 * borrar el producto no reescriba lo que se planificó.
 */
class InstallationPlannedItem extends Model
{
    use BelongsToTenant;

    protected $table = 'installation_planned_items';

    // tenant_id e installation_id no son fillable: los pone
    // InstallationPlanService a partir de la orden, nunca del cuerpo.
    protected $fillable = [
        'stock_id',
        'label',
        'unit',
        'is_serialized',
        'quantity',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity'      => 'decimal:2',
        'is_serialized' => 'boolean',
    ];

    public function installation()
    {
        return $this->belongsTo(CustomerInstallation::class, 'installation_id');
    }

    public function stock()
    {
        return $this->belongsTo(InventoryStock::class, 'stock_id');
    }
}
