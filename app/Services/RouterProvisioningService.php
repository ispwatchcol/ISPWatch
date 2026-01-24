<?php

namespace App\Services;

use App\Models\Router;
use App\Models\CustomerProfile;
use App\Models\SuspensionActionLog;
use Illuminate\Support\Facades\Log;

class RouterProvisioningService
{
    /**
     * Suspend a customer by adding their IP to the ISPWATCH_BLOCKED list.
     *
     * @param int $customerId
     * @param int $routerId
     * @param array $context Additional context (invoice_id, reason, etc.)
     * @return bool
     */
    public function suspendCustomer(int $customerId, int $routerId, array $context = []): bool
    {
        try {
            $router = Router::with('cutType')->find($routerId);
            if (!$router) {
                throw new \Exception("Router {$routerId} not found");
            }

            $customer = CustomerProfile::where('user_id', $customerId)->first();
            if (!$customer) {
                throw new \Exception("Customer profile for user {$customerId} not found");
            }

            $ip = $customer->ip_user;
            if (!$ip) {
                throw new \Exception("Customer {$customerId} has no IP assigned");
            }

            // Log the suspension attempt
            $log = SuspensionActionLog::create([
                'router_id' => $routerId,
                'customer_id' => $customerId,
                'ip' => $ip,
                'action' => 'SUSPEND',
                'status' => 'pending',
            ]);

            // Ensure blocking policy is installed on router
            $policyInstaller = new RouterPolicyInstallerService();
            $policyInstaller->ensurePolicyInstalled($router);

            // Add IP to ISPWATCH_BLOCKED address-list via MikroTik API
            $success = $this->addIpToBlockedList($router, $ip, $customerId);

            if ($success) {
                $log->update(['status' => 'success']);
                Log::info("Customer {$customerId} suspended on router {$routerId}. IP: {$ip}");
                return true;
            } else {
                $log->update(['status' => 'failed', 'error_message' => 'Failed to add IP to blocked list']);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Failed to suspend customer {$customerId}: {$e->getMessage()}");

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
     * Unsuspend a customer by removing their IP from the ISPWATCH_BLOCKED list.
     *
     * @param int $customerId
     * @param int $routerId
     * @param array $context
     * @return bool
     */
    public function unsuspendCustomer(int $customerId, int $routerId, array $context = []): bool
    {
        try {
            $router = Router::find($routerId);
            if (!$router) {
                throw new \Exception("Router {$routerId} not found");
            }

            $customer = CustomerProfile::where('user_id', $customerId)->first();
            if (!$customer) {
                throw new \Exception("Customer profile for user {$customerId} not found");
            }

            $ip = $customer->ip_user;
            if (!$ip) {
                throw new \Exception("Customer {$customerId} has no IP assigned");
            }

            // Log the unsuspension attempt
            $log = SuspensionActionLog::create([
                'router_id' => $routerId,
                'customer_id' => $customerId,
                'ip' => $ip,
                'action' => 'UNSUSPEND',
                'status' => 'pending',
            ]);

            // Remove IP from ISPWATCH_BLOCKED address-list via MikroTik API
            $success = $this->removeIpFromBlockedList($router, $ip, $customerId);

            if ($success) {
                $log->update(['status' => 'success']);
                Log::info("Customer {$customerId} unsuspended on router {$routerId}. IP: {$ip}");
                return true;
            } else {
                $log->update(['status' => 'failed', 'error_message' => 'Failed to remove IP from blocked list']);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Failed to unsuspend customer {$customerId}: {$e->getMessage()}");

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
     * Add IP to ISPWATCH_BLOCKED address-list on the router.
     * 
     * @param Router $router
     * @param string $ip
     * @param int $customerId
     * @return bool
     */
    protected function addIpToBlockedList(Router $router, string $ip, int $customerId): bool
    {
        // TODO: Implement MikroTik RouterOS API integration
        // For now, this is a stub that logs the action

        Log::info("STUB: Would add IP {$ip} for customer {$customerId} to ISPWATCH_BLOCKED on router {$router->id} ({$router->ip})");

        // Example MikroTik API integration (requires RouterOS-API library):
        // $api = new RouterosAPI();
        // $api->connect($router->ip, $router->user_rb, $router->password_rb);
        // $api->write('/ip/firewall/address-list/add', false);
        // $api->write('=list=ISPWATCH_BLOCKED', false);
        // $api->write('=address=' . $ip, false);
        // $api->write('=comment=ISPWatch: Customer ' . $customerId);
        // $api->read();
        // $api->disconnect();

        return true; // Stub returns success
    }

    /**
     * Remove IP from ISPWATCH_BLOCKED address-list on the router.
     *
     * @param Router $router
     * @param string $ip
     * @param int $customerId
     * @return bool
     */
    protected function removeIpFromBlockedList(Router $router, string $ip, int $customerId): bool
    {
        // TODO: Implement MikroTik RouterOS API integration
        Log::info("STUB: Would remove IP {$ip} for customer {$customerId} from ISPWATCH_BLOCKED on router {$router->id} ({$router->ip})");

        // Example MikroTik API integration:
        // $api = new RouterosAPI();
        // $api->connect($router->ip, $router->user_rb, $router->password_rb);
        // $api->write('/ip/firewall/address-list/print', false);
        // $api->write('?list=ISPWATCH_BLOCKED', false);
        // $api->write('?address=' . $ip);
        // $read = $api->read();
        // if (!empty($read)) {
        //     $api->write('/ip/firewall/address-list/remove', false);
        //     $api->write('=.id=' . $read[0]['.id']);
        //     $api->read();
        // }
        // $api->disconnect();

        return true; // Stub returns success
    }
}
