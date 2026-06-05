<?php

namespace App\Console\Commands;

use App\Mail\InvoiceCreatedMail;
use App\Mail\PaymentReminderMail;
use App\Models\Billing;
use App\Models\Invoice;
use App\Models\Router;
use App\Services\BillingService;
use App\Services\OverdueSuspensionService;
use App\Services\PaymentReminderService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Time-travels through a billing month for one router so the operator can
 * verify the full automation end-to-end without waiting real days.
 *
 *   php artisan billing:simulate --router=47
 *   php artisan billing:simulate --router=47 --year=2026 --month=5
 *   php artisan billing:simulate --router=47 --apply   # commit + real side effects
 *
 * Default mode is DRY-RUN: all DB writes are rolled back, mail is faked,
 * the MikroTik suspension SSH call is skipped (only logged). Pass --apply
 * to commit invoices, send real emails, and trigger real suspensions.
 */
class SimulateBillingFlow extends Command
{
    protected $signature = 'billing:simulate
                            {--router= : ID del router (requerido)}
                            {--year= : Año (default: año actual)}
                            {--month= : Mes 1-12 (default: mes actual)}
                            {--apply : Ejecuta de verdad (sin --apply es dry-run)}';

    protected $description = 'Simula el flujo de facturación completo (crear factura → recordatorio → corte) para un router específico, viajando en el tiempo.';

