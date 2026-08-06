<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Saldo de un consumible (RJ45, cable, plato) en poder de un custodio.
 *
 * Nunca se escribe directamente desde un controlador: todo pasa por
 * InventoryLedger, que es quien garantiza que el saldo y el kardex se muevan
 * juntos. Una fila suelta aquí es un saldo que nadie puede explicar.
 */
class InventoryBalance extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_balances';

    protected $fillable = [
        'stock_id',
        'holder_type',
        'holder_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    public const HOLDER_BRANCH = 'branch';
    public const HOLDER_USER   = 'user';

    public function stock()
    {
        return $this->belongsTo(InventoryStock::class, 'stock_id');
    }

    /** Saldos de un custodio concreto (sucursal o usuario). */
    public function scopeHeldBy($query, string $holderType, int $holderId)
    {
        return $query->where('holder_type', $holderType)->where('holder_id', $holderId);
    }
}
