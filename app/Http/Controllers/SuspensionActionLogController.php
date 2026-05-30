<?php

namespace App\Http\Controllers;

use App\Models\SuspensionActionLog;
use App\Services\OverdueSuspensionService;
use App\Services\RouterProvisioningService;
use Illuminate\Http\Request;

/**
 * Failover de cortes: visibiliza y opera los intentos de corte/reactivación
 * en la RB (suspension_action_logs). Mirror de BillingActionLogController.
 *
 * La tabla no tiene tenant_id, así que el scoping es vía la relación customer
 * (users.tenant_id).
 */
class SuspensionActionLogController extends Controller
{
    public function __construct(
        protected RouterProvisioningService $provisioningService,
        protected OverdueSuspensionService $suspensionService,
    ) {
    }

    /**
     * Paginated list of suspension action logs (failed / pending / success).
     * Always tenant-scoped via the related customer.
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $query = SuspensionActionLog::query()
            ->with([
                'customer:id,user_name,email',
                'customer.customerProfile:user_id,name,last_name',
                'router:id,name',
            ])
            ->when($tenantId, fn($q) => $q->whereHas('customer', fn($c) => $c->where('tenant_id', $tenantId)));

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            // Por defecto: solo casos que requieren atención.
            $query->whereIn('status', [SuspensionActionLog::STATUS_FAILED, SuspensionActionLog::STATUS_PENDING]);
        }

        if ($request->filled('router_id')) {
            $query->where('router_id', $request->router_id);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        return response()->json(
            $query->orderByRaw("CASE status
                    WHEN 'failed' THEN 0
                    WHEN 'pending' THEN 1
                    WHEN 'success' THEN 2
                    ELSE 3 END")
                ->orderByDesc('updated_at')
                ->paginate(25)
        );
    }

    /**
     * Counts grouped by status for the KPIs / badge.
     */
    public function stats(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $base = SuspensionActionLog::query()
            ->when($tenantId, fn($q) => $q->whereHas('customer', fn($c) => $c->where('tenant_id', $tenantId)));

        $max = SuspensionActionLog::MAX_ATTEMPTS;

        return response()->json([
            'failed'       => (clone $base)->where('status', SuspensionActionLog::STATUS_FAILED)->count(),
            'pending'      => (clone $base)->where('status', SuspensionActionLog::STATUS_PENDING)->count(),
            'success'      => (clone $base)->where('status', SuspensionActionLog::STATUS_SUCCESS)->count(),
            // Agotados: fallaron y ya consumieron los intentos → acción manual.
            'needs_manual' => (clone $base)
                ->where('status', SuspensionActionLog::STATUS_FAILED)
                ->where('attempts', '>=', $max)
                ->count(),
            'ready_now'    => (clone $base)
                ->where('status', SuspensionActionLog::STATUS_FAILED)
                ->where('attempts', '<', $max)
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

        $log = SuspensionActionLog::where('id', $id)
            ->when($tenantId, fn($q) => $q->whereHas('customer', fn($c) => $c->where('tenant_id', $tenantId)))
            ->firstOrFail();

        // Reset backoff so the executor can re-attempt right away.
        $log->update(['next_retry_at' => null]);

        $context = ['reason' => SuspensionActionLog::REASON_MANUAL];

        $success = $log->action === SuspensionActionLog::ACTION_UNSUSPEND
            ? $this->provisioningService->unsuspendCustomer((int) $log->customer_id, (int) $log->router_id, $context)
            : $this->provisioningService->suspendCustomer((int) $log->customer_id, (int) $log->router_id, $context);

        return response()->json([
            'success' => $success,
            'log'     => $log->fresh()->load([
                'customer:id,user_name',
                'customer.customerProfile:user_id,name,last_name',
                'router:id,name',
            ]),
        ]);
    }

    /**
     * Run the DB ⇄ RB reconciliation for the current tenant (operator action).
     */
    public function reconcile(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;
        $routerId = $request->filled('router_id') ? (int) $request->router_id : null;

        $stats = $this->suspensionService->reconcileSuspensions(
            routerId: $routerId,
            dryRun:   false,
            force:    false,
            tenantId: $tenantId,
        );

        return response()->json($stats);
    }
}
