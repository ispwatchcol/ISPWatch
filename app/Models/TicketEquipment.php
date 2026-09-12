<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Una línea de equipo o material entregada en un ticket de soporte.
 *
 * Espejo de InstallationEquipment: `device_id` lleno = equipo serializado
 * (quantity 1); `device_id` NULL = consumible del modelo `stock_id` con la
 * cantidad que se haya gastado.
 *
 * Es una tabla aparte y no una generalización de `installation_equipment`
 * porque aquella tiene `unique('device_id')` —un equipo serializado está en una
 * sola instalación— y esa garantía no se podía tocar sin migrar un módulo que
 * hoy funciona. Ver la migración para el razonamiento completo.
 */
class TicketEquipment extends Model
{
    use BelongsToTenant;

    protected $table = 'ticket_equipment';

    protected $fillable = [
        'ticket_id',
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

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
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
