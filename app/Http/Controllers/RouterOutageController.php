<?php

namespace App\Http\Controllers;

use App\Models\CustomerProfile;
use App\Models\Router;
use App\Models\RouterOutageEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Falla masiva" broadcast per core/router.
 *
 * The operator flips a core into failure (or back) from the Routers screen.
 * Each action:
 *   1. mirrors the state on router.falla_general (keeps the existing red badge
 *      and dashboard alert in sync), and
 *   2. appends a row to router_outage_events.
 *
 * Converza (read-only, id-cursor polling) picks up the new event and fans out
 * the matching WhatsApp template to every ACTIVE customer on the core. ISPWatch
 * itself never sends the messages — it only records the signal.
 */
class RouterOutageController extends Controller
{
    /** Current outage state + how many customers a broadcast would reach + recent history. */
    public function show(Router $router)
    {
        return response()->json([
            'router_id'      => $router->id,
            'falla_general'  => (bool) $router->falla_general,
            'affected_count' => $this->activeCustomerCount($router),
            'recent'         => RouterOutageEvent::where('router_id', $router->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get(['id', 'type', 'affected_count', 'created_by', 'created_at']),
        ]);
    }

    /** Mark the core in failure and record the outage event to broadcast. */
    public function notify(Router $router)
    {
        return $this->record($router, RouterOutageEvent::TYPE_OUTAGE, true);
    }

    /** Mark the core restored and record the recovery event to broadcast. */
    public function resolve(Router $router)
    {
        return $this->record($router, RouterOutageEvent::TYPE_RESTORED, false);
    }

    /**
     * Shared path: set falla_general and append the event atomically.
     */
    private function record(Router $router, string $type, bool $inFailure)
    {
        $affected = $this->activeCustomerCount($router);

        $event = DB::transaction(function () use ($router, $type, $inFailure, $affected) {
            // Keep the boolean state in sync so the badge/dashboard reflect reality.
            if ((bool) $router->falla_general !== $inFailure) {
                $router->update(['falla_general' => $inFailure]);
            }

            return RouterOutageEvent::create([
                'tenant_id'      => $router->tenant_id,
                'router_id'      => $router->id,
                'type'           => $type,
                'affected_count' => $affected,
                'created_by'     => auth()->id(),
                'created_at'     => now(),
            ]);
        });

        Log::info("Outage: router {$router->id} ({$router->name}) marked '{$type}' by user "
            . (auth()->id() ?? 'system') . "; {$affected} active customer(s) to notify.");

        $message = $type === RouterOutageEvent::TYPE_OUTAGE
            ? "Falla registrada. Se notificará a {$affected} cliente(s) conectado(s) a este core."
            : "Restablecimiento registrado. Se notificará a {$affected} cliente(s) conectado(s) a este core.";

        return response()->json([
            'message'        => $message,
            'router_id'      => $router->id,
            'falla_general'  => $inFailure,
            'affected_count' => $affected,
            'event'          => $event,
        ]);
    }

    /** Active customers physically connected to this core (recipients of the notice). */
    private function activeCustomerCount(Router $router): int
    {
        return CustomerProfile::where('router_id', $router->id)
            ->where('status', true)
            ->count();
    }
}
