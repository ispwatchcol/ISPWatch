<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Una línea de equipo o material usada en una instalación.
 *
 * device_id lleno = equipo serializado (quantity 1). device_id NULL = consumible
 * del modelo stock_id con la cantidad que se haya gastado.
 */
class InstallationEquipment extends Model
{
    use BelongsToTenant;

    protected $table = 'installation_equipment';

    protected $fillable = [
        'installation_id',
        'stock_id',
        'device_id',
        'quantity',
        'unit_price',
        'source_type',
        'source_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'unit_price' => 'decimal:2',
    ];

    public function installation()
    {
        return $this->belongsTo(CustomerInstallation::class, 'installation_id');
    }

    public function stock()
    {
        return $this->belongsTo(InventoryStock::class, 'stock_id');
    }

    public function device()
    {
        return $this->belongsTo(InventoryDevice::class, 'device_id');
    }

    /** True cuando la línea es un equipo con serial y no un consumible. */
    public function isSerialized(): bool
    {
        return $this->device_id !== null;
    }

    /** Etiqueta legible: "MIKROTIK LDF · S/N ABC123" o "RJ45 CAT5E". */
    public function label(): string
    {
        $stock = $this->stock ?? $this->device?->stock;
        $name  = trim(($stock?->brand ?? '') . ' ' . ($stock?->model ?? '')) ?: 'Equipo';

        if ($this->device?->serial) {
            $name .= ' · S/N ' . $this->device->serial;
        }

        return $name;
    }
}
