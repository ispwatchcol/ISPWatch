<?php

namespace App\Services;

use App\Models\Router;
use App\Models\SuspensionActionLog;
use Illuminate\Support\Facades\Log;

class RouterPolicyInstallerService
{
    const ADDRESS_LIST_NAME = 'ISPWATCH_BLOCKED';
    const RULE_COMMENT = 'ISPWatch: blocked customers';

    /**
     * Ensure the blocking policy (address-list + firewall rules) exists on the router.
     * Idempotent: safe to run multiple times.
     *
     * @param Router $router
     * @return bool
     */
    public function ensurePolicyInstalled(Router $router): bool
    {
        try {
            // Check if policy already installed
            if ($this->isPolicyInstalled($router)) {
                Log::info("Blocking policy already installed on router {$router->id}");
                return true;
            }

            // Log the installation attempt
            $log = SuspensionActionLog::create([
                'router_id' => $router->id,
                'customer_id' => null,
                'ip' => null,
                'action' => 'INSTALL_POLICY',
                'status' => 'pending',
            ]);

            // Install firewall filter rules
            $success = $this->installFirewallRules($router);

            if ($success) {
                $log->update(['status' => 'success']);
                Log::info("Blocking policy successfully installed on router {$router->id}");
                return true;
            } else {
                $log->update([
                    'status' => 'failed',
                    'error_message' => 'Failed to install firewall rules',
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Failed to install blocking policy on router {$router->id}: {$e->getMessage()}");

            if (isset($log)) {
                $log->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            return false;
        }
    }

    /**
     * Check if blocking policy is already installed on the router.
     *
     * @param Router $router
     * @return bool
     */
    protected function isPolicyInstalled(Router $router): bool
    {
        // TODO: Query MikroTik RouterOS API to check if firewall rules with our comment exist
        Log::info("STUB: Checking if policy installed on router {$router->id}");

        // Example:
        // $api = new RouterosAPI();
        // $api->connect($router->ip, $router->user_rb, $router->password_rb);
        // $api->write('/ip/firewall/filter/print', false);
        // $api->write('?comment=' . self::RULE_COMMENT);
        // $read = $api->read();
        // $api->disconnect();
        // return !empty($read);

        return false; // Stub: assume not installed
    }

    /**
     * Install firewall filter rules to drop traffic from ISPWATCH_BLOCKED address-list.
     *
     * @param Router $router
     * @return bool
     */
    protected function installFirewallRules(Router $router): bool
    {
        // TODO: Use MikroTik RouterOS API to create firewall filter rules
        Log::info("STUB: Installing firewall rules on router {$router->id} ({$router->ip})");

        // Example implementation:
        // The policy creates a firewall filter rule that drops packets from src-address-list=ISPWATCH_BLOCKED
        //
        // $api = new RouterosAPI();
        // $api->connect($router->ip, $router->user_rb, $router->password_rb);
        //
        // // Rule to drop forward traffic from blocked IPs
        // $api->write('/ip/firewall/filter/add', false);
        // $api->write('=chain=forward', false);
        // $api->write('=src-address-list=' . self::ADDRESS_LIST_NAME, false);
        // $api->write('=action=drop', false);
        // $api->write('=comment=' . self::RULE_COMMENT, false);
        // $api->write('=place-before=0'); // Place at the top of the chain
        // $api->read();
        //
        // $api->disconnect();

        return true; // Stub returns success
    }

    /**
     * Remove blocking policy from router (for decommissioning/cleanup).
     *
     * @param Router $router
     * @return bool
     */
    public function removePolicyFromRouter(Router $router): bool
    {
        try {
            Log::info("STUB: Removing blocking policy from router {$router->id}");

            // TODO: Use MikroTik API to remove rules with matching comment
            // $api = new RouterosAPI();
            // $api->connect($router->ip, $router->user_rb, $router->password_rb);
            // $api->write('/ip/firewall/filter/print', false);
            // $api->write('?comment=' . self::RULE_COMMENT);
            // $read = $api->read();
            // foreach ($read as $rule) {
            //     $api->write('/ip/firewall/filter/remove', false);
            //     $api->write('=.id=' . $rule['.id']);
            //     $api->read();
            // }
            // $api->disconnect();

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to remove policy from router {$router->id}: {$e->getMessage()}");
            return false;
        }
    }
}
