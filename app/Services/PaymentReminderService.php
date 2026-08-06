<?php

namespace App\Services;

use App\Mail\PaymentReminderMail;
use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Router;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends payment reminders automatically on each router's configured
 * billing.payment_reminder day-of-month.
 *
 * Runs daily (scheduler); the day-of-month gate + per-cycle idempotency
 * (invoices.last_reminder_sent) live here so a missed cron still recovers
 * without spamming customers.
 */
class PaymentReminderService
{
    public function __construct(protected WhatsAppService $whatsAppService)
    {
    }

    /**
     * @param int|null $routerId Limit processing to a specific router (null = all).
     * @return array{reminded:int, invoices_included:int, errors:int, routers_processed:int, skipped_not_due:int}
     *         'reminded' cuenta CLIENTES avisados (un mensaje cada uno);
     *         'invoices_included' cuenta las facturas que iban dentro.
     */
    public function sendDueReminders(?int $routerId = null): array
    {
        $stats = [
            'reminded'          => 0,
            'invoices_included' => 0,
            'errors'            => 0,
            'routers_processed' => 0,
            'skipped_not_due'   => 0,
        ];

        $now = Carbon::now();

        $routerQuery = Router::with('billingConfig')
            ->whereNotNull('billing_router_id');

        if ($routerId !== null) {
            $routerQuery->where('id', $routerId);
        }

        $routers = $routerQuery->get();

        Log::info("Reminders: checking {$routers->count()} router(s) with billing config.");

        foreach ($routers as $router) {
            $config = $router->billingConfig;
            if (!$config) {
                continue;
            }

            // Respect the per-router enable flag (UI toggle "Recordatorio de pago").
            if (!$config->payment_reminder_enabled) {
                continue;
            }

            // Clamp the configured reminder day to this month's length.
            $reminderDay = Billing::clampDayToMonth(
                Billing::dayOf($config->payment_reminder),
                $now
            );

            if ($reminderDay === null) {
                continue; // reminder not configured for this router
            }

            if ($now->day < $reminderDay) {
                $stats['skipped_not_due']++;
                continue; // not yet — will fire on/after the configured day
            }

            // Gate on the configured hour-of-day. The scheduler runs this
            // command hourly; default '00:00:00' keeps the date-only behaviour
            // (fire at the first run of the day). Mirrors the invoice/cut gates.
            $reminderMoment = Billing::applyTimeOfDay($now, $config->payment_reminder_time);
            if ($now->lt($reminderMoment)) {
                $stats['skipped_not_due']++;
                continue; // day reached but the configured hour hasn't arrived yet
            }

            $stats['routers_processed']++;

            // exclude_from_billing: clientes "no facturar" no reciben recordatorios.
            // Sólo se avisa a los ACTIVOS: al que ya está cortado el recordatorio
            // llega tarde (el corte es el aviso) y se le seguirían mandando
            // mensajes cada ciclo. Sus facturas sí se siguen emitiendo hasta el
            // tope — ver BillingService::generateMonthlyInvoices.
            // notify_invoice=false: cliente con notificaciones silenciadas; sus
            // facturas y recordatorios se calculan igual, sólo no se le avisa.
            $profiles = CustomerProfile::where('router_id', $router->id)
                ->where('status', true)
                ->where('exclude_from_billing', false)
                ->where('notify_invoice', true)
                ->get();

            foreach ($profiles as $profile) {
                // Outstanding invoices not yet reminded for their cycle.
                $invoices = Invoice::where('customer_id', $profile->user_id)
                    ->where('balance_due', '>', 0)
                    ->whereNotIn('status', ['paid', 'void', 'cancelled'])
                    ->orderBy('due_date')
                    ->get()
                    ->filter(function (Invoice $inv) {
                        // Skip if a reminder already went out for this cycle.
                        return $inv->last_reminder_sent === null
                            || $inv->last_reminder_sent->lt($inv->period_start);
                    })
                    ->values();

                if ($invoices->isEmpty()) {
                    continue;
                }

                // UN solo aviso por cliente con TODAS sus facturas pendientes.
                // Antes salía un mensaje por factura: al que debía tres le
                // llegaban tres correos/WhatsApps seguidos.
                try {
                    $this->remind($invoices, $profile, $router, $config);

                    foreach ($invoices as $invoice) {
                        $invoice->last_reminder_sent = $now;
                        $invoice->save();
                    }

                    $stats['reminded']++;
                    $stats['invoices_included'] += $invoices->count();
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::error("Reminders: failed for customer {$profile->user_id}: {$e->getMessage()}");
                }
            }
        }

        Log::info('Reminders: complete.', $stats);

        return $stats;
    }

    /**
     * Envía UN aviso con todas las facturas pendientes del cliente.
     *
     * La plantilla de WhatsApp aprobada en Meta tiene parámetros fijos
     * (nombre, factura, monto, vencimiento, empresa), así que el consolidado se
     * resume ahí: número de la más antigua + "y N más", monto = total adeudado
     * y vencimiento = el más antiguo. El correo sí lista factura por factura.
     *
     * @param \Illuminate\Support\Collection<int,Invoice> $invoices Ordenadas por vencimiento (la más antigua primero).
     */
    private function remind($invoices, CustomerProfile $profile, Router $router, Billing $config): void
    {
        /** @var Invoice $oldest */
        $oldest   = $invoices->first();
        $customer = $oldest->customer; // User
        if (!$customer) {
            throw new \RuntimeException("Invoice {$oldest->id} has no customer.");
        }

        $now       = Carbon::now();
        $totalDue  = (float) $invoices->sum(fn (Invoice $inv) => (float) ($inv->balance_due ?? $inv->total));
        $isOverdue = $invoices->contains(
            fn (Invoice $inv) => $inv->status === 'overdue'
                || ($inv->due_date && Carbon::parse($inv->due_date)->lt($now))
        );

        $label = $invoices->count() > 1
            ? "{$oldest->number} y " . ($invoices->count() - 1) . ' más'
            : $oldest->number;

        $data = [
            'customer_name'  => trim("{$profile->name} {$profile->last_name}") ?: ($customer->name ?? 'Cliente'),
            'invoice_number' => $label,
            'amount'         => $totalDue,
            'due_date'       => $oldest->due_date,
            'company_name'   => $oldest->tenant?->name ?? 'ISPWatch',
            'is_overdue'     => $isOverdue,
            'invoice_count'  => $invoices->count(),
            // Detalle para el correo: una fila por factura pendiente.
            'invoices'       => $invoices->map(fn (Invoice $inv) => [
                'number'      => $inv->number,
                'amount'      => (float) ($inv->balance_due ?? $inv->total),
                'due_date'    => $inv->due_date,
                'period_start'=> $inv->period_start,
                'is_overdue'  => $inv->status === 'overdue'
                    || ($inv->due_date && Carbon::parse($inv->due_date)->lt($now)),
            ])->all(),
        ];

        $type = $config->notification_type ?: 'email';

        if (in_array($type, ['email', 'both'], true) && $customer->email) {
            Mail::to($customer->email)->send(new PaymentReminderMail($data));
        }

        if (in_array($type, ['whatsapp', 'both'], true)) {
            $phone = $profile->phone ?? $customer->tel ?? null;
            if ($phone && $this->whatsAppService->isConfigured()) {
                $this->whatsAppService->sendPaymentReminder($phone, $data);
            }
        }
    }
}
