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
    ];

    protected $casts = [
        'is_serialized' => 'boolean',
        'price'         => 'decimal:2',
    ];

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
}
