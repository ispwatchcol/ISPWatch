<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Router;
use App\Services\VpnService;
use App\Services\MikroTikSshService;
use Illuminate\Support\Facades\Log;

class DebugVpnSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:debug-vpn-sync {router_id? : Optional Router ID to test with}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug MikroTik VPN Sync via SSH';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $logFile = base_path('vpn_debug_output.log');
        file_put_contents($logFile, "Starting VPN Sync (SSH Strategy) Debug...\n");

        $log = function ($msg) use ($logFile) {
            $this->info($msg);
            file_put_contents($logFile, $msg . "\n", FILE_APPEND);
        };

        $routerId = $this->argument('router_id');
        $router = $routerId ? Router::find($routerId) : Router::first();

        if (!$router) {
            $log("No router found.");
            return 1;
        }

        $log("Target: {$router->name} (ID: {$router->id})");
        $log("Current User: {$router->vpn_username}");

        $vpnService = new VpnService();

        // STEP 1: Generate Script (Triggers SSH Sync)
        $log("\n>>> STEP 1: Generate Script (calls syncPppSecret internally via SSH)");
        try {
            $log("Calling generateScript...");
            $script = $vpnService->generateScript($router);
            $log("Generate Script call completed.");

            $router->refresh();
            $log("New User: {$router->vpn_username}");
            $log("New Pass: {$router->vpn_password}");
            $log("\n--- GENERATED SCRIPT PREVIEW ---");
            $log($script);
            $log("----------------------------------\n");

        } catch (\Throwable $e) {
            $log("EXCEPTION during Generate Script: " . $e->getMessage());
            $log($e->getTraceAsString());
            return 1;
        }

        // STEP 2: Verify via SSH
        $log("\n>>> STEP 2: Verify Secret Existence via SSH Service");

        try {
            $ssh = new MikroTikSshService();
            $log("Connecting to SSH...");

            $check = $ssh->getPppSecret($router->vpn_username);

            $log("Check Result: " . json_encode($check, JSON_PRETTY_PRINT));

            if (($check['success'] ?? false) && ($check['found'] ?? false)) {
                $log("SUCCESS: Secret FOUND in MikroTik via SSH.");
            } else {
                $log("FAILURE: Secret NOT found (or SSH failed).");
            }

        } catch (\Throwable $e) {
            $log("EXCEPTION during Verification: " . $e->getMessage());
            $log($e->getTraceAsString());
        }

        return 0;
    }
}
