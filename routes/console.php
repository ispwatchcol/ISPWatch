<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run daily — BillingService checks each router's billing.create_invoice day internally
Schedule::command('billing:generate-monthly')->daily();

// Failover: reintenta facturas que fallaron en la generación mensual.
// Backoff escalonado (2h/6h/24h) — corre cada hora pero solo procesa rows con next_retry_at vencido.
Schedule::command('billing:retry-failed')->hourly();

// Auto-cut: run every hour so it picks up routers whose cut_time has arrived
Schedule::command('billing:auto-cut')->hourly();

// Payment reminders: run daily — the service fires on each router's
// billing.payment_reminder day and is idempotent per billing cycle
Schedule::command('billing:send-reminders')->daily();

// Traffic history: sample WAN counters every 5 min for routers with
// historial_trafico on. withoutOverlapping so a slow run never stacks.
Schedule::command('traffic:collect')->everyFiveMinutes()->withoutOverlapping();

// Prune fine traffic samples older than 30 days (daily aggregates are kept).
Schedule::command('traffic:prune --days=30')->daily();

