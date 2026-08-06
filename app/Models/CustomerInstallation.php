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
    ];

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
}
