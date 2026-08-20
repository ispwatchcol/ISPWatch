<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run hourly — BillingService gates each router on its billing.create_invoice
// DAY and create_invoice_time HOUR internally, so the operator can pick the hour
// invoices go out. Generation is idempotent (skips invoices that already exist),
// so the extra hourly runs are cheap no-ops once a router has billed.
// withoutOverlapping guards against a long run (many invoices + notifications)
// stacking with the next tick.
Schedule::command('billing:generate-monthly')->hourly()->withoutOverlapping();

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

// Auditoría de caja: comprueba que todo el dinero recibido siga respaldando una
// factura o un saldo a favor. Cubre otro punto ciego — verify-monthly mira que
// las facturas se generen y verify-cuts que se corte a quien debe, pero que el
// dinero YA COBRADO siga cuadrando no lo miraba nadie. Se descuadra al eliminar
// una factura ya pagada sin reaplicar el saldo que devuelve.
// Tras las otras dos auditorías: si la mensual no corrió, eso se avisa primero.
Schedule::command('billing:verify-orphan-payments')->dailyAt('08:00');

// Salud del túnel por router (handshake WireGuard / sesión L2TP). Cubre el
// punto ciego que dejó a CORE_TOCAIMA 8 días caído sin que nada avisara: el
// failover de cortes solo ve fallos POR CLIENTE, nunca "este router no está".
// Cada 30 min: un túnel caído hay que saberlo en minutos, no al día siguiente.
Schedule::command('vpn:verify-tunnels')->everyThirtyMinutes()->withoutOverlapping();

// Payment reminders: run hourly — the service fires on each router's
// billing.payment_reminder DAY at its payment_reminder_time HOUR and is
// idempotent per billing cycle (invoices.last_reminder_sent), so the extra
// hourly runs never double-send.
Schedule::command('billing:send-reminders')->hourly()->withoutOverlapping();

// Traffic history: sample WAN counters every 5 min for routers with
// historial_trafico on. withoutOverlapping so a slow run never stacks.
Schedule::command('traffic:collect')->everyFiveMinutes()->withoutOverlapping();

// Prune fine traffic samples older than 30 days (daily aggregates are kept).
Schedule::command('traffic:prune --days=30')->daily();

// Recordatorio de contratos sin firmar: UN solo aviso por link, a las 24h de
// haberse enviado. A las 09:00 y no de madrugada porque el correo compite con
// la atención del cliente, no con la del servidor.
Schedule::command('contracts:remind-unsigned')->dailyAt('09:00');

// Bitácora de la API pública: se conserva la ventana de auditoría configurada
// en config/api_keys.php (90 días por defecto). Sin esta purga la tabla crece
// sin techo, porque se escribe una fila por petición atendida o rechazada.
Schedule::command('api-keys:prune-logs')->dailyAt('03:30');

// Latido del planificador. Cada minuto, y a propósito lo primero que se agenda
// en importancia: es lo único que permite detectar que ESTE proceso dejó de
// correr. Sin él, un scheduler caído solo se descubre a fin de mes, cuando no
// hay facturas. Lo consulta /health y, a través suyo, el centinela externo.
//
// Sin withoutOverlapping: el bloqueo vive en el caché (que es la base de datos),
// y un latido que necesita adquirir un lock para latir se pierde justo cuando la
// base empieza a ir mal — que es cuando más falta hace saberlo.
Schedule::command('system:heartbeat')->everyMinute();

