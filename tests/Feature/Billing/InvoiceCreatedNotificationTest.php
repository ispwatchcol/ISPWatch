<?php

namespace Tests\Feature\Billing;

use App\Mail\InvoiceCreatedMail;
use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aviso "nueva factura" al crear la mensualidad. Dos reglas que salieron de
 * casos reales:
 *
 *  - Una factura que nace SALDADA con el saldo a favor del cliente no se
 *    notifica: al cliente le llegaba "tienes una nueva factura" por algo que ya
 *    estaba cubierto, como si no la hubiera pagado.
 *  - Si el cliente ya debía facturas anteriores, el correo muestra la deuda
 *    TOTAL (debía $50.000 + la nueva $50.000 = $100.000), no sólo la del mes.
 */
class InvoiceCreatedNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected BillingService $billing;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->billing = app(BillingService::class);
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{tenant: Tenant, router: Router, customer: User} */
    private function scenario(float $creditBalance = 0, float $planCost = 50000): array
    {
        $this->seq++;
        $tenant = Tenant::factory()->create();

        $config = Billing::create([
            'create_invoice'    => Carbon::create(2026, 1, 15)->toDateString(),
            'payment_day'       => Carbon::create(2026, 1, 20)->toDateString(),
            'notification_type' => 'email',
            'status'            => 'pending',
        ]);

        $router = Router::create([
            'name'              => "Router {$this->seq}",
            'tenant_id'         => $tenant->id,
            'billing_router_id' => $config->id,
            'status'            => 'active',
        ]);

        $plan = Plan::factory()->create([
            'tenant_id'    => $tenant->id,
            'cost_product' => $planCost,
            'is_courtesy'  => false,
        ]);

        $start    = Carbon::now()->subMonths(6)->startOfDay();
        $customer = User::factory()->create([
            'tenant_id'  => $tenant->id,
            'created_at' => $start,
        ]);

        CustomerProfile::create([
            'user_id'        => $customer->id,
            'name'           => "Cliente{$this->seq}",
            'last_name'      => "Apellido{$this->seq}",
            'router_id'      => $router->id,
            'status'         => true,
            'service_status' => 'activo',
            'credit_balance' => $creditBalance,
            'installation_date' => $start->toDateString(),
        ]);

        UserService::create([
            'user_id'         => $customer->id,
            'service_plan_id' => $plan->id,
            'status'          => UserService::STATUS_ACTIVE,
            'start_date'      => $start,
        ]);

        return compact('tenant', 'router', 'customer');
    }

    #[Test]
    public function an_invoice_fully_covered_by_credit_is_not_notified(): void
    {
        // Saldo a favor de 50.000 y factura de 50.000 → nace pagada.
        ['customer' => $customer] = $this->scenario(creditBalance: 50000);

        $this->billing->generateMonthlyInvoices();

        $invoice = Invoice::where('customer_id', $customer->id)->firstOrFail();
        $this->assertEquals(0, (float) $invoice->balance_due);
        $this->assertSame('paid', $invoice->status);

        Mail::assertNothingSent();
    }

    #[Test]
    public function an_invoice_partially_covered_by_credit_is_notified_with_the_remaining_balance(): void
    {
        ['customer' => $customer] = $this->scenario(creditBalance: 20000);

        $this->billing->generateMonthlyInvoices();

        $invoice = Invoice::where('customer_id', $customer->id)->firstOrFail();
        $this->assertEquals(30000, (float) $invoice->balance_due);

        Mail::assertSent(
            InvoiceCreatedMail::class,
            fn (InvoiceCreatedMail $m) => $m->hasTo($customer->email) && (float) $m->amount === 30000.0
        );
    }

    #[Test]
    public function the_notification_reports_the_total_debt_when_older_invoices_are_unpaid(): void
    {
        ['tenant' => $tenant, 'customer' => $customer] = $this->scenario();

        // Debía 50.000 del mes pasado.
        Invoice::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'invoice_type' => Invoice::TYPE_MONTHLY,
            'number'       => 'INV-ANTERIOR',
            'issue_date'   => Carbon::create(2026, 5, 1),
            'due_date'     => Carbon::create(2026, 5, 20),
            'period_start' => Carbon::create(2026, 5, 1),
            'period_end'   => Carbon::create(2026, 5, 31),
            'subtotal'     => 50000,
            'total'        => 50000,
            'balance_due'  => 50000,
            'status'       => 'overdue',
        ]);

        $this->billing->generateMonthlyInvoices();

        Mail::assertSent(InvoiceCreatedMail::class, function (InvoiceCreatedMail $m) {
            return $m->pendingCount === 2
                && (float) $m->amount === 50000.0        // la nueva
                && (float) $m->previousDue === 50000.0   // lo que ya debía
                && (float) $m->pendingTotal === 100000.0; // total con nosotros
        });
    }

    #[Test]
    public function a_customer_with_no_previous_debt_gets_the_plain_notification(): void
    {
        ['customer' => $customer] = $this->scenario();

        $this->billing->generateMonthlyInvoices();

        Mail::assertSent(InvoiceCreatedMail::class, function (InvoiceCreatedMail $m) use ($customer) {
            return $m->hasTo($customer->email)
                && $m->pendingCount === 1
                && (float) $m->previousDue === 0.0;
        });
    }
}
