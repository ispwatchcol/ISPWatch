<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $table = 'payment_methods';

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static array $defaults = [
        ['name' => 'Efectivo',     'description' => 'Pago en efectivo'],
        ['name' => 'Tarjeta',      'description' => 'Pago con tarjeta débito o crédito'],
        ['name' => 'Corresponsal', 'description' => 'Pago en corresponsal bancario'],
        ['name' => 'Transacción',  'description' => 'Transferencia o transacción bancaria'],
    ];
}
