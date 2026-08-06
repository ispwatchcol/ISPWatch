<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryDevice extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_device';
    protected $fillable = [
        'stock_id',
        'provider_id',
        'user_id',
        'branch_id',
        'customer_id',
        'status',
        'serial',
        'mac',
    ];

    public $timestamps = true;

    // Dónde está el equipo. status manda sobre user_id/branch_id/customer_id:
    // decidir la ubicación mirando cuál columna está llena era ambiguo y por eso
    // un equipo entregado a un técnico seguía apareciendo como disponible.
    public const STATUS_STOCK     = 'stock';      // en sucursal (branch_id) o sin ubicar
    public const STATUS_ASSIGNED  = 'assigned';   // en poder de user_id
    public const STATUS_INSTALLED = 'installed';  // instalado en casa de customer_id
    public const STATUS_RETIRED   = 'retired';    // de baja

    public function stock()
    {
        return $this->belongsTo(InventoryStock::class, 'stock_id');
    }

    public function provider()
    {
        return $this->belongsTo(InventoryProvider::class, 'provider_id');
    }

    public function branch()
    {
        return $this->belongsTo(InventoryBranch::class, 'branch_id');
    }

    /** Custodio interno: el empleado o técnico que lo tiene encima. */
    public function holder()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Cliente en cuya casa quedó instalado. */
    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function movements()
    {
        return $this->hasMany(InventoryMovement::class, 'device_id');
    }

    /** Equipos que todavía se pueden entregar o instalar. */
    public function scopeAvailable($query)
    {
        return $query->whereIn('status', [self::STATUS_STOCK, self::STATUS_ASSIGNED]);
    }

    /** Lo que tiene encima un usuario concreto. */
    public function scopeHeldByUser($query, int $userId)
    {
        return $query->where('status', self::STATUS_ASSIGNED)->where('user_id', $userId);
    }

    /** Lo que hay en una bodega/sucursal (nadie lo tiene encima). */
    public function scopeInBranch($query, ?int $branchId = null)
    {
        $query->where('status', self::STATUS_STOCK);

        return $branchId === null ? $query : $query->where('branch_id', $branchId);
    }
}
