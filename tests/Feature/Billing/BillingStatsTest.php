<?php

namespace Tests\Feature\Billing;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Panel de Finanzas (`GET /api/billing/stats`).
 *
 * El panel devolvía el acumulado histórico del ISP, sumaba facturas anuladas y
 * no sabía nada de los gastos. Estas pruebas fijan el contrato nuevo: cifras de
 * un mes, sin anuladas, con gastos y balance de caja — y el tenant tomado del
 * usuario autenticado, no del query param.
 */
class BillingStatsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'Admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);
        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);

        Sanctum::actingAs($this->admin);
    }

    private function invoice(string $issueDate, float $total, float $balanceDue, string $status = 'issued'): Invoice
    {
        return Invoice::create([
            'tenant_id'   => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'number'       => 'INV-' . fake()->unique()->numerify('######'),
            'issue_date'   => $issueDate,
            'due_date'     => $issueDate,
            'period_start' => $issueDate,
            'period_end'   => $issueDate,
            'subtotal'    => $total,
            'total'       => $total,
            'balance_due' => $balanceDue,
            'status'      => $status,
        ]);
    }

    private function payment(string $date, float $amount, string $status = 'completed'): Payment
    {
        return Payment::create([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->customer->id,
            'amount'       => $amount,
            'payment_date' => $date,
            'method'       => 'cash',
            'status'       => $status,
        ]);
    }

    private function expense(string $date, float $amount, string $status = Expense::STATUS_ACTIVE): Expense
    {
        $expense = new Expense([
            'expense_date' => $date,
            'amount'       => $amount,
            'description'  => 'Gasto de prueba',
            'status'       => $status,
        ]);
        $expense->tenant_id = $this->tenant->id;
        $expense->save();

        return $expense;
    }

    private function allocate(Payment $payment, Invoice $invoice, float $amount): void
    {
        DB::table('payment_allocations')->insert([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount'     => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_only_counts_the_requested_month(): void
    {
        $this->invoice('2026-07-05', 100000, 100000);   // mes anterior
        $this->invoice('2026-08-05', 250000, 250000);   // el mes pedido
        $this->payment('2026-07-10', 100000);
        $this->payment('2026-08-10', 180000);

        $response = $this->getJson('/api/billing/stats?month=2026-08')->assertOk();

        $this->assertEquals(250000, $response->json('summary.total_invoiced'));
        $this->assertEquals(180000, $response->json('summary.total_paid'));
        $this->assertSame('2026-08', $response->json('period.month'));
        $this->assertSame('Agosto 2026', $response->json('period.label'));
    }

    #[Test]
    public function void_invoices_and_payments_do_not_count(): void
    {
        $this->invoice('2026-08-05', 100000, 100000);
        $this->invoice('2026-08-06', 999999, 999999, 'void');
        $this->invoice('2026-08-07', 888888, 888888, 'cancelled');
        $this->payment('2026-08-10', 50000);
        $this->payment('2026-08-11', 777777, 'void');

        $response = $this->getJson('/api/billing/stats?month=2026-08')->assertOk();

        $this->assertEquals(100000, $response->json('summary.total_invoiced'));
        $this->assertEquals(50000, $response->json('summary.total_paid'));
        // La cartera acumulada tampoco arrastra las anuladas.
        $this->assertEquals(100000, $response->json('summary.total_pending'));
    }

    #[Test]
    public function expenses_are_subtracted_as_a_cash_balance(): void
    {
        $this->invoice('2026-08-05', 500000, 500000);
        $this->payment('2026-08-10', 300000);
        $this->expense('2026-08-12', 120000);
        $this->expense('2026-08-13', 30000, Expense::STATUS_VOID);   // anulado: no cuenta
        $this->expense('2026-07-20', 999999);                        // otro mes: no cuenta

        $response = $this->getJson('/api/billing/stats?month=2026-08')->assertOk();

        $this->assertEquals(120000, $response->json('summary.total_expenses'));
        // Caja: recaudado − gastos. NO facturado − gastos.
        $this->assertEquals(180000, $response->json('summary.balance'));
    }

    #[Test]
    public function the_carry_over_debt_is_cumulative_not_monthly(): void
    {
        $this->invoice('2026-05-05', 80000, 80000);    // mora vieja
        $this->invoice('2026-08-05', 120000, 120000);

        $response = $this->getJson('/api/billing/stats?month=2026-08')->assertOk();

        $this->assertEquals(120000, $response->json('summary.total_invoiced'));
        // La cartera incluye la mora de mayo: es lo que hay por cobrar en total.
        $this->assertEquals(200000, $response->json('summary.total_pending'));
    }

    #[Test]
    public function the_collection_rate_measures_the_months_own_invoices(): void
    {
        $old     = $this->invoice('2026-05-05', 100000, 0);
        $current = $this->invoice('2026-08-05', 200000, 50000);

        // Este mes entran 250k, pero 100k son de una factura de mayo.
        $oldPayment = $this->payment('2026-08-09', 100000);
        $this->allocate($oldPayment, $old, 100000);

        $newPayment = $this->payment('2026-08-10', 150000);
        $this->allocate($newPayment, $current, 150000);

        $response = $this->getJson('/api/billing/stats?month=2026-08')->assertOk();

        $this->assertEquals(250000, $response->json('summary.total_paid'));
        // 150.000 de los 200.000 facturados en agosto = 75%, no 125%.
        $this->assertEquals(75, $response->json('summary.collection_rate'));
    }

    #[Test]
    public function it_defaults_to_the_current_month(): void
    {
        $this->invoice(now()->startOfMonth()->addDay()->toDateString(), 90000, 90000);
        $this->invoice(now()->subMonth()->startOfMonth()->toDateString(), 400000, 400000);

        $response = $this->getJson('/api/billing/stats')->assertOk();

        $this->assertEquals(90000, $response->json('summary.total_invoiced'));
        $this->assertTrue($response->json('period.is_current_month'));
    }

    #[Test]
    public function another_tenants_figures_are_never_returned(): void
    {
        $this->invoice('2026-08-05', 100000, 100000);

        $otherTenant = Tenant::factory()->create();
        $otherCustomer = User::factory()->create(['tenant_id' => $otherTenant->id]);
        Invoice::withoutTenantScope()->create([
            'tenant_id'   => $otherTenant->id,
            'customer_id' => $otherCustomer->id,
            'number'       => 'OTHER-1',
            'issue_date'   => '2026-08-05',
            'due_date'     => '2026-08-05',
            'period_start' => '2026-08-01',
            'period_end'   => '2026-08-31',
            'subtotal'    => 5000000,
            'total'       => 5000000,
            'balance_due' => 5000000,
            'status'      => 'issued',
        ]);

        // Pedir explícitamente el otro tenant no debe cambiar nada: el tenant
        // sale del usuario autenticado, no de la URL.
        $response = $this->getJson('/api/billing/stats?month=2026-08&tenant=' . $otherTenant->id)
            ->assertOk();

        $this->assertEquals(100000, $response->json('summary.total_invoiced'));
    }

    #[Test]
    public function a_role_without_expense_permission_gets_no_expense_figures(): void
    {
        $limitedRole = Role::create([
            'name'        => 'Solo facturación',
            'permissions' => ['view_billing'],
            'tenant_id'   => $this->tenant->id,
        ]);
        $limited = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $limitedRole->id,
        ]);

        $this->expense('2026-08-12', 120000);
        $this->payment('2026-08-10', 300000);

        Sanctum::actingAs($limited);

        $response = $this->getJson('/api/billing/stats?month=2026-08')->assertOk();

        $this->assertNull($response->json('summary.total_expenses'));
        $this->assertNull($response->json('summary.balance'));
        $this->assertFalse($response->json('summary.can_view_expenses'));
        // Lo suyo sí lo ve.
        $this->assertEquals(300000, $response->json('summary.total_paid'));
    }

    #[Test]
    public function a_malformed_month_is_rejected(): void
    {
        $this->getJson('/api/billing/stats?month=agosto')->assertStatus(422);
    }
}
