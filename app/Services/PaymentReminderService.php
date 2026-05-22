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
     * @return array{reminded:int, errors:int, routers_processed:int, skipped_not_due:int}
     */
    public function sendDueReminders(?int $routerId = null): array
    {
        $stats = [
            'reminded'          => 0,
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

            $stats['routers_processed']++;

            $profiles = CustomerProfile::where('router_id', $router->id)
                ->where('status', true)
                ->get();

            foreach ($profiles as $profile) {
                // Outstanding invoices not yet reminded for their cycle.
                $invoices = Invoice::where('customer_id', $profile->user_id)
                    ->where('balance_due', '>', 0)
                    ->whereNotIn('status', ['paid', 'void', 'cancelled'])
                    ->get()
                    ->filter(function (Invoice $inv) {
                        // Skip if a reminder already went out for this cycle.
                        return $inv->last_reminder_sent === null
                            || $inv->last_reminder_sent->lt($inv->period_start);
                    });

                foreach ($invoices as $invoice) {
                    try {
                        $this->remind($invoice, $profile, $router, $config);
                        $invoice->last_reminder_sent = $now;
                        $invoice->save();
                        $stats['reminded']++;
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        Log::error("Reminders: failed for invoice {$invoice->id}: {$e->getMessage()}");
                    }
                }
            }
        }

        Log::info('Reminders: complete.', $stats);

        return $stats;
    }

    private function remind(Invoice $invoice, CustomerProfile $profile, Router $router, Billing $config): void
    {
        $customer = $invoice->customer; // User
        if (!$customer) {
            throw new \RuntimeException("Invoice {$invoice->id} has no customer.");
        }

        $data = [
            'customer_name'  => trim("{$profile->name} {$profile->last_name}") ?: ($customer->name ?? 'Cliente'),
            'invoice_number' => $invoice->number,
            'amount'         => $invoice->balance_due ?? $invoice->total,
            'due_date'       => $invoice->due_date,
            'company_name'   => $invoice->tenant?->name ?? 'ISPWatch',
            'is_overdue'     => $invoice->status === 'overdue',
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
