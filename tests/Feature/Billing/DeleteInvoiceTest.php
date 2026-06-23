<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeleteInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(Tenant $tenant, User $user, float $total = 60000): Invoice
    {
        $invoice = Invoice::create([
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

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'type'       => 'plan',
            'description'=> 'Servicio mensual',
            'quantity'   => 1,
            'unit_price' => $total,
            'amount'     => $total,
        ]);

        return $invoice;
    }

    #[Test]
    public function it_deletes_an_unpaid_invoice_and_its_items(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        CustomerProfile::create(['user_id' => $user->id, 'name' => 'Axel', 'last_name' => 'Cañón']);

        $invoice = $this->makeInvoice($tenant, $user, 60000);

        app(BillingService::class)->deleteInvoice($invoice);

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertSame(0, InvoiceItem::where('invoice_id', $invoice->id)->count());
    }

    #[Test]
    public function deleting_a_paid_invoice_returns_the_payment_as_credit_and_keeps_the_payment(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        $profile = CustomerProfile::create(['user_id' => $user->id, 'name' => 'Axel', 'last_name' => 'Cañón']);

        $invoice = $this->makeInvoice($tenant, $user, 60000);

        $service = app(BillingService::class);
        $service->registerPayment([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => 60000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ]);

        $service->deleteInvoice($invoice);

        $profile->refresh();
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertSame(1, Payment::where('customer_id', $user->id)->count(), 'Payment record is preserved');
        $this->assertEquals(60000, (float) $profile->credit_balance, 'Paid amount returned as credit');
    }
}
