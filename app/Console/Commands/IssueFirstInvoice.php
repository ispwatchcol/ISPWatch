<?php

namespace App\Console\Commands;

use App\Models\CustomerProfile;
use App\Services\BillingService;
use Illuminate\Console\Command;

/**
 * Emite a mano la primera factura de un cliente (la mensualidad prorrateada del
 * mes en que se instaló).
 *
 * Existe para los clientes dados de alta ANTES de que el alta emitiera su
 * factura sola, y para el router al que le falta el día de facturación: en
 * ambos casos la corrida mensual no los alcanza y el cliente queda con la
 * factura de instalación cobrada y la del servicio sin emitir.
 *
 * Usa exactamente la misma vía que el alta (BillingService::issueFirstInvoiceOnSignup),
 * así que es idempotente: si el mes ya tiene mensualidad, no hace nada.
 */
class IssueFirstInvoice extends Command
{
    protected $signature = 'billing:first-invoice {customer* : ID(s) de usuario del cliente}';

    protected $description = 'Emite la primera factura (prorrateo del mes en curso) de uno o varios clientes ya creados';

    public function __construct(protected BillingService $billing)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $issued = 0;

        foreach ($this->argument('customer') as $customerId) {
            $profile = CustomerProfile::with('user')->find((int) $customerId);

            if (!$profile) {
                $this->error("Cliente {$customerId}: no existe.");
                continue;
            }

            $invoice = $this->billing->issueFirstInvoiceOnSignup($profile);

            if (!$invoice) {
                // El motivo exacto queda en el log de facturación: aquí se
                // resume para que el operador no tenga que ir a buscarlo.
                $this->warn("Cliente {$customerId} ({$profile->name} {$profile->last_name}): "
                    . 'no correspondía emitir factura. Revisa storage/logs por "primera factura al alta omitida".');
                continue;
            }

            $issued++;
            $this->info("Cliente {$customerId} ({$profile->name} {$profile->last_name}): "
                . "factura {$invoice->number} por \${$invoice->total} — {$invoice->period_start->format('Y-m-d')} a {$invoice->period_end->format('Y-m-d')}.");
        }

        $this->line("Facturas emitidas: {$issued}.");

        return self::SUCCESS;
    }
}
