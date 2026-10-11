<?php

namespace App\Services;

use App\Models\CustomerInstallation;
use App\Models\Invoice;
use App\Models\InvoiceCarryover;
use App\Models\InvoiceItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Acuerdo de pago de una instalación: divide lo que se debe en cuotas.
 *
 * ── El modelo ────────────────────────────────────────────────────────────────
 *
 * La factura de instalación (la «madre») sigue llevando el detalle completo:
 * valor, adicionales, descuento, abono recibido. Lo que queda debiendo después
 * del abono es lo FINANCIADO, y sale de ella por `carried_out`, igual que sale
 * el faltante de un abono parcial. La madre queda saldada (no cuenta para mora
 * ni corte) y cada cuota espera en una fila de `invoice_carryovers` con
 * `installment_number` / `installment_count`.
 *
 * Dos modos, a elección del asesor en cada orden:
 *
 *   monthly  → las filas quedan PENDIENTES y cada factura mensual cobra la
 *              siguiente (BillingService::chargeablePendingCarryovers). El
 *              cliente recibe una sola factura al mes.
 *   separate → cada cuota se emite YA como su propia factura de instalación,
 *              con vencimientos mes a mes; la fila queda APLICADA a ella. Las
 *              que no han vencido no cuentan para mora, corte ni tope.
 *
 * ── Editar el acuerdo ───────────────────────────────────────────────────────
 *
 * La cartera se puede guardar muchas veces. Cada guardado recalcula el plan,
 * pero lo ya COBRADO no se toca:
 *
 *   · monthly: una cuota está cobrada si ya viajó a una factura mensual.
 *   · separate: una cuota está cobrada si su factura ya recibió algo de dinero
 *     (pago, saldo a favor o abono parcial). Las que no, se reescriben EN SU
 *     SITIO (mismo número de factura) y las sobrantes se anulan.
 *
 * Lo cobrado queda fijo; lo que falta se reparte entre las cuotas que faltan.
 * Si eso no cuadra (menos cuotas de las ya cobradas, o un saldo por debajo de
 * lo ya cobrado) se rechaza con un mensaje: mover dinero ya cobrado es un
 * trabajo de Facturación, no de esta casilla.
 *
 * ── Lo que NO hace, a propósito ─────────────────────────────────────────────
 *
 * No cobra intereses ni recargos: un acuerdo de pago de instalación es un
 * diferimiento, no un crédito. Y no emite avisos: las cuotas en factura aparte
 * entran en los recordatorios normales de su vencimiento.
 */
class InstallationInstallmentPlanner
{
    public function __construct(protected BillingService $billing)
    {
    }

