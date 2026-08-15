<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Un movimiento de arrastre: el faltante de una factura que se cerró con un
 * abono parcial y que se cobrará en la siguiente factura del cliente.
 *
 * pending → todavía no lo cobró ninguna factura (revertible: si se anula el
 *           pago, el monto vuelve a la factura original).
 * applied → ya viajó a to_invoice_id; revertir el pago original NO lo devuelve,
 *           porque cobrarlo dos veces sería peor que dejarlo donde está.
 */
class InvoiceCarryover extends Model
{
    use BelongsToTenant;

    protected $table = 'invoice_carryovers';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'from_invoice_id',
        'to_invoice_id',
        'payment_id',
        'amount',
        'status',
        'applied_at',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'applied_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function fromInvoice()
    {
        return $this->belongsTo(Invoice::class, 'from_invoice_id');
    }

    public function toInvoice()
    {
        return $this->belongsTo(Invoice::class, 'to_invoice_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** Deuda arrastrada que el cliente todavía no ha visto en ninguna factura. */
    public static function pendingTotalFor(int $customerId): float
    {
        return (float) static::query()->pending()->where('customer_id', $customerId)->sum('amount');
    }
}
