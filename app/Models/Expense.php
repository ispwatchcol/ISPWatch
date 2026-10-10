<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use BelongsToTenant;

    protected $table = 'expenses';

    public const STATUS_ACTIVE = 'activo';
    public const STATUS_VOID = 'anulado';

    protected $fillable = [
        'expense_category_id',
        'user_id',
        'created_by',
        'expense_date',
        'amount',
        'description',
        'notes',
        'status',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * Movimiento de inventario que originó este gasto, cuando lo generó la
     * entrada automática (KAN-91). Null en los gastos escritos a mano, que son
     * la mayoría. La columna es ÚNICA: es lo que impide cobrar dos veces la
     * misma entrada si la operación se reintenta.
     */
    public function inventoryMovement()
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    /** ¿Lo generó solo el inventario, o lo escribió una persona? */
    public function esAutomatico(): bool
    {
        return $this->inventory_movement_id !== null;
    }

    /**
     * Staff/técnico a nombre de quién se registra el gasto. Nullable: no todo
     * gasto está asociado a una persona (arriendo, servicios, etc.).
     */
    public function beneficiary()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