    public function __construct(
        protected BillingService $billingService,
        protected PaymentReminderService $reminderService,
        protected OverdueSuspensionService $suspensionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $routerId = (int) $this->option('router');
        if (!$routerId) {
            $this->error('--router=<id> es requerido.');
            return Command::FAILURE;
        }

        $now    = Carbon::now();
        $year   = (int) ($this->option('year')  ?? $now->year);
        $month  = (int) ($this->option('month') ?? $now->month);
        $apply  = (bool) $this->option('apply');

        $router = Router::with('billingConfig')->find($routerId);
        if (!$router) {
            $this->error("Router #{$routerId} no existe.");
            return Command::FAILURE;
        }
        $b = $router->billingConfig;
        if (!$b) {
            $this->error("Router #{$routerId} no tiene billing config (billing_router_id es null).");
            return Command::FAILURE;
        }

        $this->printHeader($router, $b, $year, $month, $apply);

        if (!$apply) {
            Mail::fake();
            DB::beginTransaction();
            $this->warn('🧪 DRY-RUN activo: nada se guarda y no se envían correos reales. Usa --apply para ejecutar.');
            $this->newLine();
        }

        try {
            // ── PHASE 1: Crear factura ───────────────────────────────
            $createDay = Billing::clampDayToMonth(Billing::dayOf($b->create_invoice), Carbon::create($year, $month, 1));
            if ($createDay === null) {
                $this->warn('• No hay create_invoice configurado — se omite la fase de creación.');
            } else {
                $createTime = $b->create_invoice_time ?: '00:00:00';
                [$ch, $cm, $cs] = array_pad(explode(':', $createTime), 3, 0);

                $this->phase("📄 PHASE 1: Crear factura — Día {$createDay} a las {$createTime}");
                // Travel to just after the configured create hour so the new
                // hour gate in generateMonthlyInvoices() lets the run through.
                Carbon::setTestNow(Carbon::create($year, $month, $createDay, (int) $ch, (int) $cm, (int) $cs)->addMinute());
                $this->line("   Reloj: " . Carbon::now()->toDateTimeString());

                $created = $this->billingService->generateMonthlyInvoices(null, $routerId);
                $this->line("   ✓ Facturas creadas: <fg=green>{$created}</>");
                $this->reportMails(InvoiceCreatedMail::class, 'InvoiceCreatedMail (notificación al crear)');
            }

            // ── PHASE 2: Recordatorio ────────────────────────────────
            $remDay = Billing::clampDayToMonth(Billing::dayOf($b->payment_reminder), Carbon::create($year, $month, 1));
            if ($remDay === null) {
                $this->warn('• No hay payment_reminder configurado — se omite la fase de recordatorio.');
            } elseif (!$b->payment_reminder_enabled) {
                $this->warn("• payment_reminder_enabled = false — se omite (el toggle del UI está OFF).");
            } else {
                $remTime = $b->payment_reminder_time ?: '00:00:00';
                [$rh, $rm, $rs] = array_pad(explode(':', $remTime), 3, 0);

                $this->phase("📣 PHASE 2: Recordatorio — Día {$remDay} a las {$remTime}");
                // Travel to just after the configured reminder hour so the new
                // hour gate in sendDueReminders() lets the run through.
                Carbon::setTestNow(Carbon::create($year, $month, $remDay, (int) $rh, (int) $rm, (int) $rs)->addMinute());
                $this->line("   Reloj: " . Carbon::now()->toDateTimeString());

                $stats = $this->reminderService->sendDueReminders($routerId);
                $this->line("   ✓ Recordatorios enviados: <fg=green>{$stats['reminded']}</>");
                $this->line("     Routers procesados: {$stats['routers_processed']} · Errores: {$stats['errors']}");
                $this->reportMails(PaymentReminderMail::class, 'PaymentReminderMail (recordatorio)');
            }

            // ── PHASE 3: Corte ───────────────────────────────────────
            $cutDay = Billing::clampDayToMonth(Billing::dayOf($b->cut_day), Carbon::create($year, $month, 1));
            if ($cutDay === null) {
                $this->warn('• No hay cut_day configurado — se omite la fase de corte.');
            } else {
                $cutTime = $b->cut_time ?: '00:05:00';
                [$h, $m, $s] = array_pad(explode(':', $cutTime), 3, 0);

                $this->phase("✂️  PHASE 3: Corte automático — Día {$cutDay} a las {$cutTime}");
                Carbon::setTestNow(Carbon::create($year, $month, $cutDay, (int) $h, (int) $m, (int) $s)->addMinute());
                $this->line("   Reloj: " . Carbon::now()->toDateTimeString());

                if ($apply) {
                    $stats = $this->suspensionService->processOverdueInvoices($routerId);
                    $this->line("   ✓ Suspendidos: <fg=red>{$stats['suspended']}</> · Errores: {$stats['errors']}");
                } else {
                    // En dry-run NO llamamos al servicio porque haría SSH a MikroTik.
                    // En su lugar reportamos quiénes serían candidatos.
                    $candidates = $this->countSuspensionCandidates($routerId, $b);
                    $this->line("   <fg=yellow>(dry-run)</> Candidatos a corte: <fg=red>{$candidates}</> (no se ejecutó SSH a MikroTik).");
                    $this->line("   Para corte real corre: <fg=cyan>php artisan billing:auto-cut --router={$routerId}</>");
                }
            }

            // ── RESUMEN FINAL ────────────────────────────────────────
            $this->newLine();
            $this->printInvoiceSummary($routerId, $year, $month);
        } finally {
            if (!$apply) {
                DB::rollBack();
                $this->newLine();
                $this->info('🔁 DRY-RUN terminado: todos los cambios fueron revertidos (rollback).');
            }
            Carbon::setTestNow(); // ALWAYS restore the real clock
        }

        return Command::SUCCESS;
    }

