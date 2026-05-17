<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Models\Tenant;
use App\Models\Billing;
use App\Models\Invoice;
use App\Models\Router;
use App\Models\UserService;
use App\Models\Plan;
use App\Models\CustomerProfile;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingModuleTest extends TestCase
{
    use RefreshDatabase;

    protected $billingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billingService = app(BillingService::class);
        // Freeze on the 15th so the router's create_invoice gate is deterministic.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Set up a router with a billing config (create_invoice = today's day)
     * and one active customer assigned to it.
     *
     * @return array{tenant: Tenant, plan: Plan, router: Router, customer: User}
     */
    private function billingRouterWithCustomer(float $cost = 25000): array
    {
        $tenant = Tenant::factory()->create(['next_invoice_number' => 1]);
        $plan = Plan::factory()->create(['tenant_id' => $tenant->id, 'cost_product' => $cost]);

        $config = Billing::create([
            'create_invoice' => Carbon::create(2026, 1, 15)->toDateString(), // day 15
            'status' => 'pending',
        ]);

        $router = Router::create([
            'name' => 'Router ' . uniqid(),
            'tenant_id' => $tenant->id,
            'billing_router_id' => $config->id,
            'status' => 'active',
        ]);

        $customer = User::factory()->create(['tenant_id' => $tenant->id]);
        CustomerProfile::create([
            'user_id' => $customer->id,
            'name' => 'Test',
            'last_name' => 'Customer ' . uniqid(),
            'router_id' => $router->id,
            'status' => true, // boolean column (true = active), matches production
        ]);
        UserService::create([
            'user_id' => $customer->id,
            'service_plan_id' => $plan->id,
            'status' => 'active',
            'start_date' => now(),
        ]);

        return compact('tenant', 'plan', 'router', 'customer');
    }

    /** @test */
    public function it_generates_monthly_invoices_idempotently()
    {
        // Arrange: a billing router with one assigned active customer
        ['tenant' => $tenant, 'plan' => $plan, 'customer' => $customer] = $this->billingRouterWithCustomer(25000);

        $period = now()->format('Y-m');

        // Act: First run
        $count1 = $this->billingService->generateMonthlyInvoices($period);

        // Assert: One invoice created
        $this->assertEquals(1, $count1);
        $this->assertDatabaseHas('invoices', [
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'service_id' => $plan->id,
            'total' => 25000,
            'tax' => 0,
        ]);

        // Act: Second run (idempotency test)
        $count2 = $this->billingService->generateMonthlyInvoices($period);

        // Assert: No new invoices
        $this->assertEquals(0, $count2);
        $this->assertEquals(1, Invoice::count());
    }

    /** @test */
    public function it_generates_tenant_scoped_sequential_invoice_numbers()
    {
        // Arrange
        $tenant1 = Tenant::factory()->create(['next_invoice_number' => 1]);
        $tenant2 = Tenant::factory()->create(['next_invoice_number' => 1]);

        // Act
        $number1_t1 = $this->billingService->generateInvoiceNumber($tenant1->id);
        $number2_t1 = $this->billingService->generateInvoiceNumber($tenant1->id);
        $number1_t2 = $this->billingService->generateInvoiceNumber($tenant2->id);

        // Assert
        $this->assertEquals('00000001', $number1_t1);
        $this->assertEquals('00000002', $number2_t1);
        $this->assertEquals('00000001', $number1_t2); // Different tenant, resets

        // Verify tenant next_invoice_number incremented
        $this->assertEquals(3, $tenant1->fresh()->next_invoice_number);
        $this->assertEquals(2, $tenant2->fresh()->next_invoice_number);
    }

    /** @test */
    public function it_enforces_unique_invoice_numbers_per_tenant()
    {
        // Arrange
        $tenant = Tenant::factory()->create();
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);

        Invoice::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'number' => '00000001',
            'issue_date' => now(),
            'due_date' => now()->addDays(5),
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'total' => 1000,
            'balance_due' => 1000,
            'status' => 'issued',
        ]);

        // Act & Assert: Attempting duplicate number should fail
        $this->expectException(\Illuminate\Database\QueryException::class);

        Invoice::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'number' => '00000001', // Duplicate!
            'issue_date' => now(),
            'due_date' => now()->addDays(5),
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'total' => 2000,
            'balance_due' => 2000,
            'status' => 'issued',
        ]);
    }

    /** @test */
    public function it_allocates_full_payment_correctly()
    {
        // Arrange
        $tenant = Tenant::factory()->create();
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'number' => '00000001',
            'issue_date' => now(),
            'due_date' => now()->addDays(5),
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'total' => 25000,
            'balance_due' => 25000,
            'status' => 'issued',
        ]);

        // Act
        $payment = $this->billingService->registerPayment([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'amount' => 25000,
            'payment_date' => now(),
            'method' => 'cash',
        ]);

        // Assert
        $invoice->refresh();
        $this->assertEquals(0, $invoice->balance_due);
        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals(1, $payment->allocations->count());
        $this->assertEquals(25000, $payment->allocations->first()->amount);
    }

    /** @test */
    public function it_allocates_partial_payment_correctly()
    {
        // Arrange
        $tenant = Tenant::factory()->create();
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'number' => '00000001',
            'issue_date' => now(),
            'due_date' => now()->addDays(5),
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'total' => 25000,
            'balance_due' => 25000,
            'status' => 'issued',
        ]);

        // Act
        $payment = $this->billingService->registerPayment([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'amount' => 10000, // Partial payment
            'payment_date' => now(),
            'method' => 'transfer',
        ]);

        // Assert
        $invoice->refresh();
        $this->assertEquals(15000, $invoice->balance_due);
        $this->assertEquals('partial', $invoice->status);
        $this->assertEquals(10000, $payment->allocations->first()->amount);
    }

    /** @test */
    public function it_allocates_payment_to_oldest_invoices_first()
    {
        // Arrange
        $tenant = Tenant::factory()->create();
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);

        $oldInvoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'number' => '00000001',
            'issue_date' => now()->subMonth(),
            'due_date' => now()->subMonth()->addDays(5),
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
            'total' => 20000,
            'balance_due' => 20000,
            'status' => 'overdue',
        ]);

        $newInvoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'number' => '00000002',
            'issue_date' => now(),
            'due_date' => now()->addDays(5),
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'total' => 25000,
            'balance_due' => 25000,
            'status' => 'issued',
        ]);

        // Act: Pay 30000 (should pay old invoice fully + 10000 to new)
        $payment = $this->billingService->registerPayment([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'amount' => 30000,
            'payment_date' => now(),
            'method' => 'cash',
        ]);

        // Assert
        $oldInvoice->refresh();
        $newInvoice->refresh();

        $this->assertEquals(0, $oldInvoice->balance_due);
        $this->assertEquals('paid', $oldInvoice->status);

        $this->assertEquals(15000, $newInvoice->balance_due);
        $this->assertEquals('partial', $newInvoice->status);

        $this->assertEquals(2, $payment->allocations->count());
    }

    /** @test */
    public function it_marks_invoices_as_overdue_when_past_due_date()
    {
        // Arrange
        $tenant = Tenant::factory()->create();
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'number' => '00000001',
            'issue_date' => now()->subDays(10),
            'due_date' => now()->subDays(5), // Past due
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'total' => 25000,
            'balance_due' => 25000,
            'status' => 'issued',
        ]);

        // Act
        $overdueInvoices = $this->billingService->getOverdueInvoices();

        // Assert
        $this->assertEquals(1, $overdueInvoices->count());
        $invoice->refresh();
        $this->assertEquals('overdue', $invoice->status);
    }

    /** @test */
    public function it_skips_customers_on_a_billing_router_without_an_active_service()
    {
        // Arrange: a fully-configured billing router whose customer has NO
        // active service — it must NOT be invoiced.
        ['router' => $router, 'tenant' => $tenant] = $this->billingRouterWithCustomer();

        $orphan = User::factory()->create(['tenant_id' => $tenant->id]);
        CustomerProfile::create([
            'user_id' => $orphan->id,
            'name' => 'No',
            'last_name' => 'Service ' . uniqid(),
            'router_id' => $router->id,
            'status' => true,
        ]);
        // No UserService row at all for $orphan.

        // Act
        $count = $this->billingService->generateMonthlyInvoices();

        // Assert: only the properly-configured customer is invoiced.
        $this->assertEquals(1, $count);
        $this->assertEquals(0, Invoice::where('customer_id', $orphan->id)->count());
    }
}
