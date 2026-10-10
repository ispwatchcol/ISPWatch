<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryStock extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_stock';

    // tenant_id is intentionally NOT fillable: it is set automatically from the
    // authenticated user by BelongsToTenant, so a client can't spoof it.
    protected $fillable = [
        'brand',
        'model',
        'desc',
        'price',
        'is_serialized',
        'unit',
        'quantity_decimals',
    ];

    protected $casts = [
        'is_serialized' => 'boolean',
        'price'         => 'decimal:2',
        'quantity_decimals' => 'integer',
    ];

    /** Tope de decimales: saldos y kardex son decimal(12,2). */
    public const MAX_QUANTITY_DECIMALS = 2;

    public function devices()
    {
        return $this->hasMany(InventoryDevice::class, 'stock_id');
    }

    public function balances()
    {
        return $this->hasMany(InventoryBalance::class, 'stock_id');
    }

    /** "MIKROTIK LDF" — el nombre con el que se ve en toda la app. */
    public function label(): string
    {
        return trim(($this->brand ?? '') . ' ' . ($this->model ?? '')) ?: 'Sin nombre';
    }

    /**
     * Decimales que admite la cantidad de este producto. Un serializado se
     * cuenta por unidad (0); uno por cantidad, lo que declare, que por default
     * es 2 —lo que el sistema aceptaba antes de existir la columna—.
     */
    public function quantityDecimals(): int
    {
        if ($this->is_serialized) {
            return 0;
        }

        $decimals = $this->quantity_decimals ?? self::MAX_QUANTITY_DECIMALS;

        return max(0, min(self::MAX_QUANTITY_DECIMALS, (int) $decimals));
    }

    /** ¿La cantidad respeta la precisión del producto? */
    public function acceptsQuantity(float $quantity): bool
    {
        $factor = 10 ** $this->quantityDecimals();

        return abs(round($quantity * $factor) - $quantity * $factor) < 1e-6;
    }
}
