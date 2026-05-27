<?php

namespace App\Http\Controllers;

use App\Models\BillingActionLog;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BillingActionLogController extends Controller
{
    public function __construct(protected BillingService $billingService)
    {
    }

    /**
     * Paginated list of billing action logs (failed / exhausted / success).
     * Always tenant-scoped via the authenticated user.
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $query = BillingActionLog::query()
            ->with([
                'customer:id,user_name,email',
                'customer.customerProfile:id,user_id,name,last_name,phone',
                'router:id,name',
                'invoice:id,number,total,balance_due,status',
            ])
            ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId));

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            // Por defecto: solo casos problemáticos
            $query->whereIn('status', [BillingActionLog::STATUS_FAILED, BillingActionLog::STATUS_EXHAUSTED]);
        }

        if ($request->filled('router_id')) {
            $query->where('router_id', $request->router_id);
        }

        if ($request->filled('period')) {
            try {
                $period = Carbon::parse($request->period . '-01')->startOfMonth();
                $query->where('period_start', $period->toDateString());
            } catch (\Throwable $e) {
                // Ignore invalid period
            }
        }

        return response()->json(
            $query->orderByRaw("CASE status
                    WHEN 'exhausted' THEN 0
                    WHEN 'failed' THEN 1
                    WHEN 'success' THEN 2
                    ELSE 3 END")
                ->orderBy('next_retry_at', 'asc')
                ->orderByDesc('updated_at')
                ->paginate(25)
        );
    }

    /**
     * Counts grouped by status for the badge / dashboard.
     */
    public function stats(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;
        $period   = $request->filled('period')
            ? Carbon::parse($request->period . '-01')->startOfMonth()->toDateString()
            : null;

        $base = BillingActionLog::query()
            ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
            ->when($period,   fn($q) => $q->where('period_start', $period));

        return response()->json([
            'failed'    => (clone $base)->where('status', BillingActionLog::STATUS_FAILED)->count(),
            'exhausted' => (clone $base)->where('status', BillingActionLog::STATUS_EXHAUSTED)->count(),
            'success'   => (clone $base)->where('status', BillingActionLog::STATUS_SUCCESS)->count(),
            'ready_now' => (clone $base)
                ->where('status', BillingActionLog::STATUS_FAILED)
                ->where('attempts', '<', BillingActionLog::MAX_ATTEMPTS)
                ->where(function ($q) {
                    $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
                })
                ->count(),
        ]);
    }

    /**
     * Manual retry for a single log row. Ignores backoff (operator override).
     */
    public function retry(Request $request, int $id)
    {
        $tenantId = $request->user()?->tenant_id;

        $log = BillingActionLog::where('id', $id)
            ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
            ->firstOrFail();

        // Operator override: reset backoff and (if exhausted) decrement attempts so it can retry.
        if ($log->status === BillingActionLog::STATUS_EXHAUSTED) {
            $log->update([
                'status'        => BillingActionLog::STATUS_FAILED,
                'attempts'      => max(0, BillingActionLog::MAX_ATTEMPTS - 1),
                'next_retry_at' => null,
            ]);
        } else {
            $log->update(['next_retry_at' => null]);
        }
        $log->refresh();

        $success = $this->billingService->retryFailedInvoice($log);

        return response()->json([
            'success' => $success,
            'log'     => $log->fresh()->load([
                'customer:id,user_name',
                'customer.customerProfile:id,user_id,name,last_name',
                'invoice:id,number',
            ]),
        ]);
    }

    /**
     * Retry all failed/exhausted rows in the current period (bulk operator action).
     */
    public function retryAll(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;
        $period   = $request->filled('period')
            ? Carbon::parse($request->period . '-01')->startOfMonth()->toDateString()
            : null;

        $query = BillingActionLog::query()
            ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
            ->when($period,   fn($q) => $q->where('period_start', $period))
            ->whereIn('status', [BillingActionLog::STATUS_FAILED, BillingActionLog::STATUS_EXHAUSTED]);

        $logs = $query->limit(500)->get();

        $ok = 0;
        $ko = 0;
        foreach ($logs as $log) {
            if ($log->status === BillingActionLog::STATUS_EXHAUSTED) {
                $log->update([
                    'status'        => BillingActionLog::STATUS_FAILED,
                    'attempts'      => max(0, BillingActionLog::MAX_ATTEMPTS - 1),
                    'next_retry_at' => null,
                ]);
            } else {
                $log->update(['next_retry_at' => null]);
            }
            $log->refresh();

            $this->billingService->retryFailedInvoice($log)
                ? $ok++
                : $ko++;
        }

        return response()->json([
            'processed' => $logs->count(),
            'success'   => $ok,
            'failed'    => $ko,
        ]);
    }
}
