<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\CustomerProfile;
use App\Models\Router;
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
     * Process overdue invoices and suspend customers according to cut_type.
     *
     * @return array Statistics: [suspended, manual_pending, no_action, errors]
     */
    public function processOverdueInvoices(): array
    {
        $stats = [
            'suspended' => 0,
            'manual_pending' => 0,
            'no_action' => 0,
            'errors' => 0,
        ];

        // Get all overdue invoices
        $overdueInvoices = $this->billingService->getOverdueInvoices();

        Log::info("Processing {$overdueInvoices->count()} overdue invoices for suspension");

        foreach ($overdueInvoices as $invoice) {
            try {
                $customerId = $invoice->customer_id;

                // Get customer profile with router info
                $customerProfile = CustomerProfile::where('user_id', $customerId)->first();

                if (!$customerProfile) {
                    Log::warning("Customer {$customerId} has no profile. Skipping suspension.");
                    $stats['errors']++;
                    continue;
                }

                if (!$customerProfile->router_id) {
                    Log::warning("Customer {$customerId} has no router assigned. Skipping suspension.");
                    $stats['errors']++;
                    continue;
                }

                $router = Router::with('cutType')->find($customerProfile->router_id);

                if (!$router) {
                    Log::warning("Router {$customerProfile->router_id} not found for customer {$customerId}. Skipping.");
                    $stats['errors']++;
                    continue;
                }

                $cutTypeName = $router->cutType?->name;

                if (!$cutTypeName) {
                    Log::warning("Router {$router->id} has no cut_type. Skipping suspension for customer {$customerId}.");
                    $stats['errors']++;
                    continue;
                }

                // Process based on cut_type
                switch ($cutTypeName) {
                    case 'Corte Automático':
                        // Automatic suspension
                        $success = $this->routerProvisioningService->suspendCustomer(
                            $customerId,
                            $router->id,
                            ['invoice_id' => $invoice->id, 'reason' => 'overdue']
                        );

                        if ($success) {
                            $stats['suspended']++;
                            Log::info("Customer {$customerId} automatically suspended due to overdue invoice {$invoice->number}");
                        } else {
                            $stats['errors']++;
                            Log::error("Failed to automatically suspend customer {$customerId}");
                        }
                        break;

                    case 'Corte Manual':
                        // Manual suspension: create a task/flag for manual action
                        Log::info("Customer {$customerId} marked for MANUAL suspension (invoice {$invoice->number})");
                        // TODO: Create a "pending suspension" record in a manual_suspension_queue table
                        // or send notification to admin
                        $stats['manual_pending']++;
                        break;

                    case 'Sin Corte':
                        // No suspension, only mark overdue
                        Log::info("Customer {$customerId} has 'Sin Corte' policy. No suspension for invoice {$invoice->number}");
                        $stats['no_action']++;
                        break;

                    default:
                        Log::warning("Unknown cut_type '{$cutTypeName}' for router {$router->id}. Skipping customer {$customerId}.");
                        $stats['errors']++;
                        break;
                }
            } catch (\Exception $e) {
                Log::error("Error processing overdue invoice {$invoice->id}: {$e->getMessage()}");
                $stats['errors']++;
            }
        }

        Log::info("Overdue suspension processing complete", $stats);

        return $stats;
    }
}
