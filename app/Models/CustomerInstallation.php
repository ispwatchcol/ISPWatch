<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use App\Models\Invoice;

class CustomerInstallation extends Model
{
    use BelongsToTenant;

    protected $table = 'customer_installations';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'prospect_id',
        'scheduled_date',
        'technician',
        'technician_id',
        'address',
        'equipment',
        'notes',
        'sheet',
        'customer_signature_path',
        'technician_signature_path',
        'signed_at',
        'status',
        'completed_at',
        'created_by',
        // billing
        'payment_agreement',
        'installation_cost',
        'additional_charges',
        'additional_items',
        'discount',
        'discount_reason',
        'payment_method',
        'payment_received',
        'payment_notes',
        'customer_retention',
        'special_attention',
        'promotion_notes',
        // Visita que no se le cobra al cliente (garantía, mantenimiento). El
        // equipo igual sale de la bodega; lo que no sale es la factura.
        'no_charge',
        'no_charge_reason',
    ];

    protected $casts = [
        'scheduled_date'     => 'date',
        'completed_at'       => 'datetime',
        'signed_at'          => 'datetime',
        'sheet'              => 'array',
        // billing
        'payment_agreement'  => 'boolean',
        'installation_cost'  => 'decimal:2',
        'additional_charges' => 'decimal:2',
        'additional_items'   => 'array',
        'discount'           => 'decimal:2',
        'payment_received'   => 'decimal:2',
        'customer_retention' => 'boolean',
        'special_attention'  => 'boolean',
        'no_charge'          => 'boolean',
    ];

    protected static function booted(): void
    {
        // Una orden que ya descargó inventario no se borra por ningún camino.
        // La FK de `installation_equipment` es CASCADE: el borrado se llevaba
        // las líneas sin devolver nada, los equipos seguían figurando
        // instalados en el cliente y el material consumido desaparecía del
        // saldo sin respaldo (P-69). El controlador lo rechaza antes con un
        // mensaje propio; esto cubre un comando, un job o un `delete()`
        // despistado, igual que la guarda de TicketEquipment.
        static::deleting(function (self $installation) {
            if ($installation->equipmentItems()->exists()) {
                throw new \RuntimeException(
                    "La orden de instalación #{$installation->id} tiene equipos o materiales descargados "
                    . 'del inventario y no se puede borrar: se perdería el respaldo de ese consumo.'
                );
            }
        });
    }

    /**
     * True cuando la hoja ya se firmó. Desde ese momento lo usado en la visita
     * es lo que el cliente firmó, y las líneas no se tocan desde la operación
     * ordinaria.
     */
    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function prospect()
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }

    public function technicianUser()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents()
    {
        return $this->hasMany(CustomerDocument::class, 'installation_id');
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class, 'installation_id');
    }

    /**
     * Equipos y materiales descargados del inventario en esta visita.
     *
     * NO se puede llamar `equipment()`: la tabla ya tiene una columna
     * `equipment` (el texto libre "equipo previsto"), y Eloquent resuelve
     * primero el atributo, así que la relación quedaría inalcanzable desde
     * `$installation->equipment` y las vistas leerían el string de siempre.
     */
    public function equipmentItems()
    {
        return $this->hasMany(InstallationEquipment::class, 'installation_id');
    }

    /**
     * Lo que se PREVÉ llevar a la visita. No mueve inventario: el consumo real
     * es `equipmentItems()`. Convive con el texto libre `equipment`, que se
     * conserva para las órdenes anteriores y para notas que no son productos.
     */
    public function plannedItems()
    {
        return $this->hasMany(InstallationPlannedItem::class, 'installation_id');
    }
}