    /**
     * Deja el plan de cuotas de `$mother` de acuerdo con la orden.
     *
     * `$owed` es lo que la madre debe ANTES de financiar: total − asignado −
     * saldo a favor aplicado − faltantes de abonos parciales. Devuelve cuánto
     * quedó financiado (lo que sale de la madre por cuotas).
     *
     * Corre dentro de la transacción de InstallationBillingService: si algo
     * se rechaza, no queda nada a medias.
     *
     * @throws ValidationException
     */
    public function sync(Invoice $mother, CustomerInstallation $installation, float $owed, ?int $actorId = null): float
    {
        $owed = round(max(0.0, $owed), 2);

        $rows = InvoiceCarryover::installments()
            ->where('from_invoice_id', $mother->id)
            ->with('toInvoice')
            ->orderBy('installment_number')
            ->get();

        $locked = $rows->filter(fn (InvoiceCarryover $r) => $this->isLocked($r, $mother));
        $free   = $rows->reject(fn (InvoiceCarryover $r) => $this->isLocked($r, $mother));

        $lockedAmount = round((float) $locked->sum('amount'), 2);
        $lockedCount  = $locked->count();

        $mode  = $installation->installments_mode;
        $count = (int) ($installation->installments_count ?? 0);

        $wants = (bool) $installation->payment_agreement && !$installation->no_charge;

        if ($wants && $owed > 0.009 && ($count < 1 || !in_array($mode, CustomerInstallation::INSTALLMENT_MODES, true))) {
            throw ValidationException::withMessages([
                'installments_count' => 'Con acuerdo de pago, indica en cuántas cuotas y cómo se cobran '
                    . '(dentro de la mensualidad o en facturas aparte).',
            ]);
        }

        if ($wants && $lockedCount > 0 && ($lockedMode = $this->modeOf($locked, $mother)) !== $mode) {
            throw ValidationException::withMessages([
                'installments_mode' => 'Este acuerdo ya tiene cuotas cobradas '
                    . ($lockedMode === CustomerInstallation::INSTALLMENTS_SEPARATE ? 'en facturas aparte' : 'dentro de la mensualidad')
                    . ': no se puede cambiar cómo se cobran las que faltan.',
            ]);
        }

        // ── Sin acuerdo (o sin nada que financiar) ──────────────────────────
        if (!$wants || $owed <= 0.009) {
            if ($owed + 0.009 < $lockedAmount) {
                throw ValidationException::withMessages([
                    'payment_agreement' => 'Ya se cobraron $' . number_format($lockedAmount, 0, ',', '.')
                        . ' en cuotas de este acuerdo, más de lo que quedaría debiendo la instalación. '
                        . 'Corrígelo desde Facturación.',
                ]);
            }

            $this->discard($free, $actorId);

            if ($lockedCount > 0) {
                $this->renumber($locked, $lockedCount);
            }

            return $lockedAmount;
        }

        // ── Con acuerdo ─────────────────────────────────────────────────────
        if ($count > CustomerInstallation::MAX_INSTALLMENTS) {
            throw ValidationException::withMessages([
                'installments_count' => 'Máximo ' . CustomerInstallation::MAX_INSTALLMENTS . ' cuotas.',
            ]);
        }

        if ($count < $lockedCount) {
            throw ValidationException::withMessages([
                'installments_count' => "Ya se cobraron {$lockedCount} cuota(s) de este acuerdo: "
                    . "no puede quedar en menos de {$lockedCount}.",
            ]);
        }

        $remaining = round($owed - $lockedAmount, 2);

        if ($remaining < -0.009) {
            throw ValidationException::withMessages([
                'installments_count' => 'Ya se cobraron $' . number_format($lockedAmount, 0, ',', '.')
                    . ' en cuotas, más de lo que quedaría debiendo la instalación. Corrígelo desde Facturación.',
            ]);
        }

        $lockedNumbers = $locked->pluck('installment_number')->map(fn ($n) => (int) $n)->all();
        $slots = array_values(array_diff(range(1, $count), $lockedNumbers));

        if ($slots === [] && $remaining > 0.009) {
            throw ValidationException::withMessages([
                'installments_count' => "Las {$count} cuota(s) ya se cobraron y todavía quedan $"
                    . number_format($remaining, 0, ',', '.') . ' por financiar: aumenta el número de cuotas.',
            ]);
        }

        if ($slots !== [] && $remaining <= 0.009) {
            throw ValidationException::withMessages([
                'installments_count' => "Con lo ya cobrado no queda saldo para más cuotas: déjalo en {$lockedCount}.",
            ]);
        }

        $amounts = $this->split($remaining, count($slots));

        if ($mode === CustomerInstallation::INSTALLMENTS_MONTHLY) {
            $this->materializeMonthly($mother, $free, $slots, $amounts, $count, $actorId);
        } else {
            $this->materializeSeparate($mother, $installation, $free, $slots, $amounts, $count, $actorId);
        }

        $this->renumber($locked, $count);

        Log::info("InstallationInstallments: factura #{$mother->number} (instalación #{$installation->id}) "
            . "financia \${$owed} en {$count} cuota(s), modo {$mode}.");

        return $owed;
    }

