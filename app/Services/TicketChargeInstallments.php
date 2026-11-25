<?php

namespace App\Services;

use App\Models\CustomerCredit;
use App\Models\CustomerInstallation;
use App\Models\Invoice;
use App\Models\InvoiceCarryover;
use App\Models\PaymentAllocation;
use App\Models\SupportTicket;
use App\Support\InstallmentPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Acuerdo de pago de un CARGO DE TICKET (2026-11-25).
 *
 * Misma mecánica que la cartera de la instalación —la ejecuta el mismo
 * `InstallationInstallmentPlanner`—: lo que se debe sale de la factura del
 * cargo por `carried_out` y espera en cuotas (`invoice_carryovers` con
 * `installment_number`). Dos modos, a elección de quien cobra:
 *
 *   monthly  → cada mensualidad cobra la siguiente cuota.
 *   separate → una factura aparte por cuota, con vencimientos mes a mes.
 *
 * Se puede cambiar después (número de cuotas, modo, primer vencimiento): lo
 * ya cobrado queda fijo y lo que falta se reparte. Las reglas de qué está
 * «cobrado» son las del planificador.
 *
 * NO hay columnas nuevas: el plan vigente se deduce de sus filas de cuota.
 * Por eso tampoco hay migración.
 */
class TicketChargeInstallments
{
    public function __construct(protected InstallationInstallmentPlanner $planner)
    {
    }

    /**
     * Deja el cargo `$mother` del ticket con el plan pedido. `$count` 0 quita
     * el acuerdo (lo no cobrado vuelve a la factura del cargo).
     *
     * @throws ValidationException
     */
    public function apply(Invoice $mother, SupportTicket $ticket, int $count, ?string $mode, ?string $firstDueDate, ?int $actorId = null): void
    {
        if ((int) $mother->ticket_id !== (int) $ticket->id || $mother->invoice_type !== Invoice::TYPE_SERVICE_CHARGE) {
            throw ValidationException::withMessages(['invoice' => 'Ese cobro no es un cargo de este ticket.']);
        }

        if (in_array($mother->status, ['void', 'cancelled'], true) || ($mother->estaAnulada() ?? false)) {
            throw ValidationException::withMessages(['invoice' => 'El cargo está anulado: no admite cuotas.']);
        }

        if ($count > 0 && !in_array($mode, CustomerInstallation::INSTALLMENT_MODES, true)) {
            throw ValidationException::withMessages([
                'installments_mode' => 'Elige cómo se cobran las cuotas: dentro de la mensualidad o en facturas aparte.',
            ]);
        }

        DB::transaction(function () use ($mother, $ticket, $count, $mode, $firstDueDate, $actorId) {
            $mother = Invoice::whereKey($mother->id)->lockForUpdate()->firstOrFail();
            $total  = round((float) $mother->total, 2);

            // Lo mismo que resta la cartera de la instalación antes de
            // financiar: pagos asignados, saldo a favor aplicado y faltantes de
            // abonos parciales. Lo que queda es lo que se puede diferir.
            $asignado = (float) PaymentAllocation::where('invoice_id', $mother->id)->sum('amount');
            $credito  = abs((float) CustomerCredit::where('to_invoice_id', $mother->id)
                ->where('type', CustomerCredit::TYPE_APPLIED)->sum('amount'));
            $faltantes = round((float) InvoiceCarryover::shortfalls()->where('from_invoice_id', $mother->id)->sum('amount'), 2);

            $debe = max(0.0, round($total - $asignado - $credito - $faltantes, 2));

            $financiado = $this->planner->sync(
                $mother,
                InstallmentPlan::forTicketCharge($ticket, $count, $mode, $firstDueDate),
                $debe,
                $actorId,
            );

            $mother->carried_out = round($faltantes + $financiado, 2);
            $mother->balance_due = max(0.0, round($debe - $financiado, 2));
            $mother->status = match (true) {
                $total <= 0 || $mother->balance_due <= 0      => 'paid',
                $mother->balance_due + 0.009 < $total          => 'partial',
                $mother->status === 'overdue'                  => 'overdue',
                default                                        => 'issued',
            };
            $mother->save();
        });
    }

    /** Plan vigente del cargo, o null si no tiene cuotas. */
    public function summary(Invoice $mother): ?array
    {
        return $this->planner->summary($mother);
    }
}
