<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-layer smoke tests for the billing module. Runs on the in-memory
 * sqlite test DB (see phpunit.xml) — real data is never touched.
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        // The billing endpoints sit behind permission middleware; give the
        // acting user a wildcard role.
        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        $this->plan = Plan::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'cost_product' => 50000,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_can_list_invoices(): void
    {
        Sanctum::actingAs($this->user);

        Invoice::create([
            'customer_id'  => $this->user->id,
            'tenant_id'    => $this->tenant->id,
            'issue_date'   => now(),
            'due_date'     => now()->addDays(5),
            'period_start' => now()->startOfMonth(),
            'period_end'   => now()->endOfMonth(),
            'total'        => 50000,
            'balance_due'  => 50000,
            'status'       => 'issued',
            'number'       => 'INV-001',
        ]);

        $response = $this->getJson('/api/billing/invoices');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_can_register_payment(): void
    {
        Sanctum::actingAs($this->user);

        $invoice = Invoice::create([
            'customer_id'  => $this->user->id,
            'tenant_id'    => $this->tenant->id,
            'issue_date'   => now(),
            'due_date'     => now()->addDays(5),
            'period_start' => now()->startOfMonth(),
            'period_end'   => now()->endOfMonth(),
            'total'        => 50000,
            'balance_due'  => 50000,
            'status'       => 'issued',
            'number'       => 'INV-001',
        ]);

        $response = $this->postJson('/api/billing/payments', [
            'customer_id'  => $this->user->id,
            'amount'       => 50000,
            'payment_date' => now()->format('Y-m-d'),
            'method'       => 'cash',
            'tenant_id'    => $this->tenant->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('payments', ['amount' => 50000]);

        $invoice->refresh();
        $this->assertEquals(0, $invoice->balance_due);
        $this->assertEquals('paid', $invoice->status);
    }

    public function test_admin_can_generate_monthly_invoices_via_endpoint(): void
    {
        // Freeze on the configured create_invoice day.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));
        Sanctum::actingAs($this->user);

        // A router with billing config + one assigned active customer.
        $config = Billing::create([
            'create_invoice' => Carbon::create(2026, 1, 15)->toDateString(),
            'status'         => 'pending',
        ]);
        $router = Router::create([
            'name'              => 'Router A',
            'tenant_id'         => $this->tenant->id,
            'billing_router_id' => $config->id,
            'status'            => 'active',
        ]);
        $customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id'   => $customer->id,
            'name'      => 'Cliente',
            'last_name' => 'Facturable',
            'router_id' => $router->id,
            'status'    => true, // boolean column (true = active)
        ]);
        UserService::create([
            'user_id'         => $customer->id,
            'service_plan_id' => $this->plan->id,
            'status'          => 'active',
            'start_date'      => now(),
        ]);

        $this->assertEquals(0, Invoice::count());

        $response = $this->postJson('/api/billing/run-monthly', ['period' => '2026-06']);

        $response->assertStatus(200);
        $this->assertEquals(1, Invoice::count());

        $invoice = Invoice::firstOrFail();
        $this->assertEquals($customer->id, $invoice->customer_id);
        $this->assertEquals($this->plan->cost_product, $invoice->total);
    }
}
