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

// Detección de no-show: audita que la facturación mensual realmente ocurrió y
// alerta (log + email) si un router que debía facturar generó 0 o quedó incompleto.
// Cubre el punto ciego que el failover NO ve: un router saltado o un job que nunca corrió
// no dejan rastro en billing_action_logs. Corre tras el generate (00:00) y varios retries.
Schedule::command('billing:verify-monthly')->dailyAt('06:00');

// Auto-cut: run every hour so it picks up routers whose cut_time has arrived
Schedule::command('billing:auto-cut')->hourly();

// Failover de cortes: reconcilia DB ⇄ RB. Re-corta en el router a los clientes
// suspendidos en la DB cuyo corte no quedó confirmado (con backoff por cliente).
// Corre tras el auto-cut para recoger lo que haya fallado.
Schedule::command('billing:reconcile-suspensions')->hourly();

// Detección de no-show de cortes: alerta (log + email) si un router de Corte
// Automático está mal configurado (sin cut_day) o dejó clientes morosos sin cortar
// pese a haber pasado el día/hora de corte. Análogo a billing:verify-monthly.
Schedule::command('billing:verify-cuts')->dailyAt('07:00');

// Payment reminders: run daily — the service fires on each router's
// billing.payment_reminder day and is idempotent per billing cycle
Schedule::command('billing:send-reminders')->daily();

// Traffic history: sample WAN counters every 5 min for routers with
// historial_trafico on. withoutOverlapping so a slow run never stacks.
Schedule::command('traffic:collect')->everyFiveMinutes()->withoutOverlapping();

// Prune fine traffic samples older than 30 days (daily aggregates are kept).
Schedule::command('traffic:prune --days=30')->daily();

