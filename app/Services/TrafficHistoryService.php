<?php

namespace App\Services;

use App\Models\Router;
use App\Models\TrafficSample;
use App\Models\TrafficDaily;
use App\Services\MikroTik\MikroTikConnectionManager;
use Illuminate\Support\Facades\Log;

/**
 * Traffic History Service
 *
 * Router/WAN-level traffic history: samples the cumulative byte counters of
 * each router's WAN interface on a schedule, stores the per-interval delta as a
 * fine sample (short retention) and rolls it up into a daily aggregate
 * (long-term). Per-client breakdown is intentionally out of scope.
 *
 * Reads go through the proven CORE ssh-exec path with the same hardened result
 * detection as the other managers.
 */
class TrafficHistoryService
{
    private MikroTikConnectionManager $connectionManager;

    public function __construct(?MikroTikConnectionManager $connectionManager = null)
    {
        $this->connectionManager = $connectionManager ?? new MikroTikConnectionManager();
    }

    /**
     * Sample WAN traffic for every router with `historial_trafico` enabled.
     * Returns a summary [sampled, failed, total].
     */
    public function collect(): array
    {
        $routers = Router::query()
            ->where('historial_trafico', true)
            ->whereNotNull('wan_interface')
            ->where('wan_interface', '!=', '')
            ->whereNotNull('ip')
            ->get();

        $sampled = 0;
        $failed  = 0;
        $now     = now();

        foreach ($routers as $router) {
            try {
                $counters = $this->readWanCounters($router);
                if ($counters === null) {
                    $failed++;
                    Log::warning('[TrafficHistory] No se pudieron leer contadores WAN', [
                        'router_id' => $router->id, 'ip' => $router->ip, 'wan' => $router->wan_interface,
                    ]);
                    continue;
                }

                $this->storeSample($router, $counters['rx'], $counters['tx'], $now);
                $sampled++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('[TrafficHistory] Error muestreando router', [
                    'router_id' => $router->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[TrafficHistory] Recolección completada', [
            'sampled' => $sampled, 'failed' => $failed, 'total' => $routers->count(),
        ]);

        return ['sampled' => $sampled, 'failed' => $failed, 'total' => $routers->count()];
    }

    /**
     * Compute the per-interval delta vs the last sample and persist the sample
     * plus the daily rollup.
     */
    private function storeSample(Router $router, int $rxCounter, int $txCounter, $now): void
    {
        $last = TrafficSample::where('router_id', $router->id)->latest('sampled_at')->first();

        // First sample → baseline (no consumption yet). Counter reset
        // (current < previous, e.g. router reboot) → record 0 for this interval
        // and re-baseline, so a reboot never produces a false multi-TB spike.
        $rxDelta = 0;
        $txDelta = 0;
        if ($last) {
            $rxDelta = $rxCounter >= $last->rx_counter ? $rxCounter - $last->rx_counter : 0;
            $txDelta = $txCounter >= $last->tx_counter ? $txCounter - $last->tx_counter : 0;
        }

        TrafficSample::create([
            'router_id'  => $router->id,
            'rx_bytes'   => $rxDelta,
            'tx_bytes'   => $txDelta,
            'rx_counter' => $rxCounter,
            'tx_counter' => $txCounter,
            'sampled_at' => $now,
        ]);

        if ($rxDelta > 0 || $txDelta > 0) {
            $row = TrafficDaily::firstOrNew(['router_id' => $router->id, 'day' => $now->toDateString()]);
            $row->rx_bytes = ($row->rx_bytes ?? 0) + $rxDelta;
            $row->tx_bytes = ($row->tx_bytes ?? 0) + $txDelta;
            $row->save();
        }
    }

    /**
     * Read cumulative rx-byte/tx-byte of the router's WAN interface via CORE
     * ssh-exec. Returns ['rx' => int, 'tx' => int] or null on failure.
     */
    private function readWanCounters(Router $router): ?array
    {
        $wan = trim((string) $router->wan_interface);
        if ($wan === '') {
            return null;
        }

        // WAN names may contain spaces (e.g. "ether5 WAN") → must stay quoted.
        $wanEsc = addcslashes($wan, "\\\"\$");
        $inner  = ':put ([/interface get [find name="' . $wanEsc . '"] rx-byte].",".[/interface get [find name="' . $wanEsc . '"] tx-byte])';

        $safe = str_replace('"', '\\"', (string) $router->password_rb);
        $core = "/system ssh-exec address={$router->ip} user={$router->user_rb} password=\"{$safe}\" command=\"" . addslashes($inner) . "\"";

        $res = $this->connectionManager->executeSsh($core);
        if (!($res['success'] ?? false)) {
            return null;
        }

        $out = (string) ($res['output'] ?? '');
        // `/system ssh-exec` returns "exit-code: N ... output: <rx>,<tx>".
        if ((bool) preg_match('/exit-code:\s*[1-9]/i', $out)) {
            return null;
        }
        if (!preg_match('/(\d+)\s*,\s*(\d+)/', $out, $m)) {
            return null;
        }

        return ['rx' => (int) $m[1], 'tx' => (int) $m[2]];
    }

    /**
     * Delete fine samples older than $days. Daily aggregates are kept.
     */
    public function prune(int $days = 30): int
    {
        return TrafficSample::where('sampled_at', '<', now()->subDays($days))->delete();
    }
}
