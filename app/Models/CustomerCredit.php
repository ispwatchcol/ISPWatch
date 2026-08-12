<?php

namespace App\Models;

use App\Support\AuditContext;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Un movimiento del saldo a favor del cliente.
 *
 * Invariante que sostiene esta tabla:
 *
 *     SUM(customer_credits.amount) del cliente == customer_profile.credit_balance
 *
 * `credit_balance` sigue existiendo como caché para pintar listados sin sumar
 * la historia en cada fila, pero la verdad son los movimientos. Todo lo que
 * toque el saldo debe pasar por los métodos de esta clase; escribir
 * credit_balance a pelo rompe el libro.
 *
 * earned   (+) un pago dejó excedente
 * applied  (-) el saldo pagó una factura
 * adjusted (±) un operador lo corrigió a mano
 * reversed (-) se anuló el pago que había generado el saldo
 *
 * El campo `consumed` de un movimiento `earned` dice cuánto de ese excedente ya
 * se gastó en facturas. Es lo que permite anular un pago sin destruir saldo
 * ajeno: solo se devuelve la parte que todavía no consumió nadie. La doctrina
 * es la misma de InvoiceCarryover — lo que ya viajó a otra factura se queda
 * donde está, porque revertirlo cobraría dos veces.
 */
class CustomerCredit extends Model
{
    use BelongsToTenant;

    protected $table = 'customer_credits';

