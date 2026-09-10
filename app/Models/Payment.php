<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'amount',
        'payment_date',
        'method',
        'reference',
        'notes',
        'status',
        'created_by'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    /**
     * Resultado de la reconexión automática que dispara este pago, si estaba
     * cortado el cliente. Ver BillingService::reactivateIfCleared().
     *
     * Es una propiedad PHP declarada a propósito, NO un atributo Eloquent: no
     * existe la columna, y si entrara al array de atributos el primer `save()`
     * posterior intentaría escribirla y reventaría. El controlador la mete a
     * mano en el JSON de la respuesta.
     *
     * @var array{was_suspended:bool,reactivated:bool,router_ok:bool,message:string}|null
     */
    public ?array $reactivation = null;

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * Staff user who registered this payment. Nullable: payments created
     * automatically (installation billing, etc.) have no human registrant.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function invoices()
    {
        return $this->belongsToMany(Invoice::class, 'payment_allocations')
            ->withPivot('amount')
            ->withTimestamps();
    }
}
