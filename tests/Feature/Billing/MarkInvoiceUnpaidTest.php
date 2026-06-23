<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Marcar como no pagada": reverts a paid invoice back to owing by undoing its
 * payment allocations, restoring the balance, and recomputing the status.
 */
class MarkInvoiceUnpaidTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(Tenant $tenant, User $user, float $total = 60000): Invoice
    {
        return Invoice::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'number'       => uniqid('INV-'),
            'issue_date'   => now()->subDays(30),
            'due_date'     => now()->subDays(10),
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end'   => now()->subMonth()->endOfMonth(),
            'subtotal'     => $total,
            'total'        => $total,
            'balance_due'  => $total,
            'status'       => 'overdue',
        ]);
    }

    #[Test]
    public function it_reverts_a_fully_paid_invoice_and_deletes_the_payment(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        CustomerProfile::create([
            'user_id' => $user->id, 'name' => 'Axel', 'last_name' => 'Cañón',
        ]);

        $invoice = $this->makeInvoice($tenant, $user, 60000);

        $service = app(BillingService::class);
        $service->registerPayment([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => 60000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ]);

        // Sanity: payment cleared the invoice.
        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(0, (float) $invoice->balance_due);

        $service->markInvoiceUnpaid($invoice);

        $invoice->refresh();
        $this->assertEquals(60000, (float) $invoice->balance_due);
        $this->assertSame('overdue', $invoice->status); // due_date is in the past
        $this->assertSame(0, Payment::where('customer_id', $user->id)->count());
        $this->assertSame(0, PaymentAllocation::where('invoice_id', $invoice->id)->count());
    }

    #[Test]
    public function a_payment_covering_other_invoices_is_kept_and_excess_becomes_credit(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        $profile = CustomerProfile::create([
            'user_id' => $user->id, 'name' => 'Axel', 'last_name' => 'Cañón',
        ]);

        $invoiceA = $this->makeInvoice($tenant, $user, 60000);
        $invoiceB = $this->makeInvoice($tenant, $user, 40000);

        // One payment clears both (FIFO by due date; both same due date here).
        $service = app(BillingService::class);
        $service->registerPayment([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => 100000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ]);

        // Revert only invoice A. Its payment also funded invoice B, so the
        // payment stays and A's freed amount becomes credit.
        $service->markInvoiceUnpaid($invoiceA);

        $invoiceA->refresh();
        $invoiceB->refresh();
        $profile->refresh();

        $this->assertEquals(60000, (float) $invoiceA->balance_due);
        $this->assertEquals(0, (float) $invoiceB->balance_due, 'Invoice B stays paid');
        $this->assertSame(1, Payment::where('customer_id', $user->id)->count(), 'Payment is preserved');
        $this->assertEquals(60000, (float) $profile->credit_balance, 'Freed amount parked as credit');
    }
}
