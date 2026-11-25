<?php

namespace App\Support;

use App\Models\CustomerInstallation;
use App\Models\Invoice;
use App\Models\SupportTicket;

/**
 * Lo que se pidió para un acuerdo de pago, venga de donde venga.
 *
 * `InstallationInstallmentPlanner` nació atado a la orden de instalación (de
 * ahí leía modo, número de cuotas y primer vencimiento). Desde 2026-11-25 los
 * cargos de un ticket también se pueden diferir en cuotas, y el planificador
 * recibe esto en vez de la orden: la mecánica —cuotas como filas de
 * `invoice_carryovers` con `installment_number`, lo cobrado queda fijo, modo
 * mensual o en facturas aparte— es la misma para los dos.
 *
 * `concept` es cómo se nombra el origen en los renglones y notas de factura
 * («instalación», «ticket #12»), para que el cliente sepa qué está pagando.
 */
final class InstallmentPlan
{
    public function __construct(
        public readonly bool $wants,
        public readonly ?string $mode,
        public readonly int $count,
        public readonly ?string $firstDueDate,
        public readonly string $concept,
    ) {
    }

    public static function fromInstallation(CustomerInstallation $installation): self
    {
        return new self(
            wants: (bool) $installation->payment_agreement && !$installation->no_charge,
            mode: $installation->installments_mode,
            count: (int) ($installation->installments_count ?? 0),
            firstDueDate: $installation->installments_first_due_date
                ? (string) \Illuminate\Support\Carbon::parse($installation->installments_first_due_date)->toDateString()
                : null,
            concept: 'instalación',
        );
    }

    /**
     * Plan de un cargo de ticket. `count` 0 = sin acuerdo (se deshace lo que no
     * se haya cobrado). No hay columnas nuevas: el plan vigente se lee de sus
     * filas de cuota (ver `InstallationInstallmentPlanner::summary()`).
     */
    public static function forTicketCharge(SupportTicket $ticket, int $count, ?string $mode, ?string $firstDueDate): self
    {
        return new self(
            wants: $count > 0,
            mode: $mode,
            count: $count,
            firstDueDate: $firstDueDate,
            concept: "ticket #{$ticket->id}",
        );
    }

    /** Cómo se nombra el origen de una factura madre en los renglones de cuota. */
    public static function conceptFor(?Invoice $mother): string
    {
        if ($mother && $mother->invoice_type === Invoice::TYPE_SERVICE_CHARGE) {
            return $mother->ticket_id ? "ticket #{$mother->ticket_id}" : 'cargo';
        }

        return 'instalación';
    }
}