    public const TYPE_EARNED   = 'earned';
    public const TYPE_APPLIED  = 'applied';
    public const TYPE_ADJUSTED = 'adjusted';
    public const TYPE_REVERSED = 'reversed';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'type',
        'from_payment_id',
        'to_invoice_id',
        'amount',
        'balance_after',
        'consumed',
        'reason',
        'created_by',
        'source',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'balance_after' => 'decimal:2',
        'consumed'      => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'from_payment_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'to_invoice_id');
    }

    /** Operador que originó el movimiento; null si lo hizo el scheduler. */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Escritura del libro ────────────────────────────────────────────────

    /**
     * Un pago dejó excedente: entra al saldo a favor.
     */
    public static function earn(Payment $payment, float $amount, ?string $reason = null): ?self
    {
        if ($amount <= 0) {
            return null;
        }

        return static::record(
            customerId: (int) $payment->customer_id,
            type: self::TYPE_EARNED,
            amount: $amount,
            reason: $reason,
            fromPaymentId: (int) $payment->id,
            tenantId: $payment->tenant_id ? (int) $payment->tenant_id : null,
        );
    }

    /**
     * El saldo a favor paga (total o parcialmente) una factura.
     *
     * Además del movimiento negativo, marca como consumidos los `earned` más
     * antiguos por ese monto (FIFO). Sin ese marcaje, anular después el pago
     * que generó el saldo restaría de nuevo algo que ya se gastó.
     */
    public static function applyToInvoice(Invoice $invoice, int $customerId, float $amount): ?self
    {
        if ($amount <= 0) {
            return null;
        }

        $movement = static::record(
            customerId: $customerId,
            type: self::TYPE_APPLIED,
            amount: -$amount,
            reason: "Saldo a favor aplicado a la factura {$invoice->number}",
            toInvoiceId: (int) $invoice->id,
            tenantId: $invoice->tenant_id ? (int) $invoice->tenant_id : null,
        );

        static::consumeEarned($customerId, $amount);

        return $movement;
    }

    /**
     * Ajuste manual de un operador. `newBalance` es el saldo final deseado; el
     * movimiento guarda la diferencia contra el saldo actual.
     */
    public static function adjust(int $customerId, float $newBalance, float $previousBalance, ?string $reason = null): ?self
    {
        $delta = round($newBalance - $previousBalance, 2);

        if (abs($delta) < 0.01) {
            return null;
        }

        $movement = static::record(
            customerId: $customerId,
            type: self::TYPE_ADJUSTED,
            amount: $delta,
            reason: $reason ?: 'Ajuste manual de saldo a favor',
        );

        // Un ajuste a la baja también gasta saldo: hay que marcarlo consumido
        // o la reversión de un pago viejo creería que ese saldo sigue vivo.
        if ($delta < 0) {
            static::consumeEarned($customerId, abs($delta));
        }

        return $movement;
    }

    /**
     * Se anula o corrige un pago: devuelve SOLO el excedente que todavía no
     * consumió ninguna factura.
     *
     * @return array{reversed: float, kept: float} Lo devuelto y lo que ya se
     *         había gastado (que no se toca, para no cobrarlo dos veces).
     */
    public static function reverseForPayment(Payment $payment): array
    {
        $earned = static::query()
            ->where('from_payment_id', $payment->id)
            ->where('type', self::TYPE_EARNED)
            ->orderBy('id')
            ->get();

        $reversible = 0.0;
        $kept       = 0.0;

        foreach ($earned as $row) {
            $available = round((float) $row->amount - (float) $row->consumed, 2);
            $reversible += max(0, $available);
            $kept       += (float) $row->consumed;
        }

        if ($reversible > 0) {
            static::record(
                customerId: (int) $payment->customer_id,
                type: self::TYPE_REVERSED,
                amount: -$reversible,
                reason: "Reversión del pago #{$payment->id}",
                fromPaymentId: (int) $payment->id,
                tenantId: $payment->tenant_id ? (int) $payment->tenant_id : null,
            );

            // El saldo devuelto ya no existe: marcar los earned como consumidos
            // evita que una segunda reversión del mismo pago lo reste otra vez.
            foreach ($earned as $row) {
                $available = round((float) $row->amount - (float) $row->consumed, 2);
                if ($available > 0) {
                    $row->consumed = (float) $row->consumed + $available;
                    $row->save();
                }
            }
        }

        return ['reversed' => round($reversible, 2), 'kept' => round($kept, 2)];
    }

    // ─── Motor ──────────────────────────────────────────────────────────────

    /**
     * Escribe un movimiento y sincroniza la caché credit_balance.
     *
     * `balance_after` se calcula sobre el saldo real del perfil, no sumando la
     * tabla, para que un descuadre preexistente se vea en el extracto en vez de
     * quedar disimulado.
     */
    protected static function record(
        int $customerId,
        string $type,
        float $amount,
        ?string $reason = null,
        ?int $fromPaymentId = null,
        ?int $toInvoiceId = null,
        ?int $tenantId = null,
    ): self {
        return DB::transaction(function () use ($customerId, $type, $amount, $reason, $fromPaymentId, $toInvoiceId, $tenantId) {
            $profile  = CustomerProfile::where('user_id', $customerId)->first();
            $previous = $profile ? (float) $profile->credit_balance : 0.0;
            $after    = round($previous + $amount, 2);

            $movement = static::create([
                'tenant_id'       => $tenantId ?: AuditContext::tenantIdForCustomer($customerId),
                'customer_id'     => $customerId,
                'type'            => $type,
                'from_payment_id' => $fromPaymentId,
                'to_invoice_id'   => $toInvoiceId,
                'amount'          => round($amount, 2),
                'balance_after'   => $after,
                'consumed'        => 0,
                'reason'          => $reason,
                'created_by'      => AuditContext::actorId(),
                'source'          => AuditContext::source(),
            ]);

            if ($profile) {
                $profile->credit_balance = $after;
                $profile->save();
            }

            return $movement;
        });
    }

    /**
     * Marca $amount como consumido sobre los `earned` más antiguos con saldo
     * disponible (FIFO), que es el orden en que el cliente los generó.
     */
    protected static function consumeEarned(int $customerId, float $amount): void
    {
        $pending = round($amount, 2);

        $rows = static::query()
            ->where('customer_id', $customerId)
            ->where('type', self::TYPE_EARNED)
            ->whereColumn('consumed', '<', 'amount')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if ($pending <= 0) {
                break;
            }

            $available = round((float) $row->amount - (float) $row->consumed, 2);
            $take      = min($available, $pending);

            $row->consumed = round((float) $row->consumed + $take, 2);
            $row->save();

            $pending = round($pending - $take, 2);
        }

        // Si queda pendiente es que se aplicó más saldo del que quedó
        // registrado como ganado — típicamente saldo anterior a esta tabla.
        // No es error: el backfill lo resuelve y el extracto lo deja ver.
    }

    // ─── Lectura ────────────────────────────────────────────────────────────

    /** Saldo según el libro. Debe coincidir con customer_profile.credit_balance. */
    public static function ledgerBalanceFor(int $customerId): float
    {
        return round((float) static::query()->where('customer_id', $customerId)->sum('amount'), 2);
    }
}
