<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BillingService
{
    /**
     * Generate monthly invoices for all active customers.
     */
    public function generateMonthlyInvoices($periodDate = null)
    {
        $date = $periodDate ? Carbon::parse($periodDate) : Carbon::now();
        $periodStart = $date->copy()->startOfMonth();
        $periodEnd = $date->copy()->endOfMonth();
        $issueDate = $periodStart->copy(); // Issued on 1st
        $dueDate = $issueDate->copy()->addDays(5);

        // Fetch active customers with a service plan
        $customers = User::where('status', true)
            ->whereNotNull('service_id')
            // Filter by role if necessary (assuming 3 is client based on Plan.php)
            ->where('role_id', 3)
            ->with('customerProfile') // To get other details if needed
            ->get();

        $count = 0;

        foreach ($customers as $customer) {
            // Check existence
            $exists = Invoice::where('customer_id', $customer->id)
                ->where('period_start', $periodStart->format('Y-m-d'))
                ->exists();

            if ($exists) {
                continue;
            }

            $this->createInvoiceForCustomer($customer, $periodStart, $periodEnd, $issueDate, $dueDate);
            $count++;
        }

        return $count;
    }

    public function createInvoiceForCustomer(User $customer, $periodStart, $periodEnd, $issueDate, $dueDate)
    {
        DB::transaction(function () use ($customer, $periodStart, $periodEnd, $issueDate, $dueDate) {

            // Get Plan Price
            // Assuming User -> service_id links to Plan
            $plan = Plan::find($customer->service_id);
            $planCost = $plan ? $plan->cost_product : 0;
            $planName = $plan ? $plan->name : 'Internet Service';

            // Create Invoice
            $invoice = Invoice::create([
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'service_id' => $customer->service_id,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => 'issued',
                'subtotal' => 0,
                'total' => 0,
                'balance_due' => 0,
                'number' => $this->generateInvoiceNumber($customer->tenant_id)
            ]);

            // Add Plan Item
            $item = InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'type' => 'plan',
                'description' => $planName . ' (' . $periodStart->format('M Y') . ')',
                'quantity' => 1,
                'unit_price' => $planCost,
                'amount' => $planCost
            ]);

            // Update Totals
            $invoice->subtotal = $item->amount;
            $invoice->total = $invoice->subtotal; // Add Tax here if needed
            $invoice->balance_due = $invoice->total;
            $invoice->save();
        });
    }

    public function registerPayment($data)
    {
        return DB::transaction(function () use ($data) {
            $payment = Payment::create([
                'tenant_id' => $data['tenant_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'completed'
            ]);

            $remainingAmount = $data['amount'];

            // Auto-allocate logic if not specified
            // 1. Get open invoices ordered by due date (oldest first)
            $invoices = Invoice::where('customer_id', $data['customer_id'])
                ->where('balance_due', '>', 0)
                ->whereIn('status', ['issued', 'overdue', 'partial'])
                ->orderBy('due_date', 'asc')
                ->get();

            foreach ($invoices as $invoice) {
                if ($remainingAmount <= 0)
                    break;

                $amountToPay = min($remainingAmount, $invoice->balance_due);

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $amountToPay
                ]);

                // Update Invoice
                $invoice->balance_due -= $amountToPay;
                $remainingAmount -= $amountToPay;

                if ($invoice->balance_due <= 0) {
                    $invoice->status = 'paid';
                    $invoice->balance_due = 0; // Floating point safety
                } else {
                    $invoice->status = 'partial';
                }
                $invoice->save();
            }

            return $payment;
        });
    }

    private function generateInvoiceNumber($tenantId)
    {
        // Simple generation: INV-YYYYMM-{ID} or similar. 
        // Using timestamp + random or max id.
        // For consecutive: count invoices + 1.
        $count = Invoice::where('tenant_id', $tenantId)->count();
        return 'INV-' . str_pad($count + 1, 6, '0', STR_PAD_LEFT);
    }
}
