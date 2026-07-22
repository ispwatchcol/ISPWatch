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
