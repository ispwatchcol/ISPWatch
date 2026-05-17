<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\Invoice;
use App\Models\CustomerProfile;
use App\Models\Router;
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

                    $success = $this->routerProvisioningService->suspendCustomer(
                        $customerId,
                        $router->id,
                        ['reason' => 'auto_cut_overdue', 'router_billing_config_id' => $billingConfig->id]
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
        $profiles = CustomerProfile::where('router_id', $router->id)
            ->where('status', true)
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
