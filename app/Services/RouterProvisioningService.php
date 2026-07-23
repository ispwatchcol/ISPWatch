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
     *
     * @param array $context  ['reason' => manual|auto_cut_overdue|reconcile, ...]
     */
    public function suspendCustomer(int $customerId, int $routerId, array $context = []): bool
    {
        $reason = $context['reason'] ?? SuspensionActionLog::REASON_MANUAL;

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

            $log = $this->openLogFor($customerId, $routerId, SuspensionActionLog::ACTION_SUSPEND, $ip, $reason);

            $policyInstaller = new RouterPolicyInstallerService();
            $policyInstaller->ensurePolicyInstalled($router);

            $success = $this->addIpToSuspendedList($router, $ip, $customer);

            if ($success) {
                $this->markLogSuccess($log);
                Log::info("Customer {$customerId} suspended on router {$routerId}. IP: {$ip}");
                return true;
            }

            $this->markLogFailed($log, 'Failed to add IP to suspended list');
            return false;

        } catch (\Exception $e) {
            Log::error("Failed to suspend customer {$customerId}: {$e->getMessage()}");
            if (isset($log)) {
                $this->markLogFailed($log, $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Unsuspend a customer by removing their IP from the ISPWATCH_SUSPENDIDOS list.
     *
     * @param array $context  ['reason' => manual|reconcile, ...]
     */
    public function unsuspendCustomer(int $customerId, int $routerId, array $context = []): bool
    {
        $reason = $context['reason'] ?? SuspensionActionLog::REASON_MANUAL;

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

            $log = $this->openLogFor($customerId, $routerId, SuspensionActionLog::ACTION_UNSUSPEND, $ip, $reason);

            $success = $this->removeIpFromSuspendedList($router, $ip);

            if ($success) {
                $this->markLogSuccess($log);
                Log::info("Customer {$customerId} unsuspended on router {$routerId}. IP: {$ip}");
                return true;
            }

            $this->markLogFailed($log, 'Failed to remove IP from suspended list');
            return false;

        } catch (\Exception $e) {
            Log::error("Failed to unsuspend customer {$customerId}: {$e->getMessage()}");
            if (isset($log)) {
                $this->markLogFailed($log, $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Reuse the "open" (pending/failed) log row for this customer+router+action
     * so attempts/backoff accumulate across retries instead of spawning a new
     * row each run. A previous `success` row is left as history and a fresh row
     * is opened. Mirrors the failover model of billing_action_logs.
     */
    private function openLogFor(int $customerId, int $routerId, string $action, string $ip, string $reason): SuspensionActionLog
    {
        $open = SuspensionActionLog::where('customer_id', $customerId)
            ->where('router_id', $routerId)
            ->where('action', $action)
            ->whereIn('status', [SuspensionActionLog::STATUS_PENDING, SuspensionActionLog::STATUS_FAILED])
            ->latest('id')
            ->first();

        if ($open) {
            $open->update([
                'ip'     => $ip,
                'reason' => $reason,
                'status' => SuspensionActionLog::STATUS_PENDING,
            ]);
            return $open;
        }

        return SuspensionActionLog::create([
            'router_id'   => $routerId,
            'customer_id' => $customerId,
            'ip'          => $ip,
            'action'      => $action,
            'reason'      => $reason,
            'status'      => SuspensionActionLog::STATUS_PENDING,
            'attempts'    => 0,
        ]);
    }

    private function markLogSuccess(SuspensionActionLog $log): void
    {
        $log->update([
            'status'        => SuspensionActionLog::STATUS_SUCCESS,
            'attempts'      => $log->attempts + 1,
            'error_message' => null,
            'next_retry_at' => null,
        ]);
    }

    private function markLogFailed(SuspensionActionLog $log, string $error): void
    {
        $attempts  = $log->attempts + 1;
        $exhausted = $attempts >= SuspensionActionLog::MAX_ATTEMPTS;

        $log->update([
            'status'        => SuspensionActionLog::STATUS_FAILED,
            'attempts'      => $attempts,
            'error_message' => $error,
            'next_retry_at' => $exhausted ? null : now()->addSeconds(
                SuspensionActionLog::RETRY_BACKOFF_SECONDS[$attempts] ?? 3600
            ),
        ]);
    }

    private function addIpToSuspendedList(Router $router, string $ip, CustomerProfile $customer): bool
    {
        $customerName = trim("{$customer->name} {$customer->last_name}");
        // router.ip drifts on every L2TP reconnect and SSH may not be on 22 —
        // a cut that silently targets the wrong endpoint leaves the customer
        // connected while billing believes they were suspended.
        $endpoint = app(\App\Services\MikroTik\RouterEndpointResolver::class)->resolve($router);

        $result = $this->suspensionManager->addSuspendedIpViaCore(
            $endpoint['ip'],
            $router->user_rb,
            $router->password_rb,
            $ip,
            $customerName,
            $endpoint['api_port'],
            $endpoint['ssh_port']
        );

        if (!$result['success']) {
            Log::error("[RouterProvisioning] Error suspending IP {$ip} on router {$router->id}: " . ($result['message'] ?? 'unknown'));
        }

        return $result['success'];
    }

    private function removeIpFromSuspendedList(Router $router, string $ip): bool
    {
        $endpoint = app(\App\Services\MikroTik\RouterEndpointResolver::class)->resolve($router);

        $result = $this->suspensionManager->removeSuspendedIpViaCore(
            $endpoint['ip'],
            $router->user_rb,
            $router->password_rb,
            $ip,
            $endpoint['api_port'],
            $endpoint['ssh_port']
        );

        if (!$result['success']) {
            Log::error("[RouterProvisioning] Error unsuspending IP {$ip} on router {$router->id}: " . ($result['message'] ?? 'unknown'));
        }

        return $result['success'];
    }
}
