<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\Invoice;
use App\Models\CustomerProfile;
use App\Models\Router;
use App\Models\SuspensionActionLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class OverdueSuspensionService
{
    protected $billingService;
    protected $routerProvisioningService;

    public function __construct(
        BillingService $billingService,
        RouterProvisioningService $routerProvisioningService
    ) {
        $this->billingService = $billingService;
        $this->routerProvisioningService = $routerProvisioningService;
    }

    /**
     * Process automatic cut for all routers (or a specific one) based on billing config.
     *
     * Conditions for cutting a customer:
     *  1. Router has cut_type = 'Corte Automático'
     *  2. Router has a billing config (billing_router_id)
     *  3. Today's day-of-month == billing.cut_day's day (or past it in the month)
     *  4. Current time >= billing.cut_time
     *  5. Customer has >= billing.overdue_invoices overdue invoices (balance_due > 0 && due_date < now)
     *
     * @param int|null $routerId  Limit processing to a specific router ID (null = all)
     * @return array  Statistics: [suspended, manual_pending, no_action, errors, routers_processed]
     */
    public function processOverdueInvoices(?int $routerId = null): array
    {
        $stats = [
            'suspended' => 0,
            'manual_pending' => 0,
            'no_action' => 0,
            'errors' => 0,
            'routers_processed' => 0,
        ];

        // Load routers with billing config and cut type
        $routerQuery = Router::with(['cutType', 'billingConfig'])
            ->whereNotNull('billing_router_id')
            ->whereNotNull('cut_type_id');

        if ($routerId !== null) {
            $routerQuery->where('id', $routerId);
        }

        $routers = $routerQuery->get();

        Log::info("Auto-cut: Processing {$routers->count()} router(s)" . ($routerId ? " (router #{$routerId})" : ''));

        $now = Carbon::now();

        foreach ($routers as $router) {
            $cutTypeName = $router->cutType?->name;
            $billingConfig = $router->billingConfig;

            // ── Skip if no billing config ──────────────────────────────────────
            if (!$billingConfig) {
                Log::warning("Auto-cut: Router {$router->id} has no billing config. Skipping.");
                continue;
            }

            // ── Handle Corte Manual (just queue / notify, no auto-suspend) ─────
            if ($cutTypeName === 'Corte Manual') {
                $stats['routers_processed']++;
                $customersForManual = $this->getEligibleCustomers($router, $billingConfig, checkTime: false);
                $stats['manual_pending'] += $customersForManual->count();
                Log::info("Auto-cut: Router {$router->id} is 'Corte Manual'. {$customersForManual->count()} cliente(s) pendientes de corte manual.");
                continue;
            }

            // ── Skip non-auto routers ──────────────────────────────────────────
            if ($cutTypeName !== 'Corte Automático') {
                Log::info("Auto-cut: Router {$router->id} is '{$cutTypeName}'. No action.");
                $stats['no_action']++;
                continue;
            }

            // ── Check cut_day (clamped to this month's length) ─────────────────
            // A configured day 31 becomes 30 in April / 28 in February so the
            // "último día" config still fires; other days stay as configured.
            $cutDayOfMonth = Billing::clampDayToMonth(
                Billing::dayOf($billingConfig->cut_day),
                $now
            );

            if ($cutDayOfMonth === null) {
                Log::warning("Auto-cut: Router {$router->id} billing config has no cut_day. Skipping.");
                $stats['errors']++;
                continue;
            }

            if ($now->day < $cutDayOfMonth) {
                Log::info("Auto-cut: Router {$router->id} — cut day is {$cutDayOfMonth}, today is {$now->day}. Not yet.");
                $stats['no_action']++;
                continue;
            }

            // ── Check cut_time (time of day) ───────────────────────────────────
            $cutTime = $billingConfig->cut_time ?? '00:00:00';
            [$cutH, $cutM, $cutS] = array_pad(explode(':', $cutTime), 3, 0);
            $cutDateTime = $now->copy()->setTime((int) $cutH, (int) $cutM, (int) $cutS);

            if ($now->lt($cutDateTime)) {
                Log::info("Auto-cut: Router {$router->id} — cut time is {$cutTime}, current time is {$now->format('H:i:s')}. Not yet.");
                $stats['no_action']++;
                continue;
            }

            // ── Get eligible customers for this router ─────────────────────────
            $eligibleCustomers = $this->getEligibleCustomers($router, $billingConfig, checkTime: true);

            $stats['routers_processed']++;
            Log::info("Auto-cut: Router {$router->id} — {$eligibleCustomers->count()} eligible customer(s) to process.");

            foreach ($eligibleCustomers as $profile) {
                try {
                    $customerId = $profile->user_id;

                    // Record the cut INTENT in the DB up front (status=false), so
                    // both the UI and the failover (reconcileSuspensions, which scans
                    // status=false) see it even if the RB call fails on this run.
                    // Mirrors the manual suspend path; without it auto-cut victims
                    // stayed status=true and the failover never retried them.
                    if ($profile->status !== false) {
                        $profile->update([
                            'status'         => false,
                            'service_status' => 'suspendido',
                        ]);
                    }

                    $success = $this->routerProvisioningService->suspendCustomer(
                        $customerId,
                        $router->id,
                        ['reason' => SuspensionActionLog::REASON_AUTO_CUT, 'router_billing_config_id' => $billingConfig->id]
                    );

                    if ($success) {
                        $stats['suspended']++;
                        Log::info("Auto-cut: Customer {$customerId} suspended (router {$router->id}).");
                    } else {
                        $stats['errors']++;
                        Log::error("Auto-cut: Failed to suspend customer {$customerId} (router {$router->id}).");
                    }
                } catch (\Exception $e) {
                    $stats['errors']++;
                    Log::error("Auto-cut: Exception suspending customer {$profile->user_id}: {$e->getMessage()}");
                }
            }
        }

        Log::info('Auto-cut: Processing complete.', $stats);

        return $stats;
    }

    /**
     * Audit (read-only) whether automatic cuts are actually happening. Mirrors
     * billing's no-show detector: auto-cut runs hourly, but if a router is
     * misconfigured (no cut_day) or the scheduler never runs, customers who
     * should be cut stay connected and nobody is alerted.
     *
     * Per 'Corte Automático' router it reports:
     *   - no_cut_day     : no cut_day configured → auto-cut OFF (valid, not alerted)
     *   - due            : cut day+time reached at least an hour ago (so an
     *                      hourly auto-cut tick has already had its chance)
     *   - still_eligible : ACTIVE customers meeting the overdue threshold that
     *                      therefore should already be suspended
     *   - status         : ok | pending | no_cut_day | cut_failing
     *
     * Writes nothing. Never cuts.
     *
     * @param int|null $routerId  Limit to a specific router (null = all).
     * @return array<int,array<string,mixed>>
     */
    public function auditAutomaticCuts(?int $routerId = null): array
    {
        $now  = Carbon::now();
        $rows = [];

        $routerQuery = Router::with(['cutType', 'billingConfig'])
            ->whereNotNull('billing_router_id')
            ->whereNotNull('cut_type_id');

        if ($routerId !== null) {
            $routerQuery->where('id', $routerId);
        }

        foreach ($routerQuery->get() as $router) {
            $billingConfig = $router->billingConfig;
            if (!$billingConfig || $router->cutType?->name !== 'Corte Automático') {
                continue;
            }

            $cutDay = Billing::clampDayToMonth(Billing::dayOf($billingConfig->cut_day), $now);

            $base = [
                'router_id'      => $router->id,
                'router_name'    => $router->name,
                'tenant_id'      => $router->tenant_id,
                'cut_day'        => $cutDay,
                'cut_time'       => $billingConfig->cut_time ?? '00:00:00',
                'threshold'      => max(1, (int) ($billingConfig->overdue_invoices ?? 1)),
                'due'            => false,
                'still_eligible' => 0,
            ];

            if ($cutDay === null) {
                // No cut day configured = automatic cut intentionally OFF for this
                // router. This is a valid choice, NOT a problem → never alerts.
                $rows[] = array_merge($base, ['status' => 'no_cut_day']);
                continue;
            }

            // Only flag once the cut moment is at least an hour in the past, so
            // a normal hourly auto-cut run has already had the chance to act
            // (avoids false positives in the gap right after cut_time).
            $cutTime = $billingConfig->cut_time ?? '00:00:00';
            [$h, $m, $s] = array_pad(explode(':', $cutTime), 3, 0);
            $cutMoment = $now->copy()->setTime((int) $h, (int) $m, (int) $s);
            $due = $now->day >= $cutDay && $now->gte($cutMoment->copy()->addHour());

            if (!$due) {
                $rows[] = array_merge($base, ['status' => 'pending']);
                continue;
            }

            $stillEligible = $this->getEligibleCustomers($router, $billingConfig)->count();

            $rows[] = array_merge($base, [
                'due'            => true,
                'still_eligible' => $stillEligible,
                'status'         => $stillEligible > 0 ? 'cut_failing' : 'ok',
            ]);
        }

        return $rows;
    }

    /**
     * Reconcile DB ⇄ router: re-apply the block on the RB for every customer that
     * is suspended in the DB (status = false) but whose cut is NOT confirmed on
     * the router, so both sides end up consistent and any failure is logged.
     *
     * "Confirmed cut" = the latest suspension_action_logs row for that
     * (customer, router) is action=SUSPEND with status=success. Any other state
     * (no log / last attempt failed / last action was UNSUSPEND) means the RB may
     * not actually be blocking the customer → re-assert (idempotent add + conntrack
     * flush). We never read the address-list (ssh-exec reads are fragile); we just
     * re-assert the desired state, which is safe because addSuspendedIpViaCore
     * treats "already have" as success.
     *
     * @param int|null $routerId  Limit to a specific router (null = all)
     * @param bool $dryRun  Report only, never touch the RB
     * @param bool $force   Ignore the per-customer retry backoff
     * @param int|null $tenantId  Limit to one tenant (null = all; used by the scheduler)
     * @return array  [scanned, already_confirmed, reblocked_ok, reblocked_failed, skipped_backoff]
     */
    public function reconcileSuspensions(?int $routerId = null, bool $dryRun = false, bool $force = false, ?int $tenantId = null): array
    {
        $stats = [
            'scanned'           => 0,
            'already_confirmed' => 0,
            'reblocked_ok'      => 0,
            'reblocked_failed'  => 0,
            'skipped_backoff'   => 0,
            'skipped_exhausted' => 0,
            'would_reblock'     => 0, // dry-run only
        ];

        // Source of truth = clients suspended in the DB with a router + IP.
        // customer_profile.status is a BOOLEAN column (false = not active);
        // see memory customer-profile-status-is-boolean.
        $query = CustomerProfile::where('status', false)
            ->whereNotNull('router_id')
            ->whereNotNull('ip_user');

        if ($routerId !== null) {
            $query->where('router_id', $routerId);
        }

        if ($tenantId !== null) {
            $query->whereHas('user', fn($q) => $q->where('tenant_id', $tenantId));
        }

        $profiles = $query->get();

        Log::info("Reconcile: scanning {$profiles->count()} DB-suspended customer(s)"
            . ($routerId ? " (router #{$routerId})" : '')
            . ($dryRun ? ' [dry-run]' : ''));

        foreach ($profiles as $profile) {
            $stats['scanned']++;

            $latest = SuspensionActionLog::where('customer_id', $profile->user_id)
                ->where('router_id', $profile->router_id)
                ->latest('id')
                ->first();

            // Already cut and confirmed on the RB → nothing to do.
            if (!$force
                && $latest
                && $latest->action === SuspensionActionLog::ACTION_SUSPEND
                && $latest->status === SuspensionActionLog::STATUS_SUCCESS
            ) {
                $stats['already_confirmed']++;
                continue;
            }

            // Exhausted: the SUSPEND failed and already consumed all its retries
            // (attempts >= MAX_ATTEMPTS) → stop auto-retrying and leave it for a
            // human. It stays a 'failed' row and surfaces in MassActions as
            // needs_manual. Mirrors billing's "exhausted" state. --force overrides.
            if (!$force
                && $latest
                && $latest->action === SuspensionActionLog::ACTION_SUSPEND
                && $latest->status === SuspensionActionLog::STATUS_FAILED
                && $latest->attempts >= SuspensionActionLog::MAX_ATTEMPTS
            ) {
                $stats['skipped_exhausted']++;
                continue;
            }

            // A recent failed SUSPEND still inside its backoff window → wait,
            // don't hammer a router that's down (unless forced).
            if (!$force
                && $latest
                && $latest->action === SuspensionActionLog::ACTION_SUSPEND
                && $latest->status === SuspensionActionLog::STATUS_FAILED
                && $latest->next_retry_at
                && $latest->next_retry_at->isFuture()
            ) {
                $stats['skipped_backoff']++;
                continue;
            }

            if ($dryRun) {
                // Count what WOULD be re-blocked without touching the RB.
                $stats['would_reblock']++;
                continue;
            }

            try {
                $ok = $this->routerProvisioningService->suspendCustomer(
                    (int) $profile->user_id,
                    (int) $profile->router_id,
                    ['reason' => SuspensionActionLog::REASON_RECONCILE]
                );

                if ($ok) {
                    $stats['reblocked_ok']++;
                    Log::info("Reconcile: customer {$profile->user_id} re-blocked on router {$profile->router_id}.");
                } else {
                    $stats['reblocked_failed']++;
                    Log::warning("Reconcile: failed to re-block customer {$profile->user_id} on router {$profile->router_id}.");
                }
            } catch (\Throwable $e) {
                $stats['reblocked_failed']++;
                Log::error("Reconcile: exception re-blocking customer {$profile->user_id}: {$e->getMessage()}");
            }
        }

        Log::info('Reconcile: complete.', $stats);

        return $stats;
    }

    /**
     * Get all customer profiles on the given router that have enough overdue invoices
     * to qualify for suspension.
     *
     * @param  Router   $router
     * @param  Billing  $billingConfig
     * @param  bool     $checkTime  (unused here, kept for signature clarity)
     * @return \Illuminate\Support\Collection<CustomerProfile>
     */
    protected function getEligibleCustomers(Router $router, Billing $billingConfig, bool $checkTime = true)
    {
        $maxOverdue = max(1, (int) ($billingConfig->overdue_invoices ?? 1));

        // All active customers assigned to this router.
        // customer_profile.status is a BOOLEAN column (true = active);
        // comparing to the string 'active' throws on PostgreSQL.
        // exclude_from_billing: clientes "no facturar" quedan fuera del corte
        // automático por mora (facturación manual / clientes especiales).
        $profiles = CustomerProfile::where('router_id', $router->id)
            ->where('status', true)
            ->where('exclude_from_billing', false)
            ->get();

        return $profiles->filter(function (CustomerProfile $profile) use ($maxOverdue) {
            $overdueCount = Invoice::where('customer_id', $profile->user_id)
                ->where('due_date', '<', now())
                ->where('balance_due', '>', 0)
                ->whereNotIn('status', ['void', 'cancelled', 'paid'])
                ->count();

            return $overdueCount >= $maxOverdue;
        });
    }
}
