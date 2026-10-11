<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Un movimiento de arrastre: el faltante de una factura que se cerró con un
 * abono parcial y que se cobrará en la siguiente factura del cliente.
 *
 * También es una CUOTA de un acuerdo de pago de instalación cuando trae
 * `installment_number` («cuota 2 de 3»). Ahí no hay pago que la origine
 * (`payment_id` null): la factura de instalación sacó lo financiado por
 * `carried_out` y cada cuota espera aquí. Ver InstallationInstallmentPlanner.
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
        'installment_number',
        'installment_count',
    ];

    protected $casts = [
        'amount'             => 'decimal:2',
        'applied_at'         => 'datetime',
        'installment_number' => 'integer',
        'installment_count'  => 'integer',
    ];

    public function isInstallment(): bool
    {
        return $this->installment_number !== null;
    }

    /**
     * Renglón de factura de una cuota: «Cuota 2 de 3 · instalación (factura
     * #F-0012)». Uno solo para la mensualidad y para la factura aparte, para
     * que el cliente lea lo mismo en los dos modos.
     */
    public static function installmentDescription(self $row, ?string $fromNumber): string
    {
        return "Cuota {$row->installment_number} de {$row->installment_count} · instalación"
            . ($fromNumber ? " (factura #{$fromNumber})" : '');
    }

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

    /** Cuotas de acuerdos de pago de instalación. */
    public function scopeInstallments($query)
    {
        return $query->whereNotNull('installment_number');
    }

    /** Faltantes de abonos parciales (lo que había antes de las cuotas). */
    public function scopeShortfalls($query)
    {
        return $query->whereNull('installment_number');
    }

    /**
     * Faltantes de abonos parciales que el cliente todavía no ha visto en
     * ninguna factura. Se cobran TODOS en la próxima mensual.
     *
     * Las cuotas de instalación NO entran: van una por mes, y sumarlas aquí
     * haría que la caja anunciara que «se suma a la próxima factura» un plan
     * entero de seis meses. Para ellas está `pendingInstallmentsFor()`.
     */
    public static function pendingTotalFor(int $customerId): float
    {
        return (float) static::query()->pending()->shortfalls()->where('customer_id', $customerId)->sum('amount');
    }

    /** Cuotas de instalación pendientes (monto y cuántas), para la caja. */
    public static function pendingInstallmentsFor(int $customerId): array
    {
        $filas = static::query()->pending()->installments()->where('customer_id', $customerId)->get(['amount']);

        return [
            'amount' => round((float) $filas->sum('amount'), 2),
            'count'  => $filas->count(),
        ];
    }
}
