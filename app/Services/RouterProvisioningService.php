<?php

namespace App\Services;

use App\Models\Router;
use App\Models\CustomerProfile;
use App\Models\SuspensionActionLog;
use App\Services\MikroTik\SuspensionManager;
use Illuminate\Support\Facades\Log;

class RouterProvisioningService
{
    public function __construct(
        private SuspensionManager $suspensionManager = new SuspensionManager()
    ) {}

    /**
     * Suspend a customer by adding their IP to the ISPWATCH_SUSPENDIDOS list.
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

            $log = SuspensionActionLog::create([
                'router_id'   => $routerId,
                'customer_id' => $customerId,
                'ip'          => $ip,
                'action'      => 'SUSPEND',
                'status'      => 'pending',
            ]);

            $policyInstaller = new RouterPolicyInstallerService();
            $policyInstaller->ensurePolicyInstalled($router);

            $success = $this->addIpToSuspendedList($router, $ip, $customer);

            if ($success) {
                $log->update(['status' => 'success']);
                Log::info("Customer {$customerId} suspended on router {$routerId}. IP: {$ip}");
                return true;
            }

            $log->update(['status' => 'failed', 'error_message' => 'Failed to add IP to suspended list']);
            return false;

        } catch (\Exception $e) {
            Log::error("Failed to suspend customer {$customerId}: {$e->getMessage()}");
            if (isset($log)) {
                $log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            }
            return false;
        }
    }

    /**
     * Unsuspend a customer by removing their IP from the ISPWATCH_SUSPENDIDOS list.
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

            $log = SuspensionActionLog::create([
                'router_id'   => $routerId,
                'customer_id' => $customerId,
                'ip'          => $ip,
                'action'      => 'UNSUSPEND',
                'status'      => 'pending',
            ]);

            $success = $this->removeIpFromSuspendedList($router, $ip);

            if ($success) {
                $log->update(['status' => 'success']);
                Log::info("Customer {$customerId} unsuspended on router {$routerId}. IP: {$ip}");
                return true;
            }

            $log->update(['status' => 'failed', 'error_message' => 'Failed to remove IP from suspended list']);
            return false;

        } catch (\Exception $e) {
            Log::error("Failed to unsuspend customer {$customerId}: {$e->getMessage()}");
            if (isset($log)) {
                $log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            }
            return false;
        }
    }

    private function addIpToSuspendedList(Router $router, string $ip, CustomerProfile $customer): bool
    {
        $customerName = trim("{$customer->name} {$customer->last_name}");
        $port = (int) ($router->puerto_api ?: 8728);

        $result = $this->suspensionManager->addSuspendedIpViaCore(
            $router->ip,
            $router->user_rb,
            $router->password_rb,
            $ip,
            $customerName,
            $port
        );

        if (!$result['success']) {
            Log::error("[RouterProvisioning] Error suspending IP {$ip} on router {$router->id}: " . ($result['message'] ?? 'unknown'));
        }

        return $result['success'];
    }

    private function removeIpFromSuspendedList(Router $router, string $ip): bool
    {
        $port = (int) ($router->puerto_api ?: 8728);

        $result = $this->suspensionManager->removeSuspendedIpViaCore(
            $router->ip,
            $router->user_rb,
            $router->password_rb,
            $ip,
            $port
        );

        if (!$result['success']) {
            Log::error("[RouterProvisioning] Error unsuspending IP {$ip} on router {$router->id}: " . ($result['message'] ?? 'unknown'));
        }

        return $result['success'];
    }
}