    private function printHeader(Router $router, Billing $b, int $year, int $month, bool $apply): void
    {
        $modeLabel = ($b->billing_mode === Billing::MODE_VENCIDO) ? 'Vencido (mes anterior)' : 'Anticipado (mes en curso)';
        $monthName = Carbon::create($year, $month, 1)->locale('es')->isoFormat('MMMM YYYY');

        $this->info("═══════════════════════════════════════════════════════════════");
        $this->info("  SIMULACIÓN DE FACTURACIÓN");
        $this->info("═══════════════════════════════════════════════════════════════");
        $this->table(
            ['Campo', 'Valor'],
            [
                ['Router',                   "#{$router->id} — {$router->name}"],
                ['Período simulado',         $monthName],
                ['Modo de facturación',      $modeLabel],
                ['Crear factura',            'Día ' . (Billing::dayOf($b->create_invoice) ?? '—') . ' @ ' . ($b->create_invoice_time ?: '00:00:00')],
                ['Día límite de pago',       'Día ' . (Billing::dayOf($b->payment_day)    ?? '—')],
                ['Recordatorio',             'Día ' . (Billing::dayOf($b->payment_reminder) ?? '—') . ' @ ' . ($b->payment_reminder_time ?: '00:00:00') . ($b->payment_reminder_enabled ? ' (activo)' : ' (DESACTIVADO)')],
                ['Día de corte',             'Día ' . (Billing::dayOf($b->cut_day) ?? '—') . ' @ ' . ($b->cut_time ?: '00:00:00')],
                ['Suspender tras N vencidas', $b->overdue_invoices ?? 1],
                ['Tipo de notificación',     $b->notification_type ?: 'email'],
                ['Modo de ejecución',        $apply ? '⚠️  APPLY (cambios reales)' : '🧪 DRY-RUN (rollback al final)'],
            ]
        );
    }

    private function phase(string $title): void
    {
        $this->newLine();
        $this->line("<fg=cyan>{$title}</>");
        $this->line(str_repeat('─', 60));
    }

    private function reportMails(string $mailable, string $label): void
    {
        if ($this->option('apply')) {
            $this->line("   (apply: correos reales enviados — revisa la bandeja del cliente)");
            return;
        }
        $sent = collect(Mail::sent($mailable));
        $count = $sent->count();
        $this->line("   📧 {$label}: <fg=green>{$count}</>");

        // Print at most 5 addresses, then a summary line if more.
        $shown = $sent->take(5);
        $shown->each(function ($m) {
            $to = $m->to[0]['address'] ?? '?';
            $this->line("      → {$to}");
        });
        if ($count > 5) {
            $remaining = $count - 5;
            $this->line("      → ... y {$remaining} más");
        }
    }

    private function countSuspensionCandidates(int $routerId, Billing $b): int
    {
        $maxOverdue = max(1, (int) ($b->overdue_invoices ?? 1));
        $profiles = \App\Models\CustomerProfile::where('router_id', $routerId)
            ->where('status', true)
            ->get();

        return $profiles->filter(function ($p) use ($maxOverdue) {
            $count = Invoice::where('customer_id', $p->user_id)
                ->where('due_date', '<', Carbon::now())
                ->where('balance_due', '>', 0)
                ->whereNotIn('status', ['void', 'cancelled', 'paid'])
                ->count();
            return $count >= $maxOverdue;
        })->count();
    }

    private function printInvoiceSummary(int $routerId, int $year, int $month): void
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = $start->copy()->endOfMonth();

        $invoices = Invoice::whereHas('customer.customerProfile', fn ($q) => $q->where('router_id', $routerId))
            ->where('issue_date', '>=', $start)
            ->where('issue_date', '<=', $end)
            ->get();

        if ($invoices->isEmpty()) {
            $this->warn('• No se encontraron facturas para este router en el mes simulado.');
            return;
        }

        $this->line("<fg=cyan>📋 Resumen de facturas del router en el mes simulado:</>");
        $rows = $invoices->map(fn (Invoice $inv) => [
            $inv->number,
            $inv->customer_id,
            Carbon::parse($inv->period_start)->format('Y-m-d') . ' → ' . Carbon::parse($inv->period_end)->format('Y-m-d'),
            Carbon::parse($inv->issue_date)->format('Y-m-d'),
            Carbon::parse($inv->due_date)->format('Y-m-d'),
            number_format((float) $inv->total, 0, ',', '.'),
            $inv->status,
        ])->all();

        $this->table(
            ['#', 'Cliente', 'Período cubierto', 'Emisión', 'Vence', 'Total', 'Estado'],
            $rows
        );
    }
}