    /**
     * Resumen para la pantalla de la orden. Lee lo que hay, no lo que se pidió.
     *
     * @return array<string, mixed>|null
     */
    public function summary(?Invoice $mother): ?array
    {
        if (!$mother) {
            return null;
        }

        $rows = InvoiceCarryover::installments()
            ->where('from_invoice_id', $mother->id)
            ->with('toInvoice:id,number,invoice_type,due_date,status,total,balance_due,carried_out')
            ->orderBy('installment_number')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $cuotas = $rows->map(function (InvoiceCarryover $r) use ($mother) {
            $vehiculo = $r->toInvoice;

            return [
                'number'         => (int) $r->installment_number,
                'of'             => (int) $r->installment_count,
                'amount'         => round((float) $r->amount, 2),
                // pending  → espera la próxima mensualidad
                // billed   → ya está en una factura (mensual o propia)
                'state'          => $r->status === InvoiceCarryover::STATUS_PENDING ? 'pending' : 'billed',
                'invoice_id'     => $vehiculo?->id,
                'invoice_number' => $vehiculo?->number,
                'invoice_status' => $vehiculo?->status,
                'due_date'       => $vehiculo?->due_date?->toDateString(),
                'paid'           => $vehiculo ? (float) $vehiculo->balance_due <= 0.009 : false,
                'locked'         => $this->isLocked($r, $mother),
            ];
        })->values();

        return [
            'financed' => round((float) $rows->sum('amount'), 2),
            'count'    => (int) $rows->max('installment_count'),
            'mode'     => $this->modeOf($rows, $mother),
            'pending'  => round((float) $rows->where('status', InvoiceCarryover::STATUS_PENDING)->sum('amount'), 2),
            'items'    => $cuotas,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ¿Esta cuota ya se cobró y no se puede reescribir?
     *
     *  · Pendiente: no — todavía no la vio ningún documento.
     *  · En una factura MENSUAL: sí — esa factura ya se emitió con ella.
     *  · En su propia factura (modo separate): sólo si esa factura ya recibió
     *    algo de dinero. Una cuota emitida pero intacta se puede reescribir.
     */
    private function isLocked(InvoiceCarryover $row, Invoice $mother): bool
    {
        if ($row->status === InvoiceCarryover::STATUS_PENDING) {
            return false;
        }

        $vehiculo = $row->toInvoice;

        if (!$vehiculo || !$this->isOwnInstallmentInvoice($vehiculo, $mother)) {
            return true;
        }

        return $this->hasMoney($vehiculo);
    }

    /** Factura propia de una cuota: tipo instalación y distinta de la madre. */
    private function isOwnInstallmentInvoice(Invoice $invoice, Invoice $mother): bool
    {
        return $invoice->invoice_type === Invoice::TYPE_INSTALLATION && (int) $invoice->id !== (int) $mother->id;
    }

    /**
     * Algo de dinero ya la tocó: un pago, saldo a favor o un abono parcial que
     * la cerró. Cualquiera de los tres deja el saldo por debajo del total o
     * deja `carried_out`.
     */
    private function hasMoney(Invoice $invoice): bool
    {
        return round((float) $invoice->total - (float) $invoice->balance_due, 2) > 0.009
            || (float) $invoice->carried_out > 0.009;
    }

    /** Modo de un plan ya materializado, deducido de dónde viven sus cuotas. */
    private function modeOf(Collection $rows, Invoice $mother): string
    {
        $enFacturaPropia = $rows->contains(fn (InvoiceCarryover $r) => $r->status === InvoiceCarryover::STATUS_APPLIED
            && $r->toInvoice
            && $this->isOwnInstallmentInvoice($r->toInvoice, $mother));

        return $enFacturaPropia
            ? CustomerInstallation::INSTALLMENTS_SEPARATE
            : CustomerInstallation::INSTALLMENTS_MONTHLY;
    }

    /**
     * Reparte en pesos enteros cuando el monto es entero: $100.000 en 3 cuotas
     * son 33.333 + 33.333 + 33.334, no 33.333,33 tres veces. La última cuota
     * se lleva el resto, centavos incluidos si los hubiera.
     *
     * @return array<int, float>
     */
    private function split(float $total, int $parts): array
    {
        if ($parts <= 0) {
            return [];
        }

        $base = floor($total / $parts);
        $amounts = array_fill(0, $parts, (float) $base);
        $amounts[$parts - 1] = round($total - $base * ($parts - 1), 2);

        return $amounts;
    }

    /**
     * Saca del plan las cuotas que no se cobraron: las pendientes se borran
     * (nunca las vio nadie) y las facturas propias intactas se ANULAN —se
     * conservan con su número, como manda la doctrina de anular y no borrar.
     */
    private function discard(Collection $rows, ?int $actorId): void
    {
        foreach ($rows as $row) {
            $vehiculo = $row->status === InvoiceCarryover::STATUS_APPLIED ? $row->toInvoice : null;

            // La fila primero: `voidInvoice()` devolvería a pendiente cualquier
            // arrastre aplicado a la factura, y esta cuota ya no debe cobrarse.
            $row->delete();

            if ($vehiculo && !$vehiculo->estaAnulada()) {
                $this->billing->voidInvoice(
                    $vehiculo,
                    'Acuerdo de pago de la instalación modificado: esta cuota ya no hace parte del plan.',
                    $actorId
                );
            }
        }
    }

    /** «Cuota k de N»: el total del plan cambió, se actualiza en las cobradas. */
    private function renumber(Collection $rows, int $count): void
    {
        foreach ($rows as $row) {
            if ((int) $row->installment_count !== $count) {
                $row->update(['installment_count' => $count]);
            }
        }
    }

    /**
     * @param  array<int, int>    $slots
     * @param  array<int, float>  $amounts
     */
    private function materializeMonthly(Invoice $mother, Collection $free, array $slots, array $amounts, int $count, ?int $actorId): void
    {
        // Lo libre se rehace entero: son filas que nadie vio todavía (o
        // facturas propias intactas, si el plan venía de otro modo).
        $this->discard($free, $actorId);

        foreach ($slots as $i => $numero) {
            InvoiceCarryover::create([
                'tenant_id'          => $mother->tenant_id,
                'customer_id'        => $mother->customer_id,
                'from_invoice_id'    => $mother->id,
                'payment_id'         => null,
                'amount'             => $amounts[$i],
                'status'             => InvoiceCarryover::STATUS_PENDING,
                'installment_number' => $numero,
                'installment_count'  => $count,
            ]);
        }
    }

    /**
     * @param  array<int, int>    $slots
     * @param  array<int, float>  $amounts
     */
    private function materializeSeparate(
        Invoice $mother,
        CustomerInstallation $installation,
        Collection $free,
        array $slots,
        array $amounts,
        int $count,
        ?int $actorId
    ): void {
        // Facturas propias intactas: se reutilizan en orden, para no gastar
        // números ni dejar anuladas por cada vez que se guarda la cartera.
        $reutilizables = $free
            ->filter(fn (InvoiceCarryover $r) => $r->status === InvoiceCarryover::STATUS_APPLIED
                && $r->toInvoice && $this->isOwnInstallmentInvoice($r->toInvoice, $mother))
            ->sortBy('installment_number')
            ->values();

        // Pendientes en modo aparte: su factura se anuló o se borró. Se
        // descartan y su hueco se vuelve a emitir.
        $this->discard(
            $free->reject(fn (InvoiceCarryover $r) => $reutilizables->contains('id', $r->id)),
            $actorId
        );

        $primera = $installation->installments_first_due_date
            ? Carbon::parse($installation->installments_first_due_date)->startOfDay()
            : now()->startOfDay()->addMonthNoOverflow();

        foreach ($slots as $i => $numero) {
            $vence = $primera->copy()->addMonthsNoOverflow($numero - 1);
            $fila  = $reutilizables->shift();

            if ($fila) {
                $this->rewriteInstallmentInvoice($fila, $mother, $numero, $count, $amounts[$i], $vence);
            } else {
                $this->issueInstallmentInvoice($mother, $numero, $count, $amounts[$i], $vence);
            }
        }

        // Sobraron facturas (el plan quedó con menos cuotas): se anulan.
        $this->discard($reutilizables, $actorId);
    }

    private function issueInstallmentInvoice(Invoice $mother, int $numero, int $count, float $monto, Carbon $vence): void
    {
        $factura = Invoice::create([
            'tenant_id'    => $mother->tenant_id,
            'customer_id'  => $mother->customer_id,
            // SIN installation_id: la orden apunta a UNA factura (la madre, por
            // `hasOne`). La cuota se enlaza a la madre por la fila de arrastre.
            'invoice_type' => Invoice::TYPE_INSTALLATION,
            'number'       => $this->billing->generateInvoiceNumber((int) $mother->tenant_id),
            'issue_date'   => now()->startOfDay(),
            'due_date'     => $vence,
            'period_start' => $vence,
            'period_end'   => $vence,
            'currency'     => $mother->currency ?: 'COP',
            'subtotal'     => $monto,
            'tax'          => 0,
            'total'        => $monto,
            'balance_due'  => $monto,
            // Todo lo que cobra viene arrastrado de la madre: así lo cuenta el
            // estado de cuentas («deuda vieja recobrada») y no como facturación
            // nueva del mes.
            'carried_in'   => $monto,
            'status'       => Invoice::STATUS_ISSUED,
            'notes'        => "Cuota {$numero} de {$count} del acuerdo de pago de la instalación (factura #{$mother->number}).",
        ]);

        $fila = InvoiceCarryover::create([
            'tenant_id'          => $mother->tenant_id,
            'customer_id'        => $mother->customer_id,
            'from_invoice_id'    => $mother->id,
            'to_invoice_id'      => $factura->id,
            'payment_id'         => null,
            'amount'             => $monto,
            'status'             => InvoiceCarryover::STATUS_APPLIED,
            'applied_at'         => now(),
            'installment_number' => $numero,
            'installment_count'  => $count,
        ]);

        InvoiceItem::create([
            'invoice_id'  => $factura->id,
            'type'        => 'installment',
            'description' => InvoiceCarryover::installmentDescription($fila, $mother->number),
            'quantity'    => 1,
            'unit_price'  => $monto,
            'amount'      => $monto,
        ]);
    }

    /** Reescribe en su sitio la factura de una cuota que nadie ha pagado. */
    private function rewriteInstallmentInvoice(
        InvoiceCarryover $fila,
        Invoice $mother,
        int $numero,
        int $count,
        float $monto,
        Carbon $vence
    ): void {
        $fila->update([
            'amount'             => $monto,
            'installment_number' => $numero,
            'installment_count'  => $count,
        ]);

        $factura = $fila->toInvoice;
        $factura->items()->delete();

        InvoiceItem::create([
            'invoice_id'  => $factura->id,
            'type'        => 'installment',
            'description' => InvoiceCarryover::installmentDescription($fila, $mother->number),
            'quantity'    => 1,
            'unit_price'  => $monto,
            'amount'      => $monto,
        ]);

        $factura->fill([
            'due_date'     => $vence,
            'period_start' => $vence,
            'period_end'   => $vence,
            'subtotal'     => $monto,
            'tax'          => 0,
            'total'        => $monto,
            'balance_due'  => $monto,
            'carried_in'   => $monto,
            'notes'        => "Cuota {$numero} de {$count} del acuerdo de pago de la instalación (factura #{$mother->number}).",
        ]);

        // Intacta por definición (si no, estaría bloqueada): sin pagos, el
        // estado sólo depende del vencimiento.
        $factura->status = $vence->lt(now()->startOfDay()) ? Invoice::STATUS_OVERDUE : Invoice::STATUS_ISSUED;
        $factura->save();
    }
}
