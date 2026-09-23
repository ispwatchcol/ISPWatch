<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Una línea de equipo o material movida en una visita de soporte.
 *
 * device_id lleno = equipo serializado (quantity 1). device_id NULL = consumible
 * del modelo stock_id con la cantidad gastada.
 *
 * A diferencia de la instalación, aquí la línea tiene sentido: `out` es lo que
 * se le dejó al cliente y `in` es lo que se le retiró. Un cambio de router son
 * dos líneas en el mismo ticket, y las dos tienen que constar.
 */
class TicketEquipment extends Model
{
    use BelongsToTenant;

    protected $table = 'ticket_equipment';

    /** Salió del inventario y quedó en casa del cliente. */
    public const DIRECTION_OUT = 'out';

    /** Volvió de casa del cliente al inventario. */
    public const DIRECTION_IN = 'in';

    protected $fillable = [
        'ticket_id',
        'stock_id',
        'device_id',
        'direction',
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

    /** True cuando la línea es un retiro: volvió de casa del cliente. */
    public function isReturn(): bool
    {
        return $this->direction === self::DIRECTION_IN;
    }

    /**
     * True cuando el equipo se recogió para darlo de baja y no vuelve a
     * circular. Se distingue del retiro normal porque un aparato muerto
     * devuelto a bodega cuenta como disponible, y alguien lo va a prometer en
     * la siguiente instalación.
     */
    public function isScrapped(): bool
    {
        return $this->isReturn() && $this->source_type === InventoryMovement::HOLDER_SCRAP;
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
