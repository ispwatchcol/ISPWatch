<?php

namespace App\Services;

use App\Billing\FirstInvoicePolicy;
use App\Mail\InvoiceCreatedMail;
use App\Models\Billing;
use App\Models\BillingActionLog;
use App\Models\CustomerAdditionalService;
use App\Models\CustomerCredit;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceCarryover;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Plan;
use App\Models\Router;
use App\Models\SuspensionActionLog;
use App\Models\User;
use App\Models\UserService;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BillingService
{
    public function __construct(protected WhatsAppService $whatsAppService)
    {
    }

    /**
     * Get the active service plan for a customer via user_services.
     *
     * @param int $userId
     * @return \App\Models\Plan|null
     */
    public function getActivePlan(int $userId)
    {
        $activeService = UserService::with('servicePlan')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        return $activeService?->servicePlan;
    }

    /**
     * Generate the next invoice number for a tenant (concurrency-safe).
     *
     * @param int $tenantId
     * @return string
     */
    public function generateInvoiceNumber(int $tenantId): string
    {
        return DB::transaction(function () use ($tenantId) {
            $tenant = Tenant::where('id', $tenantId)->lockForUpdate()->first();

            if (!$tenant) {
                throw new \Exception("Tenant not found: {$tenantId}");
            }

            $nextNumber = $tenant->next_invoice_number ?? 1;
            $invoiceNumber = str_pad($nextNumber, 8, '0', STR_PAD_LEFT);

            // Increment for next invoice
            $tenant->next_invoice_number = $nextNumber + 1;
            $tenant->save();

            return $invoiceNumber;
        });
    }

    /**
     * Generate monthly invoices based on each router's billing configuration.
     *
     * For each router that has a billing config (billing_router_id):
     *   1. Check if today's day-of-month >= the billing's create_invoice day
     *   2. Find all billable customers assigned to that router (activos, gratis
     *      y cortados por mora; nunca retirados/cancelados ni "no facturar")
     *   3. Create an invoice for each customer with an active (non-gratis) service plan
     *
     * Tope de facturación: el cliente que ya acumula
     * (billing.overdue_invoices + billing.stop_invoicing_extra) facturas
     * pendientes deja de recibir mensualidades — la deuda se congela ahí.
     *
     * Idempotent: safe to run multiple times — duplicate invoices are skipped.
     *
     * The covered period depends on each router's billing_mode:
     *   - 'anticipado' (default): the month the job runs (cobro adelantado)
     *   - 'vencido'             : the previous month (cobro vencido)
     * An explicit $period overrides the mode for ALL routers (manual backfill).
     *
     * @param string|null $period   Format: YYYY-MM. Null = derive per router.
     * @param int|null    $routerId Limit to a specific router (null = all). Used by the
     *                              simulator/manual ops to focus a single tenant.
     * @return int Number of invoices created
     */
    public function generateMonthlyInvoices(?string $period = null, ?int $routerId = null): int
    {
        $periodExplicit = $period !== null;
        $today          = now();
        $created        = 0;

        // ── Iterate routers that have a billing config ──────────────────────
        $routerQuery = Router::with(['billingConfig', 'customers'])
            ->whereNotNull('billing_router_id');

        if ($routerId !== null) {
            $routerQuery->where('id', $routerId);
        }

        $routers = $routerQuery->get();

        Log::info("Billing: Checking {$routers->count()} router(s) with billing config.");

        foreach ($routers as $router) {
            $billingConfig = $router->billingConfig;
            if (!$billingConfig) {
                continue;
            }

            // ── Check create_invoice day (clamped to this month's length) ───
            // A configured day 31 becomes 30 in April / 28 in February so
            // "último día" configs still fire; other days stay as set.
            $rawCreateDay = Billing::dayOf($billingConfig->create_invoice);
            $createDay    = Billing::clampDayToMonth($rawCreateDay, $today);

            if ($createDay === null) {
                Log::info("Billing: Router {$router->id} ({$router->name}) has no create_invoice day. Skipping.");
                continue;
            }

            // Only generate if today's day >= the (clamped) creation day.
            // This allows recovery if the system was down on the exact day.
            if ($today->day < $createDay) {
                Log::info("Billing: Router {$router->id} ({$router->name}) — create day is {$createDay}, today is {$today->day}. Not yet.");
                continue;
            }

            // ── Check create_invoice_time (hour of day) ─────────────────────
            // The scheduler runs this command hourly; gate on the configured
            // time exactly like the auto-cut does, so the operator can pick the
            // hour invoices go out. Default '00:00:00' = fire at the first run
            // of the day (unchanged date-only behaviour). An explicit $period
            // (manual backfill) bypasses the hour gate — the operator asked for
            // it right now.
            if (!$periodExplicit) {
                $createDateTime = Billing::applyTimeOfDay($today, $billingConfig->create_invoice_time);
                if ($today->lt($createDateTime)) {
                    Log::info("Billing: Router {$router->id} ({$router->name}) — create time is "
                        . ($billingConfig->create_invoice_time ?: '00:00:00')
                        . ", current time is {$today->format('H:i:s')}. Not yet.");
                    continue;
                }
            }

            // ── Resolve the period this invoice covers ──────────────────────
            if ($periodExplicit) {
                $periodMonth = Carbon::parse($period . '-01');
            } else {
                $mode = $billingConfig->billing_mode ?: Billing::MODE_ANTICIPADO;
                $periodMonth = $mode === Billing::MODE_VENCIDO
                    ? $today->copy()->subMonthNoOverflow()
                    : $today->copy();
            }
            $periodStart = $periodMonth->copy()->startOfMonth()->startOfDay();
            $periodEnd   = $periodMonth->copy()->endOfMonth()->startOfDay();

            // ── Determine due date from payment_day config ──────────────────
            $dueDay = $billingConfig->payment_day
                ? Carbon::parse($billingConfig->payment_day)->day
                : null;

            $issueDate = $today->copy()->startOfDay();

            if ($dueDay !== null) {
                // Clamp due day to last day of current month
                $lastDayOfMonth = $today->copy()->endOfMonth()->day;
                $clampedDueDay  = min($dueDay, $lastDayOfMonth);
                $dueDate = $today->copy()->setDay($clampedDueDay)->startOfDay();
                // If due date is before issue date, push to next month
                if ($dueDate->lt($issueDate)) {
                    $dueDate = $dueDate->addMonth();
                }
            } else {
                $dueDate = $issueDate->copy()->addDays(5);
            }

            // ── Tope de facturación del router (null = sin tope) ────────────
            $stopAt = $billingConfig->invoiceStopThreshold();

            // ── Get billable customers assigned to this router ──────────────
            // Se factura a activos, gratis y CORTADOS por mora: el corte no
            // congela la deuda, la frena el tope de más abajo. Sólo quedan
            // fuera las bajas definitivas (retirado / cancelado).
            // exclude_from_billing = clientes marcados como "no facturar": quedan
            // fuera del ciclo automático (sin factura, recordatorio ni corte).
            $customerProfiles = CustomerProfile::where('router_id', $router->id)
                ->billableServiceStatus()
                ->where('exclude_from_billing', false)
                ->with('user:id,created_at')
                ->get();

            Log::info("Billing: Router {$router->id} ({$router->name}) — {$customerProfiles->count()} billable customer(s) to check.");

            foreach ($customerProfiles as $profile) {
                $customerId = $profile->user_id;

                // Find the customer's active (billable) service
                $userService = UserService::where('user_id', $customerId)
                    ->where('status', UserService::STATUS_ACTIVE)
                    ->with('servicePlan')
                    ->first();

                // ── Sin plan que cobrar, pero cliente vigente ───────────────
                // Los tres saltos de aquí abajo comparten un matiz: el sistema
                // no deja de facturarle porque NO DEBA cobrarle (eso ya se
                // filtró arriba: excluidos, retirados), sino porque no hay plan
                // que cobrarle. Si además tiene servicios adicionales activos
                // —el alquiler del router de un empleado con plan de cortesía,
                // por ejemplo— se le emite una factura sólo con ellos.
                $servicePlan = $userService?->servicePlan;

                $sinPlanQueCobrar = !$userService || !$servicePlan || $servicePlan->is_courtesy;

                if ($sinPlanQueCobrar) {
                    $motivo = match (true) {
                        !$userService => 'no active service',
                        !$servicePlan => 'active service has no plan',
                        default       => "courtesy plan '{$servicePlan->name}'",
                    };

                    $extra = $this->issueAdditionalOnlyInvoice(
                        router:        $router,
                        profile:       $profile,
                        billingConfig: $billingConfig,
                        issueDate:     $issueDate,
                        dueDate:       $dueDate,
                        periodStart:   $periodStart,
                        periodEnd:     $periodEnd,
                        stopAt:        $stopAt,
                    );

                    if ($extra) {
                        $created++;
                        Log::info("Billing: Customer {$customerId} — {$motivo}, pero tiene servicios adicionales: "
                            . "se emite la factura {$extra->number} sólo con ellos.");
                    } else {
                        Log::info("Billing: Customer {$customerId} — {$motivo}. Skipping.");
                    }

                    continue;
                }

                $tenantId = $router->tenant_id;

                // Idempotency check: skip if a monthly invoice already covers
                // this month (an exact period_start match is not enough — a
                // prorated first invoice starts on the installation day).
                if ($this->monthlyInvoiceExists($tenantId, $customerId, $periodStart, $periodEnd)) {
                    continue;
                }

                // El administrador borró la factura de este periodo a propósito:
                // NO se vuelve a crear. Sólo aplica a ese mes.
                if ($this->isRegenerationSuppressed($tenantId, $customerId, $periodStart)) {
                    Log::info("Billing: Customer {$customerId} — factura de {$periodStart->format('Y-m')} eliminada por un administrador. No se regenera.");
                    continue;
                }

                // Tope de facturación: al moroso que ya acumula (umbral de corte
                // + margen) facturas pendientes se le deja de emitir la
                // mensualidad. Evita que un cliente cortado siga sumando deuda
                // mes tras mes — y que le sigan llegando avisos de facturas que
                // nadie va a cobrar.
                if ($stopAt !== null && $this->pendingInvoiceCount($tenantId, $customerId) >= $stopAt) {
                    Log::info("Billing: Customer {$customerId} — {$stopAt} factura(s) pendientes o más "
                        . "(tope del router {$router->id}). No se genera la mensualidad de {$periodStart->format('Y-m')}.");
                    continue;
                }

                // "Primera factura": un cliente cuyo servicio arrancó DENTRO de
                // este periodo (instalación a mitad de mes) no se factura como
                // uno antiguo. Devuelve null cuando no hay nada que cobrar.
                $charge = $this->resolveFirstInvoiceCharge(
                    $profile, $userService, $billingConfig, $servicePlan, $periodStart, $periodEnd
                );

                if ($charge === null) {
                    Log::info("Billing: Customer {$customerId} — servicio iniciado dentro de {$periodStart->format('Y-m')}; "
                        . 'la política de primera factura indica no cobrar este periodo.');
                    continue;
                }

                try {
                    $invoice = $this->createMonthlyInvoiceFor(
                        tenantId:    $tenantId,
                        customerId:  $customerId,
                        router:      $router,
                        profile:     $profile,
                        servicePlan: $servicePlan,
                        issueDate:   $issueDate,
                        dueDate:     $dueDate,
                        periodStart: $charge['period_start'],
                        periodEnd:   $periodEnd,
                        billingConfig: $billingConfig,
                        amount:      $charge['amount'],
                        itemDescription: $charge['description'],
                        free:        $charge['free'] ?? false,
                    );
                    $created++;
                    $this->markActionLogSuccess($tenantId, $router->id, $customerId, $periodStart, $periodEnd, $invoice->id);
                } catch (\Throwable $e) {
                    Log::error("Billing: Failed to create invoice for customer {$customerId}: {$e->getMessage()}");
                    $this->markActionLogFailed($tenantId, $router->id, $customerId, $periodStart, $periodEnd, $e->getMessage());
                }
            }
        }

        Log::info("Billing: Generation complete. {$created} invoice(s) created for period {$period}.");

        return $created;
    }

    /**
     * Audit (read-only) whether the monthly run actually happened for every
     * router that was due to invoice. This closes the blind spot the failover
     * cannot see: the failover only retries per-customer creation exceptions
     * that were recorded in billing_action_logs, so a router that was skipped
     * entirely — or a monthly job that never ran at all — leaves NO trace and
     * triggers NO retry. This method reconstructs the SAME gating as
     * generateMonthlyInvoices() and compares "expected" vs "actually created".
     *
     * Per router it reports:
     *   - due         : whether today's day has reached the (clamped) create day
     *   - expected    : billable customers with an active, non-courtesy service
     *   - capped      : morosos que llegaron al tope de facturas pendientes
     *                   (informativo: no se les factura y no son un problema)
     *   - actual      : monthly invoices that exist for the resolved period
     *   - failed_logs : FAILED/EXHAUSTED action-log rows for the period
     *   - status      : pending | ok | partial | no_show
     *
     * Writes nothing. Never creates invoices.
     *
     * @param string|null $period   YYYY-MM. Null = derive per router (same as generation).
     * @param int|null    $routerId Limit to a specific router (null = all).
     * @return array<int,array<string,mixed>>
     */
    public function auditMonthlyBilling(?string $period = null, ?int $routerId = null): array
    {
        $periodExplicit = $period !== null;
        $today          = now();
        $rows           = [];

        $routerQuery = Router::with('billingConfig')->whereNotNull('billing_router_id');
        if ($routerId !== null) {
            $routerQuery->where('id', $routerId);
        }

        foreach ($routerQuery->get() as $router) {
            $billingConfig = $router->billingConfig;
            if (!$billingConfig) {
                continue;
            }

            $createDay = Billing::clampDayToMonth(Billing::dayOf($billingConfig->create_invoice), $today);
            if ($createDay === null) {
                // No create day configured: not a no-show, nothing to audit.
                continue;
            }

            // Resolve the covered period EXACTLY like generateMonthlyInvoices().
            if ($periodExplicit) {
                $periodMonth = Carbon::parse($period . '-01');
            } else {
                $mode = $billingConfig->billing_mode ?: Billing::MODE_ANTICIPADO;
                $periodMonth = $mode === Billing::MODE_VENCIDO
                    ? $today->copy()->subMonthNoOverflow()
                    : $today->copy();
            }
            $periodStart = $periodMonth->copy()->startOfMonth()->startOfDay();
            $periodEnd   = $periodMonth->copy()->endOfMonth()->startOfDay();

            $due = $today->day >= $createDay;

            // Don't flag a no-show until the configured create hour has had its
            // chance to run. Generation is gated on create_invoice_time and the
            // scheduler ticks hourly, so we mirror the cut audit's 1h grace: the
            // gap between the configured hour and the next hourly run must not be
            // mistaken for a missing invoice. Skipped for an explicit (past) period.
            if ($due && !$periodExplicit) {
                $createMoment = Billing::applyTimeOfDay($today, $billingConfig->create_invoice_time);
                $due = $today->gte($createMoment->copy()->addHour());
            }

            // Expected: billable customers on this router with an active,
            // billable (non-courtesy) service — the same set generation would
            // invoice (incluidos los cortados por mora).
            // Excluded ("no facturar") customers are not expected to be invoiced,
            // so they must not count toward the no-show/partial audit either.
            // Tampoco cuentan los que la política de primera factura deja fuera
            // (alta a mitad de mes), aquellos cuya factura borró el operador ni
            // los que llegaron al tope de facturas pendientes: si contaran,
            // verify-monthly gritaría "partial" por facturas que NUNCA debieron
            // existir.
            $profiles = CustomerProfile::where('router_id', $router->id)
                ->billableServiceStatus()
                ->where('exclude_from_billing', false)
                ->with('user:id,created_at')
                ->get();

            $custIds = $profiles->pluck('user_id');

            $services = $custIds->isEmpty()
                ? collect()
                : UserService::whereIn('user_id', $custIds->all())
                    ->where('status', UserService::STATUS_ACTIVE)
                    ->with('servicePlan')
                    ->get()
                    ->keyBy('user_id');

            $expected   = 0;
            $skippedNew = 0;   // altas a mitad de mes que la política deja sin cobro
            $suppressed = 0;   // facturas que el operador borró a propósito
            $capped     = 0;   // morosos que llegaron al tope de facturas pendientes

            $stopAt = $billingConfig->invoiceStopThreshold();

            foreach ($profiles as $profile) {
                $userService = $services->get($profile->user_id);
                $plan        = $userService?->servicePlan;

                if (!$userService || !$plan || $plan->is_courtesy) {
                    continue;
                }

                if ($this->isRegenerationSuppressed($router->tenant_id, (int) $profile->user_id, $periodStart)) {
                    $suppressed++;
                    continue;
                }

                // Mismo tope que la generación. Se descuenta la factura del
                // periodo auditado si ya existe: sin eso, el cliente que acaba
                // de llegar al tope justo con ESTA factura se contaría como
                // "no esperado" y el audit reportaría un falso 'partial'.
                if ($stopAt !== null) {
                    $pending = $this->pendingInvoiceCount($router->tenant_id, (int) $profile->user_id)
                        - $this->monthlyInvoicesOfPeriod($periodStart, $periodEnd)
                            ->where('tenant_id', $router->tenant_id)
                            ->where('customer_id', $profile->user_id)
                            ->where('balance_due', '>', 0)
                            ->whereNotIn('status', ['paid', 'void', 'cancelled'])
                            ->count();

                    if ($pending >= $stopAt) {
                        $capped++;
                        continue;
                    }
                }

                $charge = $this->resolveFirstInvoiceCharge(
                    $profile, $userService, $billingConfig, $plan, $periodStart, $periodEnd
                );

                if ($charge === null) {
                    $skippedNew++;
                    continue;
                }

                $expected++;
            }

            // Actual: monthly invoices for the resolved period, scoped to this
            // router's customers (so multi-router tenants compare apples to apples).
            // Se cuenta por MES, no por period_start exacto: una primera factura
            // prorrateada arranca el día de la instalación.
            $actual = $custIds->isEmpty() ? 0 : $this->monthlyInvoicesOfPeriod($periodStart, $periodEnd)
                ->where('tenant_id', $router->tenant_id)
                ->whereIn('customer_id', $custIds->all())
                ->count();

            $failedLogs = BillingActionLog::where('tenant_id', $router->tenant_id)
                ->where('period_start', $periodStart->toDateString())
                ->whereIn('status', [BillingActionLog::STATUS_FAILED, BillingActionLog::STATUS_EXHAUSTED])
                ->count();

            if (!$due) {
                $status = 'pending';
            } elseif ($expected > 0 && $actual === 0) {
                $status = 'no_show';
            } elseif ($actual < $expected) {
                $status = 'partial';
            } else {
                $status = 'ok';
            }

            $rows[] = [
                'router_id'   => $router->id,
                'router_name' => $router->name,
                'tenant_id'   => $router->tenant_id,
                'period'      => $periodStart->format('Y-m'),
                'create_day'  => $createDay,
                'due'         => $due,
                'expected'    => $expected,
                'actual'      => $actual,
                'failed_logs' => $failedLogs,
                // Informativos: NO son un problema, pero explican por qué se
                // facturó a menos clientes de los que hay en el router.
                'skipped_new' => $skippedNew,
                'suppressed'  => $suppressed,
                'capped'      => $capped,
                'status'      => $status,
            ];
        }

        return $rows;
    }

    /**
     * Facturas PENDIENTES del cliente: las que tienen saldo y no están pagadas,
     * anuladas ni canceladas. Cuenta todos los tipos (mensual, instalación,
     * cargos), igual que el corte por mora — es el "debe N facturas" que ve el
     * operador. A diferencia del corte, aquí NO se filtra por vencimiento: la
     * factura del mes en curso, aunque todavía no venza, ya cuenta para el tope.
     */
    protected function pendingInvoiceCount(int $tenantId, int $customerId): int
    {
        return Invoice::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', ['paid', 'void', 'cancelled'])
            ->count();
    }

    /**
     * Is there already a monthly invoice covering $periodStart's month for this
     * customer? Matches on the MONTH rather than an exact period_start because a
     * prorated first invoice legitimately starts mid-month (installation day).
     * Non-monthly invoices (instalación, cargos, tickets) never count.
     */
    protected function monthlyInvoiceExists(int $tenantId, int $customerId, Carbon $periodStart, Carbon $periodEnd): bool
    {
        return $this->monthlyInvoicesOfPeriod($periodStart, $periodEnd)
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->exists();
    }

    /**
     * Base query for the monthly invoices covering [$periodStart, $periodEnd].
     *
     * whereDate en ambos extremos (y no whereBetween): el cast 'date' guarda
     * "Y-m-d H:i:s", que en SQLite se compara como texto y dejaría fuera una
     * factura cuyo period_start caiga justo en el último día del rango.
     */
    protected function monthlyInvoicesOfPeriod(Carbon $periodStart, Carbon $periodEnd)
    {
        return Invoice::query()
            ->where(fn ($q) => $q->where('invoice_type', Invoice::TYPE_MONTHLY)->orWhereNull('invoice_type'))
            ->whereDate('period_start', '>=', $periodStart->toDateString())
            ->whereDate('period_start', '<=', $periodEnd->toDateString());
    }

    /**
     * True when the operator deleted this customer's invoice for this period.
     * deleteInvoice() leaves the tombstone; generation must respect it forever.
     */
    protected function isRegenerationSuppressed(int $tenantId, int $customerId, Carbon $periodStart): bool
    {
        return $this->monthlyActionLogQuery($tenantId, $customerId, $periodStart)
            ->where('status', BillingActionLog::STATUS_SUPPRESSED)
            ->exists();
    }

    /**
     * Query for THE action-log row of a (tenant, customer, month) pair.
     *
     * whereDate y no una igualdad simple: el cast 'date' del modelo guarda
     * "2026-06-01 00:00:00", que en PostgreSQL la columna `date` normaliza pero
     * en SQLite (los tests) queda tal cual y una comparación con "2026-06-01"
     * no encontraría nada.
     */
    protected function monthlyActionLogQuery(int $tenantId, int $customerId, Carbon $periodStart)
    {
        return BillingActionLog::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereDate('period_start', $periodStart->copy()->startOfMonth()->toDateString())
            ->where('action', BillingActionLog::ACTION_GENERATE_MONTHLY);
    }

    /**
     * Fecha en que el cliente realmente empezó a tener servicio.
     *
     * Toma la MÁS ANTIGUA de las señales disponibles (fecha de instalación,
     * inicio del servicio, alta del usuario). El mínimo es deliberado: si una
     * de ellas es reciente por un motivo operativo — por ejemplo una carga
     * masiva que reescribió user_services — un cliente antiguo seguirá
     * facturándose como siempre en vez de quedar silenciosamente sin factura.
     */
    protected function resolveServiceStart(CustomerProfile $profile, ?UserService $userService): ?Carbon
    {
        $candidates = [];

        if ($profile->installation_date) {
            $candidates[] = Carbon::parse($profile->installation_date)->startOfDay();
        }
        if ($userService?->start_date) {
            $candidates[] = Carbon::parse($userService->start_date)->startOfDay();
        }
        if ($profile->user?->created_at) {
            $candidates[] = Carbon::parse($profile->user->created_at)->startOfDay();
        }

        if (empty($candidates)) {
            return null;
        }

        return collect($candidates)->sortBy(fn (Carbon $d) => $d->timestamp)->first();
    }

    /**
     * Qué cobrarle a este cliente por el periodo en curso.
     *
     * Toda la aritmética y la cascada cliente → plan → router viven en
     * App\Billing\FirstInvoicePolicy; aquí sólo se arma el contexto (cuándo
     * arrancó el servicio y cuánto cuesta el plan). Devuelve null cuando no
     * debe existir factura, y un cargo con free = true en los meses de
     * cortesía posteriores a la instalación (factura en cero).
     *
     * @return array{period_start: Carbon, amount: float, description: string, free: bool}|null
     */
    protected function resolveFirstInvoiceCharge(
        CustomerProfile $profile,
        ?UserService $userService,
        Billing $billingConfig,
        \App\Models\Plan $servicePlan,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): ?array {
        return FirstInvoicePolicy::resolve($profile, $servicePlan, $billingConfig)
            ->chargeFor(
                serviceStart: $this->resolveServiceStart($profile, $userService),
                periodStart:  $periodStart,
                periodEnd:    $periodEnd,
                fullAmount:   (float) ($servicePlan->cost_product ?? 0),
                planName:     $servicePlan->name,
            );
    }

    /**
     * Shared invoice-creation block used by the monthly run AND by failover retries.
     * Throws on failure so the caller can log it.
     *
     * $amount / $itemDescription permiten cobrar algo distinto al plan completo
     * (prorrateo de primera factura). Por defecto: el costo del plan.
     *
     * $free marca un mes de cortesía (promoción de instalación): la factura se
     * emite igual —para que quede constancia y para que la auditoría no la lea
     * como una factura que faltó— pero nace en cero y ya saldada, así queda
     * fuera del seguimiento de mora y del corte automático, que filtran por
     * balance_due > 0 y status not in (void, cancelled, paid).
     */
    protected function createMonthlyInvoiceFor(
        int $tenantId,
        int $customerId,
        Router $router,
        CustomerProfile $profile,
        \App\Models\Plan $servicePlan,
        Carbon $issueDate,
        Carbon $dueDate,
        Carbon $periodStart,
        Carbon $periodEnd,
        Billing $billingConfig,
        ?float $amount = null,
        ?string $itemDescription = null,
        bool $free = false,
    ): Invoice {
        $subtotal    = $free ? 0.0 : ($amount ?? (float) ($servicePlan->cost_product ?? 0));
        $tax         = 0;
        $total       = $subtotal + $tax;
        $description = $itemDescription ?: "Servicio mensual: {$servicePlan->name}";

        $invoiceNumber = $this->generateInvoiceNumber($tenantId);

        $invoice = Invoice::create([
            'tenant_id'    => $tenantId,
            'customer_id'  => $customerId,
            'service_id'   => $servicePlan->id,
            'invoice_type' => Invoice::TYPE_MONTHLY,
            'number'       => $invoiceNumber,
            'issue_date'   => $issueDate,
            'due_date'     => $dueDate,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'currency'     => 'COP',
            'subtotal'     => $subtotal,
            'tax'          => $tax,
            'total'        => $total,
            // El saldo sale del total, sin atajos: un mes de cortesía nace en
            // cero porque su subtotal ES cero, no porque se le fuerce. Si más
            // abajo los servicios adicionales le suman algo, la factura sube de
            // 'paid' a 'issued' sola y vuelve al circuito normal de cobro.
            'balance_due'  => $total,
            'status'       => $total > 0 ? 'issued' : 'paid',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'type'       => 'plan',
            'description'=> $description,
            'quantity'   => 1,
            'unit_price' => $subtotal,
            'amount'     => $subtotal,
        ]);

        // Arrastre: lo que quedó debiendo de abonos parciales anteriores se
        // cobra aquí, como un ítem más de la factura del mes.
        //
        // En un mes de cortesía NO: esa factura nace en cero y ya saldada para
        // quedar fuera de la mora y del corte; meterle deuda la sacaría de esa
        // condición. El arrastre espera a la siguiente factura cobrable.
        if (!$free) {
            $this->applyPendingCarryoversTo($invoice);
        }

        // Servicios adicionales recurrentes del cliente (alquiler de equipos,
        // soporte mensual...). Van DESPUÉS del arrastre y ANTES del saldo a
        // favor: aplicar el crédito contra un total al que todavía le faltan
        // ítems dejaría balance_due mal y el aviso al cliente con otra cifra.
        //
        // OJO con $periodStart: en una primera factura prorrateada es el día de
        // instalación, no el 1º. Los adicionales razonan sobre el MES natural,
        // que se deriva de $periodEnd (siempre fin de mes en este método).
        $this->applyAdditionalServicesTo($invoice, $periodEnd->copy()->startOfMonth(), $periodEnd, $free);

        $profile->refresh();
        $this->applyCreditToInvoice($invoice, $profile);

        Log::info("Billing: Invoice {$invoiceNumber} created for customer {$customerId} (router {$router->id})"
            . ($free ? ' — mes de cortesía (plan en cero).' : '.'));

        // Se notifica lo que hay que pagar, y sólo eso. Manda el saldo, no el
        // motivo:
        //
        //  - Mes de cortesía sin adicionales → $0: no se avisa. Avisar de una
        //    factura que no hay que pagar confunde (y gasta mensajes).
        //  - Factura que nace SALDADA porque el saldo a favor la cubrió entera
        //    → tampoco: el aviso "tienes una nueva factura" le llegaba a quien
        //    ya no debía nada, como si no la hubiera pagado.
        //  - Mes de cortesía CON adicionales → sí se avisa: el plan va gratis
        //    pero el alquiler del equipo se cobra, y el cliente tiene que
        //    enterarse de que debe pagarlo. Antes esta rama estaba dentro de un
        //    `if (!$free)` que la habría dejado muda.
        //
        // Notification failure must NOT roll back the invoice.
        try {
            $invoice->refresh()->load('tenant');

            if ((float) $invoice->balance_due > 0) {
                $this->notifyInvoiceCreated($invoice, $profile, $billingConfig);
            } else {
                Log::info("Billing: Invoice {$invoiceNumber} no tiene saldo por cobrar (cortesía o saldo a favor del cliente {$customerId}). No se notifica.");
            }
        } catch (\Throwable $e) {
            Log::error("Billing: notify-on-create failed for invoice {$invoiceNumber}: {$e->getMessage()}");
        }

        return $invoice;
    }

    /**
     * Retry a previously-failed monthly invoice from its action log row.
     * Increments attempts, recomputes next_retry_at, and marks success/exhausted.
     *
     * @return bool true if the invoice was created (or already existed), false otherwise
     */
    public function retryFailedInvoice(BillingActionLog $log): bool
    {
        if (!$log->isReadyToRetry()) {
            return false;
        }

        $router = Router::with('billingConfig')->find($log->router_id);
        if (!$router || !$router->billingConfig) {
            $log->update([
                'status'     => BillingActionLog::STATUS_EXHAUSTED,
                'last_error' => 'Router or billing config no longer exists',
                'attempts'   => $log->attempts + 1,
            ]);
            return false;
        }

        // Mismas reglas de audiencia que la corrida mensual: un cliente cortado
        // por mora SÍ se factura (el tope de más abajo lo frena); sólo quedan
        // fuera las bajas definitivas y los "no facturar".
        $profile = CustomerProfile::where('user_id', $log->customer_id)->first();
        if (!$profile || !$profile->hasBillableServiceStatus() || $profile->exclude_from_billing) {
            $log->update([
                'status'     => BillingActionLog::STATUS_EXHAUSTED,
                'last_error' => $profile && $profile->exclude_from_billing
                    ? 'Customer excluded from billing'
                    : 'Customer profile retired/cancelled or missing',
                'attempts'   => $log->attempts + 1,
            ]);
            return false;
        }

        $userService = UserService::where('user_id', $log->customer_id)
            ->where('status', UserService::STATUS_ACTIVE)
            ->with('servicePlan')
            ->first();

        if (!$userService || !$userService->servicePlan || $userService->servicePlan->is_courtesy) {
            $log->update([
                'status'     => BillingActionLog::STATUS_EXHAUSTED,
                'last_error' => 'No active billable service plan',
                'attempts'   => $log->attempts + 1,
            ]);
            return false;
        }

        $logPeriodStart = Carbon::parse($log->period_start)->startOfDay();
        $logPeriodEnd   = Carbon::parse($log->period_end)->startOfDay();

        // Idempotency: if the invoice already exists, just mark success.
        $existing = $this->monthlyInvoicesOfPeriod($logPeriodStart, $logPeriodEnd)
            ->where('tenant_id', $log->tenant_id)
            ->where('customer_id', $log->customer_id)
            ->first();

        if ($existing) {
            $log->update([
                'status'     => BillingActionLog::STATUS_SUCCESS,
                'invoice_id' => $existing->id,
                'attempts'   => $log->attempts + 1,
                'last_error' => null,
            ]);
            return true;
        }

        // Tope de facturación del router: si entretanto el cliente llegó al
        // límite de facturas pendientes, este reintento no debe crear una más.
        // Va DESPUÉS de la idempotencia: si la factura ya existe, el log se
        // cierra como éxito, no como agotado.
        $stopAt = $router->billingConfig->invoiceStopThreshold();
        if ($stopAt !== null && $this->pendingInvoiceCount((int) $log->tenant_id, (int) $log->customer_id) >= $stopAt) {
            $log->update([
                'status'     => BillingActionLog::STATUS_EXHAUSTED,
                'last_error' => "Tope de facturación alcanzado ({$stopAt} facturas pendientes)",
                'attempts'   => $log->attempts + 1,
            ]);
            return false;
        }

        // Misma política de "primera factura" que la corrida mensual: el
        // failover no debe cobrar un mes completo que la generación había
        // decidido no cobrar (o cobrar prorrateado).
        $charge = $this->resolveFirstInvoiceCharge(
            $profile->loadMissing('user:id,created_at'),
            $userService,
            $router->billingConfig,
            $userService->servicePlan,
            $logPeriodStart,
            $logPeriodEnd,
        );

        if ($charge === null) {
            $log->update([
                'status'     => BillingActionLog::STATUS_EXHAUSTED,
                'last_error' => 'Sin cargo para el periodo (política de primera factura del cliente/router)',
                'attempts'   => $log->attempts + 1,
            ]);
            return false;
        }

        // Recompute due date from billing config (mirror of main loop).
        $today      = now();
        $billingConfig = $router->billingConfig;
        $dueDay     = $billingConfig->payment_day
            ? Carbon::parse($billingConfig->payment_day)->day
            : null;
        $issueDate  = $today->copy()->startOfDay();
        if ($dueDay !== null) {
            $lastDayOfMonth = $today->copy()->endOfMonth()->day;
            $clampedDueDay  = min($dueDay, $lastDayOfMonth);
            $dueDate = $today->copy()->setDay($clampedDueDay)->startOfDay();
            if ($dueDate->lt($issueDate)) {
                $dueDate = $dueDate->addMonth();
            }
        } else {
            $dueDate = $issueDate->copy()->addDays(5);
        }

        try {
            $invoice = $this->createMonthlyInvoiceFor(
                tenantId:    $log->tenant_id,
                customerId:  $log->customer_id,
                router:      $router,
                profile:     $profile,
                servicePlan: $userService->servicePlan,
                issueDate:   $issueDate,
                dueDate:     $dueDate,
                periodStart: $charge['period_start'],
                periodEnd:   $logPeriodEnd,
                billingConfig: $billingConfig,
                amount:      $charge['amount'],
                itemDescription: $charge['description'],
                free:        $charge['free'] ?? false,
            );

            $log->update([
                'status'        => BillingActionLog::STATUS_SUCCESS,
                'invoice_id'    => $invoice->id,
                'attempts'      => $log->attempts + 1,
                'last_error'    => null,
                'next_retry_at' => null,
            ]);
            return true;
        } catch (\Throwable $e) {
            $attempts = $log->attempts + 1;
            $exhausted = $attempts >= BillingActionLog::MAX_ATTEMPTS;

            $log->update([
                'status'        => $exhausted ? BillingActionLog::STATUS_EXHAUSTED : BillingActionLog::STATUS_FAILED,
                'attempts'      => $attempts,
                'last_error'    => $e->getMessage(),
                'next_retry_at' => $exhausted ? null : now()->addSeconds(
                    BillingActionLog::RETRY_BACKOFF_SECONDS[$attempts] ?? 3600
                ),
            ]);
            Log::error("Billing: Retry attempt {$attempts} failed for log {$log->id} (customer {$log->customer_id}): {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Upsert an action log row marking a failed invoice creation attempt.
     * Increments attempts and computes next_retry_at via backoff.
     */
    protected function markActionLogFailed(int $tenantId, ?int $routerId, int $customerId, Carbon $periodStart, Carbon $periodEnd, string $errorMessage): void
    {
        $existing = BillingActionLog::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('period_start', $periodStart->toDateString())
            ->where('action', BillingActionLog::ACTION_GENERATE_MONTHLY)
            ->first();

        if ($existing) {
            $attempts = $existing->attempts + 1;
            $exhausted = $attempts >= BillingActionLog::MAX_ATTEMPTS;
            $existing->update([
                'router_id'     => $routerId,
                'status'        => $exhausted ? BillingActionLog::STATUS_EXHAUSTED : BillingActionLog::STATUS_FAILED,
                'attempts'      => $attempts,
                'last_error'    => $errorMessage,
                'next_retry_at' => $exhausted ? null : now()->addSeconds(
                    BillingActionLog::RETRY_BACKOFF_SECONDS[$attempts] ?? 3600
                ),
            ]);
        } else {
            BillingActionLog::create([
                'tenant_id'     => $tenantId,
                'router_id'     => $routerId,
                'customer_id'   => $customerId,
                'action'        => BillingActionLog::ACTION_GENERATE_MONTHLY,
                'period_start'  => $periodStart->toDateString(),
                'period_end'    => $periodEnd->toDateString(),
                'status'        => BillingActionLog::STATUS_FAILED,
                'attempts'      => 1,
                'last_error'    => $errorMessage,
                'next_retry_at' => now()->addSeconds(BillingActionLog::RETRY_BACKOFF_SECONDS[1]),
            ]);
        }
    }

    /**
     * Close a previously-failed log row when the invoice finally succeeds.
     * No-op if there's no prior failed row — we keep the log lean and focused
     * on trouble cases (failed / exhausted), not on every successful invoice.
     */
    protected function markActionLogSuccess(int $tenantId, ?int $routerId, int $customerId, Carbon $periodStart, Carbon $periodEnd, int $invoiceId): void
    {
        $existing = BillingActionLog::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('period_start', $periodStart->toDateString())
            ->where('action', BillingActionLog::ACTION_GENERATE_MONTHLY)
            ->first();

        if (!$existing) {
            return;
        }

        $existing->update([
            'router_id'     => $routerId,
            'period_end'    => $periodEnd->toDateString(),
            'invoice_id'    => $invoiceId,
            'status'        => BillingActionLog::STATUS_SUCCESS,
            'last_error'    => null,
            'next_retry_at' => null,
        ]);
    }

    /**
     * Generate a service charge invoice (from a ticket or as a standalone additional charge).
     *
     * @param array $data {tenant_id, customer_id, items[], ticket_id?, invoice_type?, due_date?, notes?}
     * @return Invoice
     */
    public function generateServiceChargeInvoice(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $tenantId   = $data['tenant_id'];
            $customerId = $data['customer_id'];
            $ticketId   = $data['ticket_id'] ?? null;
            $items      = $data['items'];
            $issueDate  = now()->startOfDay();
            $dueDate    = isset($data['due_date'])
                ? \Carbon\Carbon::parse($data['due_date'])->startOfDay()
                : $issueDate->copy()->addDays(5);
            $notes      = $data['notes'] ?? null;
            $type       = $data['invoice_type'] ?? ($ticketId ? Invoice::TYPE_SERVICE_CHARGE : Invoice::TYPE_ADDITIONAL);

            $subtotal = collect($items)->sum(fn($item) => (float) $item['quantity'] * (float) $item['unit_price']);

            $invoiceNumber = $this->generateInvoiceNumber($tenantId);

            $invoice = Invoice::create([
                'tenant_id'    => $tenantId,
                'customer_id'  => $customerId,
                'ticket_id'    => $ticketId,
                'invoice_type' => $type,
                'number'       => $invoiceNumber,
                'issue_date'   => $issueDate,
                'due_date'     => $dueDate,
                'period_start' => $issueDate,
                'period_end'   => $dueDate,
                'currency'     => 'COP',
                'subtotal'     => $subtotal,
                'tax'          => 0,
                'total'        => $subtotal,
                'balance_due'  => $subtotal,
                'status'       => 'issued',
                'notes'        => $notes,
            ]);

            foreach ($items as $item) {
                $amount = (float) $item['quantity'] * (float) $item['unit_price'];
                InvoiceItem::create([
                    'invoice_id'  => $invoice->id,
                    'type'        => $item['type'] ?? 'service',
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit'        => $item['unit'] ?? null,
                    'unit_price'  => $item['unit_price'],
                    'amount'      => $amount,
                ]);
            }

            $profile = \App\Models\CustomerProfile::where('user_id', $customerId)->first();
            if ($profile) {
                $this->applyCreditToInvoice($invoice, $profile);
            }

            Log::info("Billing: Service charge invoice {$invoiceNumber} created for customer {$customerId}" . ($ticketId ? " (ticket #{$ticketId})" : '') . '.');

            return $invoice->load(['items', 'customer.customerProfile', 'tenant']);
        });
    }

    /**
     * Register a payment and allocate it to invoices.
     *
     * @param array $data
     * @return Payment
     */
    public function registerPayment(array $data): Payment
    {
        $payment = DB::transaction(function () use ($data) {
            $payment = Payment::create([
                'tenant_id' => $data['tenant_id'],
                'customer_id' => $data['customer_id'],
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'],
                'method' => $data['method'] ?? 'cash',
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'completed',
                'created_by' => $data['created_by'] ?? null,
            ]);

            // Allocate payment to open invoices (oldest first)
            $this->allocatePayment($payment, $data['allocations'] ?? null);

            return $payment->load('allocations');
        });

        // After commit: if this payment cleared the customer's outstanding
        // balance, reconnect them right away (synchronously) so the operator
        // sees the result without depending on a queue worker being up. The
        // call is fully guarded (never throws) and runs after the transaction,
        // so a slow/failing router can't roll back or break the saved payment.
        $this->reactivateIfCleared((int) $data['customer_id']);

        return $payment;
    }

    /**
     * Auto-reconnect a customer after a payment IF, and only if:
     *   - they have NO overdue invoices left, AND
     *   - they are currently cut on the router (last suspension log =
     *     SUSPEND/success not yet followed by an UNSUSPEND).
     *
     * Per operator policy, ANY current cut is lifted once the balance is
     * cleared — including manual suspensions — so paying always reconnects the
     * customer. Lifts the block via RouterProvisioningService and mirrors the
     * manual `activate` DB state (status=true). Never throws — a router failure
     * must not roll back or break the payment; the reconcile/manual tools
     * remain the safety net.
     */
    public function reactivateIfCleared(int $customerId): void
    {
        try {
            $profile = CustomerProfile::where('user_id', $customerId)->first();
            if (!$profile || !$profile->router_id || !$profile->ip_user) {
                return;
            }

            // Still owes? Keep the cut in place.
            $overdue = Invoice::where('customer_id', $customerId)
                ->where('due_date', '<', now())
                ->where('balance_due', '>', 0)
                ->whereNotIn('status', ['void', 'cancelled', 'paid'])
                ->count();
            if ($overdue > 0) {
                return;
            }

            // Is the customer currently cut on the router? The latest log row is
            // the same "confirmed cut" signal the reconciler uses: a successful
            // SUSPEND that hasn't been followed by an UNSUSPEND.
            $latest = SuspensionActionLog::where('customer_id', $customerId)
                ->where('router_id', $profile->router_id)
                ->latest('id')
                ->first();

            $currentlyCut = $latest
                && $latest->action === SuspensionActionLog::ACTION_SUSPEND
                && $latest->status === SuspensionActionLog::STATUS_SUCCESS;

            if (!$currentlyCut) {
                return; // not cut, or already reconnected
            }

            $ok = app(RouterProvisioningService::class)->unsuspendCustomer(
                $customerId,
                (int) $profile->router_id,
                ['reason' => SuspensionActionLog::REASON_AUTO_RECONNECT]
            );

            // Mirror the manual activate's DB state so the UI reflects reality.
            if ($ok && $profile->status !== true) {
                $plan = $profile->service_id ? Plan::find($profile->service_id) : null;
                $profile->update([
                    'status'         => true,
                    'service_status' => ($plan && $plan->is_courtesy) ? 'gratis' : 'activo',
                ]);
            }

            Log::info("Billing: auto-reconnect customer {$customerId} after payment cleared overdue balance "
                . "(router {$profile->router_id}). router_ok=" . ($ok ? '1' : '0'));
        } catch (\Throwable $e) {
            Log::error("Billing: auto-reconnect after payment failed for customer {$customerId}: {$e->getMessage()}");
        }
    }

    /**
     * Allocate payment amount to invoices.
     * Any unallocated remainder is stored as credit_balance on the customer profile.
     *
     * Un abono que no alcanza a cubrir la factura NO la deja a medias: la cierra
     * como pagada y manda el faltante al arrastre (ver carryOverShortfall), que
     * la siguiente factura mensual cobra. Es decisión de operación: el cliente
     * que abona sale de mora y no se le corta hasta que venza esa factura nueva.
     *
     * @param Payment $payment
     * @param array|null $allocations Manual allocations: [['invoice_id' => X, 'amount' => Y], ...]
     */
    protected function allocatePayment(Payment $payment, ?array $allocations = null): void
    {
        $remainingAmount = (float) $payment->amount;

        if ($allocations) {
            // Manual allocation
            foreach ($allocations as $allocation) {
                if ($remainingAmount <= 0)
                    break;

                $invoice = Invoice::find($allocation['invoice_id']);
                if (!$invoice || $invoice->balance_due <= 0)
                    continue;

                $amountToApply = min($allocation['amount'], $invoice->balance_due, $remainingAmount);

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $amountToApply,
                ]);

                $invoice->balance_due -= $amountToApply;
                $this->updateInvoiceStatus($invoice);
                $invoice->save();
                $this->carryOverShortfall($invoice, $payment);

                $remainingAmount -= $amountToApply;
            }
        } else {
            // Auto-allocate to oldest open invoices (FIFO)
            $openInvoices = Invoice::where('customer_id', $payment->customer_id)
                ->where('balance_due', '>', 0)
                ->whereNotIn('status', ['void', 'cancelled'])
                ->orderBy('due_date', 'asc')
                ->get();

            foreach ($openInvoices as $invoice) {
                if ($remainingAmount <= 0)
                    break;

                $amountToApply = min((float) $invoice->balance_due, $remainingAmount);

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $amountToApply,
                ]);

                $invoice->balance_due -= $amountToApply;
                $this->updateInvoiceStatus($invoice);
                $invoice->save();
                $this->carryOverShortfall($invoice, $payment);

                $remainingAmount -= $amountToApply;
            }
        }

        // Any remaining amount after all invoices are paid becomes credit for the customer.
        //
        // El excedente entra por el libro de movimientos (CustomerCredit), no
        // sumándole a credit_balance a pelo: es lo que después permite anular
        // este pago devolviendo solo la parte que ninguna factura consumió.
        if ($remainingAmount > 0) {
            $customer = CustomerProfile::where('user_id', $payment->customer_id)->first();
            if ($customer) {
                CustomerCredit::earn(
                    $payment,
                    $remainingAmount,
                    "Excedente del pago #{$payment->id}"
                );
                Log::info("Billing: Payment {$payment->id} — \${$remainingAmount} stored as credit for customer {$payment->customer_id}.");
            }
        }
    }

    // ─── Arrastre de saldo por pago parcial ──────────────────────────────────

    /**
     * Cierra una factura que recibió un abono parcial y manda el faltante al
     * arrastre: la factura queda PAGADA (sale de la mora y del corte) y el saldo
     * se cobrará en la siguiente factura mensual del cliente.
     *
     * No hace nada si la factura quedó saldada (pago exacto o en exceso).
     */
    protected function carryOverShortfall(Invoice $invoice, Payment $payment): void
    {
        $shortfall = round((float) $invoice->balance_due, 2);

        if ($shortfall <= 0) {
            return;
        }

        InvoiceCarryover::create([
            'tenant_id'       => $invoice->tenant_id,
            'customer_id'     => $invoice->customer_id,
            'from_invoice_id' => $invoice->id,
            'payment_id'      => $payment->id,
            'amount'          => $shortfall,
            'status'          => InvoiceCarryover::STATUS_PENDING,
        ]);

        $invoice->carried_out = (float) $invoice->carried_out + $shortfall;
        $invoice->balance_due = 0;
        $this->updateInvoiceStatus($invoice);
        $invoice->save();

        Log::info("Billing: abono parcial en factura {$invoice->id} (#{$invoice->number}) — "
            . "se cierra como pagada y \${$shortfall} pasa como saldo pendiente "
            . "del cliente {$invoice->customer_id} a la próxima factura.");
    }

    /**
     * Cobra en $invoice todo el saldo arrastrado que el cliente tenga pendiente:
     * lo agrega como un ítem más y marca los movimientos como aplicados.
     *
     * Sólo lo hace la facturación mensual: es "la próxima factura" del ciclo. Un
     * cargo adicional o una factura manual no arrastran deuda ajena encima, que
     * sorprendería al operador que la está emitiendo a mano.
     */
    /**
     * Suma a la factura del mes los servicios adicionales recurrentes del
     * cliente (alquiler de router extra, soporte mensual, un punto de TV...).
     *
     * NO emiten factura propia: son ítems más de la mensualidad, igual que el
     * arrastre. La mecánica de totales es la misma que applyPendingCarryoversTo.
     *
     * Cuatro filtros, en este orden:
     *
     *  1. Ventana de vigencia de la asignación (starts_at / ends_at / is_active).
     *  2. Cortesía: si el mes es de cortesía por instalación, sólo entran los
     *     servicios marcados `charge_on_courtesy_month`.
     *  3. Idempotencia: si esta asignación YA tiene un ítem en una factura del
     *     cliente que cubre este mes, no se vuelve a cobrar.
     *  4. Prorrateo: si la asignación arrancó dentro de este mes, manda el
     *     `proration_mode` del catálogo (none / prorated / full).
     *
     * La idempotencia se DERIVA de los ítems existentes y no de un contador en
     * la asignación: si un administrador borra la factura del mes, los ítems se
     * van con ella (FK en cascada) y el periodo queda libre para volver a
     * cobrarse. Un contador habría quedado adelantado y ese mes no se cobraría
     * nunca.
     *
     * @param bool $courtesyMonth Mes de cortesía por instalación (plan en cero).
     */
    protected function applyAdditionalServicesTo(
        Invoice $invoice,
        Carbon $periodStart,
        Carbon $periodEnd,
        bool $courtesyMonth = false,
        ?array $lines = null,
    ): void {
        // $lines ya calculado: lo pasa la factura de excepción, que tuvo que
        // preguntar ANTES de crear nada para no emitir una factura vacía.
        $lines ??= $this->chargeableAdditionalServices(
            (int) $invoice->tenant_id, (int) $invoice->customer_id, $periodStart, $periodEnd, $courtesyMonth
        );

        if (empty($lines)) {
            return;
        }

        $added = 0.0;

        foreach ($lines as $line) {
            InvoiceItem::create([
                'invoice_id'                     => $invoice->id,
                'customer_additional_service_id' => $line['assignment_id'],
                'type'                           => 'additional_service',
                'description'                    => $line['description'],
                'quantity'                       => $line['quantity'],
                'unit_price'                     => $line['unit_price'],
                'amount'                         => $line['amount'],
            ]);

            $added += $line['amount'];
        }

        $invoice->subtotal    = (float) $invoice->subtotal + $added;
        $invoice->total       = (float) $invoice->total + $added;
        $invoice->balance_due = (float) $invoice->balance_due + $added;
        $this->updateInvoiceStatus($invoice);
        $invoice->save();

        Log::info("Billing: factura {$invoice->id} (#{$invoice->number}) suma \${$added} "
            . "en servicios adicionales del cliente {$invoice->customer_id}.");
    }

    /**
     * Puerta pública a los servicios adicionales, para las rutas de creación de
     * facturas que NO pasan por createMonthlyInvoiceFor().
     *
     * Existe una: el comando one-off `billing:generate-tenant`. Sin esto,
     * facturaría de menos y sin avisar. Cualquier camino nuevo que cree una
     * mensualidad por su cuenta tiene que llamar aquí — o, mejor, no existir.
     */
    public function addRecurringExtrasTo(Invoice $invoice, Carbon $periodStart, Carbon $periodEnd): void
    {
        $this->applyAdditionalServicesTo($invoice, $periodStart, $periodEnd);
    }

    /**
     * Servicios adicionales de un cliente que **deberían haberse cobrado este
     * mes y no aparecen en ninguna factura**.
     *
     * Es el detector de la fuga silenciosa: una asignación puede quedar sin
     * cobrar por motivos legítimos del ciclo (cliente excluido, retirado, tope
     * de mora alcanzado, mes suprimido), y desde la ficha se seguiría viendo
     * "activa" indefinidamente sin que nadie note que no se está facturando.
     *
     * Reutiliza EL MISMO filtro que el cobro (`chargeableAdditionalServices`),
     * así que no puede reportar como pendiente algo que el cobro no iba a
     * cobrar de todos modos — un indicador que grita en falso se acaba
     * ignorando, y entonces no sirve para nada.
     *
     * Si el cliente todavía no tiene factura de este periodo no se reporta
     * nada: el ciclo de su router simplemente no ha corrido aún, que no es lo
     * mismo que haberse saltado el cobro.
     *
     * @return array<int,int> ids de asignación
     */
    public function pendingAdditionalServiceIds(int $tenantId, int $customerId): array
    {
        $periodStart = now()->startOfMonth()->startOfDay();
        $periodEnd   = now()->endOfMonth()->startOfDay();

        $tieneFacturaDelPeriodo = Invoice::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->whereDate('period_start', '<=', $periodEnd->toDateString())
            ->whereDate('period_end', '>=', $periodStart->toDateString())
            ->exists();

        if (!$tieneFacturaDelPeriodo) {
            return [];
        }

        return array_column(
            $this->chargeableAdditionalServices($tenantId, $customerId, $periodStart, $periodEnd),
            'assignment_id'
        );
    }

    /**
     * Barrido del tenant: todas las asignaciones sin cobrar este mes.
     *
     * Itera sólo sobre los clientes QUE TIENEN adicionales activos, no sobre
     * toda la base: ese conjunto es pequeño por naturaleza, así que el barrido
     * cuesta poco aunque el tenant tenga miles de clientes.
     *
     * @return \Illuminate\Support\Collection<int,CustomerAdditionalService>
     */
    public function unbilledAdditionalServices(int $tenantId)
    {
        $customerIds = CustomerAdditionalService::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->distinct()
            ->pluck('customer_id');

        $pendingIds = [];

        foreach ($customerIds as $customerId) {
            $pendingIds = array_merge($pendingIds, $this->pendingAdditionalServiceIds($tenantId, (int) $customerId));
        }

        if (empty($pendingIds)) {
            return collect();
        }

        return CustomerAdditionalService::withoutTenantScope()
            ->whereIn('id', $pendingIds)
            ->with([
                'service' => fn ($q) => $q->withoutGlobalScope('tenant'),
                'customer:id',
                'customer.customerProfile:user_id,name,last_name',
            ])
            ->get();
    }

    /**
     * Factura de EXCEPCIÓN: sólo servicios adicionales, sin plan.
     *
     * Existe para los dos únicos casos en que la corrida mensual salta a un
     * cliente porque **no tiene plan que cobrarle**, no porque no haya que
     * cobrarle: cliente vigente sin `user_services` activo, y cliente con plan
     * de cortesía permanente (empleado, canje). Ese cliente no paga internet,
     * pero sí puede estar alquilando un router — y eso hay que cobrarlo.
     *
     * NO se emite en los otros cinco motivos de salto (`exclude_from_billing`,
     * retirado/cancelado, tope de facturas pendientes, mes suprimido por
     * borrado manual, política de primera factura): en todos ellos alguien —el
     * operador o el propio sistema— ya decidió que a ese cliente no se le cobra,
     * y emitirle una factura sería desobedecerlo. El tope de mora es el más
     * delicado: existe justo para dejar de inflar deuda incobrable, así que
     * también se respeta aquí.
     *
     * Detalles deliberados:
     *  - Usa el `due_date` del ciclo del router, no "hoy + 5 días" como el cargo
     *    puntual: así entra al seguimiento de mora y al corte igual que el resto.
     *  - `invoice_type = additional`, de modo que ni `monthlyInvoiceExists()` ni
     *    `auditMonthlyBilling()` la confundan con la mensualidad que falta.
     *  - `courtesyMonth = false`: `charge_on_courtesy_month` habla de los meses
     *    de cortesía POR INSTALACIÓN, no de un plan de cortesía permanente. Aquí
     *    no hay factura de plan de la que ser cortesía.
     *
     * @return Invoice|null null cuando no había nada que cobrar (lo normal).
     */
    protected function issueAdditionalOnlyInvoice(
        Router $router,
        CustomerProfile $profile,
        Billing $billingConfig,
        Carbon $issueDate,
        Carbon $dueDate,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?int $stopAt,
    ): ?Invoice {
        $tenantId   = (int) $router->tenant_id;
        $customerId = (int) $profile->user_id;

        $lines = $this->chargeableAdditionalServices($tenantId, $customerId, $periodStart, $periodEnd);

        if (empty($lines)) {
            return null;
        }

        // Mismo tope que la mensualidad: al moroso que ya acumula facturas sin
        // pagar no se le sigue sumando deuda, venga del plan o de un adicional.
        if ($stopAt !== null && $this->pendingInvoiceCount($tenantId, $customerId) >= $stopAt) {
            Log::info("Billing: Customer {$customerId} — tope de facturación alcanzado; "
                . 'no se emite la factura de servicios adicionales.');
            return null;
        }

        return DB::transaction(function () use (
            $tenantId, $customerId, $router, $profile, $billingConfig,
            $issueDate, $dueDate, $periodStart, $periodEnd, $lines
        ) {
            $invoiceNumber = $this->generateInvoiceNumber($tenantId);

            $invoice = Invoice::create([
                'tenant_id'    => $tenantId,
                'customer_id'  => $customerId,
                'invoice_type' => Invoice::TYPE_ADDITIONAL,
                'number'       => $invoiceNumber,
                'issue_date'   => $issueDate,
                'due_date'     => $dueDate,
                'period_start' => $periodStart,
                'period_end'   => $periodEnd,
                'currency'     => 'COP',
                'subtotal'     => 0,
                'tax'          => 0,
                'total'        => 0,
                'balance_due'  => 0,
                'status'       => 'issued',
            ]);

            // Nace en cero y los ítems la levantan, igual que la mensualidad.
            $this->applyAdditionalServicesTo($invoice, $periodStart, $periodEnd, false, $lines);

            $profile->refresh();
            $this->applyCreditToInvoice($invoice, $profile);

            Log::info("Billing: Invoice {$invoiceNumber} (sólo servicios adicionales) creada para el cliente "
                . "{$customerId} del router {$router->id} — no tiene plan que facturar.");

            try {
                $invoice->refresh()->load('tenant');

                if ((float) $invoice->balance_due > 0) {
                    $this->notifyInvoiceCreated($invoice, $profile, $billingConfig);
                }
            } catch (\Throwable $e) {
                Log::error("Billing: notify-on-create failed for invoice {$invoiceNumber}: {$e->getMessage()}");
            }

            return $invoice;
        });
    }

    /**
     * Qué servicios adicionales corresponde cobrarle a este cliente en este
     * periodo, ya con su monto resuelto. **Sólo calcula: no escribe nada.**
     *
     * Está separado del método que persiste porque hay dos llamantes con
     * necesidades distintas: la factura mensual aplica el resultado sobre una
     * factura que ya existe, mientras que la factura de excepción (cliente sin
     * plan que cobrar) necesita saber si hay algo que cobrar ANTES de crear la
     * factura — emitir una vacía gastaría un consecutivo y confundiría al
     * cliente. El filtro vive en un solo sitio para que las dos rutas no puedan
     * divergir.
     *
     * @return array<int, array{assignment_id:int, description:string, quantity:int, unit_price:float, amount:float}>
     */
    protected function chargeableAdditionalServices(
        int $tenantId,
        int $customerId,
        Carbon $periodStart,
        Carbon $periodEnd,
        bool $courtesyMonth = false,
    ): array {
        // withoutTenantScope + tenant_id explícito: el scope global deriva el
        // tenant del usuario autenticado y esto corre en el scheduler (sin
        // sesión) y también desde la UI (donde el usuario podría pertenecer a
        // un tenant distinto del de la factura que se está generando). Fijarlo
        // a mano es la única forma de que dé lo mismo quién dispare la corrida.
        $assignments = CustomerAdditionalService::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->with(['service' => fn ($q) => $q->withoutGlobalScope('tenant')])
            ->get();

        $lines = [];

        foreach ($assignments as $assignment) {
            $service = $assignment->service;

            if (!$service) {
                Log::warning("Billing: asignación {$assignment->id} sin servicio en el catálogo. Se omite.");
                continue;
            }

            if (!$assignment->coversPeriod($periodStart, $periodEnd)) {
                continue;
            }

            // Un servicio DESACTIVADO en el catálogo se sigue cobrando a quien
            // ya lo tiene: desactivar significa "no ofrecerlo más al asignar",
            // no "dejar de cobrárselo a 50 clientes en silencio". Para eso está
            // dar de baja la asignación, que es explícito y por cliente.

            if ($courtesyMonth && !$service->charge_on_courtesy_month) {
                continue;
            }

            if ($this->additionalServiceAlreadyBilled($assignment->id, $customerId, $periodStart, $periodEnd)) {
                continue;
            }

            $unitPrice   = $assignment->effective_price;
            $description = $service->name;

            if ($assignment->startsInsidePeriod($periodStart, $periodEnd)) {
                $mode = $service->proration_mode;

                // 'none': su primer cobro sale en el ciclo siguiente.
                if ($mode === Billing::FIRST_INVOICE_NONE) {
                    continue;
                }

                if ($mode === Billing::FIRST_INVOICE_PRORATED) {
                    $unitPrice = FirstInvoicePolicy::prorate($unitPrice, $assignment->starts_at, $periodStart);
                    $description .= " (proporcional desde el {$assignment->starts_at->format('d/m/Y')})";
                }
            }

            // Se redondea el PRECIO UNITARIO y después se multiplica por la
            // cantidad, no al revés: así unit_price × quantity = amount cuadra
            // exacto en la factura, que es lo que el cliente ve.
            $quantity = max(1, (int) $assignment->quantity);
            $amount   = round($unitPrice * $quantity, 2);

            if ($amount <= 0) {
                continue;
            }

            $lines[] = [
                'assignment_id' => (int) $assignment->id,
                'description'   => $description,
                'quantity'      => $quantity,
                'unit_price'    => $unitPrice,
                'amount'        => $amount,
            ];
        }

        return $lines;
    }

    /**
     * ¿Esta asignación ya se cobró en una factura que cubre este mes?
     *
     * Se compara por SOLAPE de periodos y no por igualdad de `period_start`:
     * una primera factura prorrateada arranca el día de la instalación, no el
     * 1º, así que una comparación exacta la dejaría fuera y cobraría dos veces.
     *
     * Sin filtrar por estado a propósito: que el ítem exista significa que ya se
     * cobró. Si la factura se borra, sus ítems se van con ella y el mes vuelve a
     * quedar libre — que es justo el comportamiento buscado.
     */
    protected function additionalServiceAlreadyBilled(
        int $assignmentId,
        int $customerId,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): bool {
        return InvoiceItem::where('customer_additional_service_id', $assignmentId)
            ->whereHas('invoice', function ($q) use ($customerId, $periodStart, $periodEnd) {
                $q->where('customer_id', $customerId)
                    ->where('period_start', '<=', $periodEnd->toDateString())
                    ->where('period_end', '>=', $periodStart->toDateString());
            })
            ->exists();
    }

    protected function applyPendingCarryoversTo(Invoice $invoice): void
    {
        $pending = InvoiceCarryover::pending()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('customer_id', $invoice->customer_id)
            ->with('fromInvoice:id,number')
            ->get();

        $total = round((float) $pending->sum('amount'), 2);

        if ($total <= 0) {
            return;
        }

        $numbers = $pending->pluck('fromInvoice.number')->filter()->unique()->values();
        $description = 'Saldo pendiente de facturas anteriores'
            . ($numbers->isNotEmpty() ? ' (#' . $numbers->implode(', #') . ')' : '');

        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'type'        => 'carryover',
            'description' => $description,
            'quantity'    => 1,
            'unit_price'  => $total,
            'amount'      => $total,
        ]);

        $invoice->subtotal    = (float) $invoice->subtotal + $total;
        $invoice->total       = (float) $invoice->total + $total;
        $invoice->balance_due = (float) $invoice->balance_due + $total;
        $invoice->carried_in  = (float) $invoice->carried_in + $total;
        $this->updateInvoiceStatus($invoice);
        $invoice->save();

        InvoiceCarryover::whereIn('id', $pending->pluck('id')->all())->update([
            'status'        => InvoiceCarryover::STATUS_APPLIED,
            'to_invoice_id' => $invoice->id,
            'applied_at'    => now(),
            'updated_at'    => now(),
        ]);

        Log::info("Billing: factura {$invoice->id} (#{$invoice->number}) cobra \${$total} "
            . "de saldo arrastrado del cliente {$invoice->customer_id}.");
    }

    /**
     * Deshace los arrastres que generó un pago y que TODAVÍA no ha cobrado
     * ninguna factura: el monto vuelve a la factura original.
     *
     * Los que ya viajaron a otra factura (status applied) se quedan donde están:
     * devolverlos cobraría dos veces el mismo dinero.
     */
    protected function revertPendingCarryoversOfPayment(Payment $payment): void
    {
        InvoiceCarryover::pending()
            ->where('payment_id', $payment->id)
            ->get()
            ->each(fn (InvoiceCarryover $row) => $this->revertCarryover($row));
    }

    /** Devuelve un arrastre pendiente a su factura de origen y borra el movimiento. */
    protected function revertCarryover(InvoiceCarryover $row): void
    {
        $invoice = $row->from_invoice_id ? Invoice::find($row->from_invoice_id) : null;

        if ($invoice) {
            $invoice->balance_due = (float) $invoice->balance_due + (float) $row->amount;
            $invoice->carried_out = max(0, (float) $invoice->carried_out - (float) $row->amount);
            $this->updateInvoiceStatus($invoice);
            $invoice->save();
        }

        $row->delete();
    }

    /**
     * Apply a customer's credit balance to a single invoice, reducing both.
     * Called automatically after each new invoice is created.
     *
     * Deja asiento en customer_credits. Antes no lo dejaba, y eso producía el
     * efecto que desató todo esto: una factura de $60.000 aparecía en caja por
     * $36.000 sin una sola fila que explicara los $24.000 de diferencia, y el
     * libro no cuadraba con lo que había entrado por caja.
     */
    protected function applyCreditToInvoice(Invoice $invoice, CustomerProfile $profile): void
    {
        if ($profile->credit_balance <= 0 || $invoice->balance_due <= 0) {
            return;
        }

        $apply = min((float) $profile->credit_balance, (float) $invoice->balance_due);

        $invoice->balance_due -= $apply;
        $this->updateInvoiceStatus($invoice);
        $invoice->save();

        // Escribe el movimiento y sincroniza credit_balance: no hay que tocar
        // el perfil aquí o el saldo se descontaría dos veces.
        CustomerCredit::applyToInvoice($invoice, (int) $profile->user_id, $apply);
        $profile->refresh();

        Log::info("Billing: Auto-applied \${$apply} credit to invoice {$invoice->id} for customer {$profile->user_id}. Remaining credit: {$profile->credit_balance}.");
    }

    /**
     * Audita el destino del dinero recibido, cliente por cliente. NO escribe nada.
     *
     * La invariante que comprueba es la más simple que tiene el módulo:
     *
     *     todo peso que entró está aplicado a una factura, o está en el saldo a favor
     *
     *     sum(payments.amount) == sum(payment_allocations.amount) + credit_balance
     *
     * Cuando la resta da positivo hay dinero recibido que no respalda ninguna
     * factura y tampoco figura como saldo: entró por caja y el sistema no sabe
     * decir qué pagó. Da igual qué lo provocó —borrar una factura pagada y no
     * reaplicar el saldo, un ajuste manual del saldo a la baja, o un pago que
     * nunca se asignó—: el síntoma es el mismo y es el que hay que ver.
     *
     * Se mide por cliente y no en total porque el total se compensa solo: a un
     * cliente le sobra lo que a otro le falta y el descuadre desaparece.
     *
     * @return array<int,array<string,mixed>> Una fila por cliente descuadrado,
     *                                        de mayor a menor importe.
     */
    public function auditOrphanPayments(?int $tenantId = null): array
    {
        $entrado = DB::table('payments')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->selectRaw('customer_id, count(*) as pagos, sum(amount) as entrado')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        if ($entrado->isEmpty()) {
            return [];
        }

        $aplicado = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->whereIn('p.customer_id', $entrado->keys())
            ->selectRaw('p.customer_id, sum(pa.amount) as aplicado')
            ->groupBy('p.customer_id')
            ->pluck('aplicado', 'customer_id');

        $perfiles = DB::table('customer_profile')
            ->whereIn('user_id', $entrado->keys())
            ->get(['user_id', 'name', 'last_name', 'credit_balance'])
            ->keyBy('user_id');

        $filas = [];

        foreach ($entrado as $customerId => $fila) {
            $perfil = $perfiles->get($customerId);

            $recibido = (float) $fila->entrado;
            $enFacturas = (float) ($aplicado[$customerId] ?? 0);
            $enSaldo = (float) ($perfil->credit_balance ?? 0);
            $suelto = round($recibido - $enFacturas - $enSaldo, 2);

            // Redondeo: los importes son decimal(·,2) y un céntimo suelto es
            // ruido de coma flotante, no un descuadre que valga una alerta.
            if ($suelto < 0.01) {
                continue;
            }

            $filas[] = [
                'customer_id' => (int) $customerId,
                'cliente'     => trim(($perfil->name ?? '') . ' ' . ($perfil->last_name ?? '')) ?: "cliente #{$customerId}",
                'pagos'       => (int) $fila->pagos,
                'recibido'    => $recibido,
                'en_facturas' => $enFacturas,
                'en_saldo'    => $enSaldo,
                'suelto'      => $suelto,
            ];
        }

        usort($filas, fn ($a, $b) => $b['suelto'] <=> $a['suelto']);

        return $filas;
    }

    /**
     * Aplica el saldo a favor del cliente a una factura creada a mano.
     *
     * La generación mensual siempre terminaba con applyCreditToInvoice(); el
     * alta manual, no. La diferencia se notaba justo en el peor momento: al
     * borrar una factura ya pagada y crear otra en su lugar, el dinero volvía
     * como saldo a favor y la factura nueva nacía debiendo el total, con lo que
     * el cliente aparecía debiendo algo que ya había pagado.
     */
    public function applyCreditToManualInvoice(Invoice $invoice): Invoice
    {
        $profile = CustomerProfile::where('user_id', $invoice->customer_id)->first();

        if ($profile) {
            $this->applyCreditToInvoice($invoice, $profile);
        }

        return $invoice->refresh();
    }

    /**
     * Update invoice status based on balance_due.
     *
     * @param Invoice $invoice
     */
    protected function updateInvoiceStatus(Invoice $invoice): void
    {
        if ($invoice->balance_due <= 0) {
            $invoice->status = 'paid';
        } elseif ($invoice->balance_due < $invoice->total) {
            $invoice->status = 'partial';
        } elseif ($invoice->due_date < now() && $invoice->balance_due > 0) {
            $invoice->status = 'overdue';
        } else {
            $invoice->status = 'issued';
        }
    }

    /**
     * Reverse all allocations of a payment and restore invoice balances.
     * Also removes any credit_balance that was generated by the payment's excess.
     */
    protected function reversePaymentAllocations(Payment $payment): void
    {
        // PRIMERO el arrastre: devuelve saldo a las mismas facturas que se
        // recargan abajo. Hacerlo después trabajaría sobre copias en memoria
        // distintas de la misma factura y el último save() pisaría al otro.
        $this->revertPendingCarryoversOfPayment($payment);

        $allocations = $payment->allocations()->with('invoice')->get();

        foreach ($allocations as $allocation) {
            $invoice = $allocation->invoice;
            if ($invoice) {
                $invoice->balance_due = (float) $invoice->balance_due + (float) $allocation->amount;
                $this->updateInvoiceStatus($invoice);
                $invoice->save();
            }
            $allocation->delete();
        }

        // Devolver el excedente que este pago había dejado como saldo a favor.
        //
        // Antes esto restaba el excedente COMPLETO del credit_balance sin mirar
        // si alguna factura posterior ya se lo había comido, y el max(0, ...)
        // tapaba el resultado. Con eso, anular un pago viejo borraba saldo que
        // venía de otros pagos: dinero real desapareciendo sin dejar rastro.
        //
        // Ahora el libro sabe cuánto de cada excedente sigue vivo y solo se
        // devuelve eso. Lo ya consumido se queda donde está —misma doctrina que
        // InvoiceCarryover— porque devolverlo cobraría dos veces la factura que
        // pagó.
        $reversal = CustomerCredit::reverseForPayment($payment);

        if ($reversal['kept'] > 0) {
            Log::warning(
                "Billing: al revertir el pago {$payment->id} se conservaron \${$reversal['kept']} " .
                "de saldo a favor que ya habían pagado facturas posteriores. " .
                "Devuelto: \${$reversal['reversed']}."
            );
        }
    }

    /**
     * Update a payment's metadata and re-allocate when amount changes.
     */
    public function updatePayment(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data) {
            $amountChanged = isset($data['amount']) && (float) $data['amount'] !== (float) $payment->amount;

            if ($amountChanged) {
                $this->reversePaymentAllocations($payment);
            }

            $payment->update([
                'amount'       => $data['amount']       ?? $payment->amount,
                'payment_date' => $data['payment_date'] ?? $payment->payment_date,
                'method'       => $data['method']       ?? $payment->method,
                'reference'    => array_key_exists('reference', $data) ? $data['reference'] : $payment->reference,
                'notes'        => array_key_exists('notes', $data) ? $data['notes'] : $payment->notes,
            ]);

            if ($amountChanged) {
                $this->allocatePayment($payment);
            }

            return $payment->load('allocations');
        });
    }

    /**
     * Delete a payment, reversing all its allocations and any credit generated.
     */
    public function deletePayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $this->reversePaymentAllocations($payment);
            $payment->delete();
        });
    }

    /**
     * Permanently delete an invoice, its items and its payment allocations.
     *
     * Any money that had been applied to it is returned to the customer as
     * credit so a received payment is never silently lost when the invoice is
     * removed (the payment record itself is kept for history). Hard delete —
     * for legal invoicing prefer voiding (status=cancelled) over deleting.
     *
     * Borrar es una DECISIÓN del administrador: se deja una lápida para que la
     * corrida horaria no vuelva a crear la misma factura. Sólo se bloquea ese
     * mes; la generación de los meses siguientes sigue igual.
     */
    public function deleteInvoice(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $this->suppressRegeneration($invoice);

            // Deuda arrastrada que ESTA factura estaba cobrando: vuelve a quedar
            // pendiente para la siguiente. Si no, borrar la factura del mes le
            // perdonaría al cliente un saldo que sí debe.
            InvoiceCarryover::where('to_invoice_id', $invoice->id)
                ->where('status', InvoiceCarryover::STATUS_APPLIED)
                ->update([
                    'status'        => InvoiceCarryover::STATUS_PENDING,
                    'to_invoice_id' => null,
                    'applied_at'    => null,
                    'updated_at'    => now(),
                ]);

            // Arrastres que ESTA factura generó y que nadie ha cobrado todavía:
            // mueren con ella (el abono que los originó se devuelve como crédito
            // unas líneas más abajo).
            InvoiceCarryover::pending()->where('from_invoice_id', $invoice->id)->delete();

            $allocations = PaymentAllocation::where('invoice_id', $invoice->id)->get();

            if ($allocations->isNotEmpty()) {
                foreach ($allocations as $allocation) {
                    // El dinero que había pagado esta factura vuelve al saldo a
                    // favor del cliente, atado al pago que lo trajo para que una
                    // anulación posterior de ese pago lo encuentre.
                    $payment = Payment::find($allocation->payment_id);
                    if ($payment) {
                        CustomerCredit::earn(
                            $payment,
                            (float) $allocation->amount,
                            "Factura {$invoice->number} eliminada: el pago #{$payment->id} vuelve a saldo a favor"
                        );
                    }
                    $allocation->delete();
                }
            }

            // Saldo a favor que había pagado esta factura: si la factura
            // desaparece, el saldo tiene que volver o el cliente lo pierde sin
            // que nadie se entere. Vuelve como ajuste y no des-consumiendo los
            // `earned` originales: es el lado conservador (nunca destruye
            // saldo), a costa de que anular después el pago de origen ya no
            // arrastre esta devolución.
            $creditApplied = CustomerCredit::where('to_invoice_id', $invoice->id)
                ->where('type', CustomerCredit::TYPE_APPLIED)
                ->sum('amount');

            if ($creditApplied < 0) {
                $profile = CustomerProfile::where('user_id', $invoice->customer_id)->first();
                if ($profile) {
                    CustomerCredit::adjust(
                        (int) $invoice->customer_id,
                        (float) $profile->credit_balance + abs((float) $creditApplied),
                        (float) $profile->credit_balance,
                        "Factura {$invoice->number} eliminada: se devuelve el saldo a favor que la había pagado"
                    );
                }
            }

            $invoice->items()->delete();
            $invoice->delete();

            Log::info("Billing: Invoice {$invoice->id} (#{$invoice->number}) deleted.");
        });
    }

    /**
     * Deja constancia de que el administrador borró la factura mensual de un
     * periodo, para que generateMonthlyInvoices() no la vuelva a crear.
     *
     * Sólo aplica a facturas mensuales: instalación, cargos y tickets no los
     * genera nadie automáticamente, así que no necesitan lápida.
     */
    protected function suppressRegeneration(Invoice $invoice): void
    {
        $type = $invoice->invoice_type ?: Invoice::TYPE_MONTHLY;

        if ($type !== Invoice::TYPE_MONTHLY || !$invoice->period_start) {
            return;
        }

        $periodStart = Carbon::parse($invoice->period_start)->startOfMonth();
        $periodEnd   = $periodStart->copy()->endOfMonth();
        $routerId    = CustomerProfile::where('user_id', $invoice->customer_id)->value('router_id');

        $attributes = [
            'router_id'     => $routerId,
            'period_end'    => $periodEnd->toDateString(),
            // La factura se está borrando: la referencia debe quedar vacía.
            'invoice_id'    => null,
            'status'        => BillingActionLog::STATUS_SUPPRESSED,
            'attempts'      => 0,
            'last_error'    => "Factura #{$invoice->number} eliminada por un administrador. "
                . 'No se regenerará automáticamente en este periodo.',
            'next_retry_at' => null,
        ];

        // Puede existir ya una fila (un intento fallido previo del mismo
        // periodo); se reescribe en vez de crear otra — hay un índice único
        // por (tenant, cliente, periodo, acción).
        $existing = $this->monthlyActionLogQuery(
            (int) $invoice->tenant_id, (int) $invoice->customer_id, $periodStart
        )->first();

        if ($existing) {
            $existing->update($attributes);
            return;
        }

        BillingActionLog::create($attributes + [
            'tenant_id'    => $invoice->tenant_id,
            'customer_id'  => $invoice->customer_id,
            'period_start' => $periodStart->toDateString(),
            'action'       => BillingActionLog::ACTION_GENERATE_MONTHLY,
        ]);
    }

    /**
     * Revert an invoice back to "owing": undo every payment allocation tied to
     * it, restore its balance to the full total, and recompute its status.
     *
     * A payment that funded ONLY this invoice is deleted (its money is undone).
     * A payment that also funded other invoices keeps those allocations; the
     * portion freed from this invoice becomes customer credit so the books stay
     * balanced (sum of allocations + credit still equals the payment amount).
     *
     * Used by the "Marcar como no pagada" action — operator correction and
     * testing the overdue → cut → pay → reconnect cycle.
     */
    public function markInvoiceUnpaid(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            // Work from the persisted balance, not a possibly-stale in-memory one.
            $invoice->refresh();

            // Saldo que esta factura había trasladado a la próxima y que aún no
            // ha cobrado nadie: vuelve a deberse aquí. El que ya viajó a otra
            // factura se queda allá — reclamarlo también aquí sería cobrarlo dos
            // veces —, así que la factura queda debiendo sólo su parte.
            foreach (InvoiceCarryover::pending()->where('from_invoice_id', $invoice->id)->get() as $row) {
                $invoice->balance_due = (float) $invoice->balance_due + (float) $row->amount;
                $invoice->carried_out = max(0, (float) $invoice->carried_out - (float) $row->amount);
                $row->delete();
            }

            $allocations = PaymentAllocation::where('invoice_id', $invoice->id)->get();

            foreach ($allocations as $allocation) {
                $invoice->balance_due = (float) $invoice->balance_due + (float) $allocation->amount;

                $payment = Payment::find($allocation->payment_id);
                $allocation->delete();

                if (!$payment) {
                    continue;
                }

                $remaining = PaymentAllocation::where('payment_id', $payment->id)->count();
                if ($remaining === 0) {
                    // Payment existed only for this invoice → undo it entirely.
                    $payment->delete();
                } else {
                    // Payment also funded other invoices → keep it and park the
                    // freed amount as customer credit so nothing is lost.
                    CustomerCredit::earn(
                        $payment,
                        (float) $allocation->amount,
                        "Factura {$invoice->number} marcada como no pagada: el pago #{$payment->id} vuelve a saldo a favor"
                    );
                }
            }

            $this->updateInvoiceStatus($invoice);
            $invoice->save();

            Log::info("Billing: Invoice {$invoice->id} marked unpaid; balance restored to {$invoice->balance_due}.");

            return $invoice->fresh(['customer', 'items', 'payments']);
        });
    }

    /**
     * Notify the customer that a new invoice was issued, using the channel
     * configured in the router's billing config (email / whatsapp / both).
     * Silent no-op when a channel is selected but contact info or external
     * credentials are missing — the failure is logged inside the caller's
     * try/catch.
     */
    protected function notifyInvoiceCreated(Invoice $invoice, CustomerProfile $profile, Billing $billingConfig): void
    {
        $customer = $invoice->customer;
        if (!$customer) {
            return;
        }

        // "No facturar": never notify an excluded customer, even if some other
        // code path created an invoice for them manually.
        if ($profile->exclude_from_billing) {
            return;
        }

        // Cliente con notificaciones silenciadas: la factura ya se generó
        // (líneas arriba) y se sigue generando igual todos los meses; esto
        // sólo apaga el aviso de email/WhatsApp.
        if (!$profile->notify_invoice) {
            return;
        }

        $periodLabel = $invoice->period_start
            ? Carbon::parse($invoice->period_start)->locale('es')->isoFormat('MMMM YYYY')
            : null;

        // Deuda TOTAL del cliente, esta factura incluida. Sin esto el aviso
        // decía "$50.000" al que en realidad debía $100.000 (la del mes pasado
        // seguía pendiente) y el cliente pagaba de menos.
        $pending = Invoice::where('tenant_id', $invoice->tenant_id)
            ->where('customer_id', $invoice->customer_id)
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', ['paid', 'void', 'cancelled'])
            ->get(['id', 'balance_due']);

        $data = [
            'customer_name'  => trim("{$profile->name} {$profile->last_name}") ?: ($customer->name ?? 'Cliente'),
            'invoice_number' => $invoice->number,
            'amount'         => $invoice->balance_due ?? $invoice->total,
            'due_date'       => $invoice->due_date,
            'issue_date'     => $invoice->issue_date,
            'company_name'   => $invoice->tenant?->name ?? 'ISPWatch',
            'period_label'   => $periodLabel,
            // Sólo se muestran si hay deuda anterior (pending_count > 1).
            'pending_count'  => $pending->count(),
            'pending_total'  => (float) $pending->sum('balance_due'),
            'previous_due'   => (float) $pending->where('id', '!=', $invoice->id)->sum('balance_due'),
        ];

        $type = $billingConfig->notification_type ?: 'email';

        if (in_array($type, ['email', 'both'], true) && $customer->email) {
            Mail::to($customer->email)->send(new InvoiceCreatedMail($data));
        }

        if (in_array($type, ['whatsapp', 'both'], true)) {
            $phone = $profile->phone ?? $customer->tel ?? null;
            if ($phone && $this->whatsAppService->isConfigured()) {
                $this->whatsAppService->sendInvoiceCreated($phone, $data);
            }
        }
    }

    /**
     * Mark overdue invoices and return list for suspension processing.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getOverdueInvoices()
    {
        $overdueInvoices = Invoice::where('due_date', '<', now())
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', ['void', 'cancelled', 'paid'])
            ->get();

        foreach ($overdueInvoices as $invoice) {
            if ($invoice->status !== 'overdue') {
                $invoice->status = 'overdue';
                $invoice->save();
            }
        }

        return $overdueInvoices;
    }
}
