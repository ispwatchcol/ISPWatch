<?php

namespace Tests\Feature\Billing;

use App\Models\AdditionalService;
use App\Models\Billing;
use App\Models\CustomerAdditionalService;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Factura de excepción: sólo servicios adicionales, sin plan.
 *
 * La corrida mensual salta a un cliente por siete motivos distintos. Sólo DOS
 * de ellos significan "no hay plan que cobrarle" — sin servicio activo y plan de
 * cortesía permanente — y son los únicos en que, si tiene adicionales, se le
 * emite una factura sólo con ellos.
 *
 * Los otros cinco significan "no hay que cobrarle": alguien (el operador o el
 * propio sistema) ya lo decidió, y facturarle sería desobedecerlo. El más
 * delicado es el tope de mora, que existe justo para dejar de inflar deuda
 * incobrable — la mitad de estos tests están para que nadie lo rompa después.
 */
class AdditionalOnlyInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected BillingService $billing;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billing = app(BillingService::class);
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function makeRouter(Tenant $tenant, ?int $stopExtra = null): Router
    {
        $this->seq++;

        $config = Billing::create([
            'create_invoice'       => Carbon::create(2026, 1, 15)->toDateString(),
            'payment_day'          => Carbon::create(2026, 1, 20)->toDateString(),
            'billing_mode'         => Billing::MODE_ANTICIPADO,
            'notification_type'    => 'none',
            'overdue_invoices'     => 2,
            'stop_invoicing_extra' => $stopExtra,
            'status'               => 'pending',
        ]);

        return Router::create([
            'name'              => "Router {$this->seq}",
            'tenant_id'         => $tenant->id,
            'billing_router_id' => $config->id,
            'status'            => 'active',
        ]);
    }

    /**
     * Cliente vigente. $plan = null → sin user_services activo.
     * Con $plan de cortesía → el otro caso de excepción.
     */
    private function makeCustomer(
        Tenant $tenant,
        Router $router,
        ?Plan $plan = null,
        array $profileOverrides = [],
    ): User {
        $this->seq++;
        $start = Carbon::now()->subMonths(6)->startOfDay();

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'created_at' => $start]);

        CustomerProfile::create(array_merge([
            'user_id'           => $user->id,
            'name'              => "Cliente{$this->seq}",
            'last_name'         => "Apellido{$this->seq}",
            'router_id'         => $router->id,
            'status'            => true,
            'service_status'    => 'activo',
            'installation_date' => $start->toDateString(),
        ], $profileOverrides));

        if ($plan) {
            UserService::create([
                'user_id'         => $user->id,
                'service_plan_id' => $plan->id,
                'status'          => UserService::STATUS_ACTIVE,
                'start_date'      => $start,
            ]);
        }

        return $user;
    }

    private function makePlan(Tenant $tenant, bool $courtesy = false, float $cost = 50000): Plan
    {
        return Plan::factory()->create([
            'tenant_id'    => $tenant->id,
            'cost_product' => $cost,
            'is_courtesy'  => $courtesy,
        ]);
    }

    private function giveExtra(Tenant $tenant, User $customer, float $price = 20000): CustomerAdditionalService
    {
        $this->seq++;

        $service = AdditionalService::create([
            'tenant_id' => $tenant->id,
            'name'      => "Alquiler de router {$this->seq}",
            'price'     => $price,
        ]);

        return CustomerAdditionalService::create([
            'tenant_id'             => $tenant->id,
            'customer_id'           => $customer->id,
            'additional_service_id' => $service->id,
            'starts_at'             => '2026-01-01',
            'assigned_at'           => Carbon::now()->subMonths(3),
        ]);
    }

    private function invoicesOf(User $customer)
    {
        return Invoice::where('customer_id', $customer->id)->get();
    }

    // ── Los dos casos que SÍ generan factura ───────────────────────────────

    #[Test]
    public function un_cliente_sin_plan_activo_pero_con_adicionales_recibe_factura_solo_de_ellos(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeCustomer($tenant, $router, plan: null);
        $this->giveExtra($tenant, $customer, 20000);

        $this->billing->generateMonthlyInvoices();

        $invoices = $this->invoicesOf($customer);
        $this->assertCount(1, $invoices);

        $invoice = $invoices->first();
        $this->assertSame(Invoice::TYPE_ADDITIONAL, $invoice->invoice_type);
        $this->assertEquals(20000, $invoice->total);
        $this->assertEquals(20000, $invoice->balance_due);
        $this->assertSame('issued', $invoice->status);

        // Sin línea de plan: no hay plan que cobrar.
        $this->assertSame(0, InvoiceItem::where('invoice_id', $invoice->id)->where('type', 'plan')->count());
        $this->assertSame(1, InvoiceItem::where('invoice_id', $invoice->id)->where('type', 'additional_service')->count());
    }

    #[Test]
    public function un_cliente_con_plan_de_cortesia_paga_igual_su_alquiler_de_equipo(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeCustomer($tenant, $router, $this->makePlan($tenant, courtesy: true));
        $this->giveExtra($tenant, $customer, 20000);

        $this->billing->generateMonthlyInvoices();

        $invoice = $this->invoicesOf($customer)->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(20000, $invoice->total);
    }

    #[Test]
    public function la_factura_de_excepcion_usa_el_vencimiento_del_ciclo_del_router(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);   // payment_day = 20
        $customer = $this->makeCustomer($tenant, $router, plan: null);
        $this->giveExtra($tenant, $customer);

        $this->billing->generateMonthlyInvoices();

        $invoice = $this->invoicesOf($customer)->first();

        // El día 20 del ciclo, no "hoy + 5 días" como el cargo puntual: así
        // entra a mora y corte igual que cualquier otra factura.
        $this->assertSame('2026-07-20', Carbon::parse($invoice->due_date)->toDateString());
        $this->assertSame('2026-07-01', Carbon::parse($invoice->period_start)->toDateString());
        $this->assertSame('2026-07-31', Carbon::parse($invoice->period_end)->toDateString());
    }

    #[Test]
    public function no_se_emite_dos_veces_si_la_generacion_corre_de_nuevo(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeCustomer($tenant, $router, plan: null);
        $this->giveExtra($tenant, $customer);

        $this->billing->generateMonthlyInvoices();
        $this->billing->generateMonthlyInvoices();

        $this->assertCount(1, $this->invoicesOf($customer));
    }

    #[Test]
    public function sin_adicionales_no_se_emite_ninguna_factura(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeCustomer($tenant, $router, plan: null);

        $this->billing->generateMonthlyInvoices();

        // Ni siquiera una factura vacía: gastaría un consecutivo y confundiría.
        $this->assertCount(0, $this->invoicesOf($customer));
    }

    #[Test]
    public function la_excepcion_no_cuenta_como_mensualidad_para_la_auditoria(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeCustomer($tenant, $router, plan: null);
        $this->giveExtra($tenant, $customer);

        $this->billing->generateMonthlyInvoices();

        // invoice_type = 'additional', así que monthlyInvoiceExists() y
        // auditMonthlyBilling() no la leen como la mensualidad de ese cliente.
        $this->assertSame(0, Invoice::where('customer_id', $customer->id)
            ->where('invoice_type', Invoice::TYPE_MONTHLY)->count());
    }

    // ── Los casos que NO deben generar factura ─────────────────────────────

    #[Test]
    public function un_cliente_marcado_como_no_facturar_no_recibe_nada(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeCustomer($tenant, $router, plan: null, profileOverrides: [
            'exclude_from_billing' => true,
        ]);
        $this->giveExtra($tenant, $customer);

        $this->billing->generateMonthlyInvoices();

        // Un operador marcó "no facturar": emitirle una factura, aunque sea de
        // adicionales, sería desobedecerlo.
        $this->assertCount(0, $this->invoicesOf($customer));
    }

    #[Test]
    public function un_cliente_retirado_no_recibe_nada(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeCustomer($tenant, $router, plan: null, profileOverrides: [
            'status'         => false,
            'service_status' => 'retirado',
        ]);
        $this->giveExtra($tenant, $customer);

        $this->billing->generateMonthlyInvoices();

        $this->assertCount(0, $this->invoicesOf($customer));
    }

    #[Test]
    public function el_tope_de_mora_frena_tambien_la_factura_de_adicionales(): void
    {
        $tenant   = Tenant::factory()->create();
        // overdue_invoices = 2, stop_invoicing_extra = 0 ⇒ tope en 2 pendientes.
        $router   = $this->makeRouter($tenant, stopExtra: 0);
        $customer = $this->makeCustomer($tenant, $router, plan: null);
        $this->giveExtra($tenant, $customer);

        // Dos facturas viejas sin pagar: el cliente ya llegó al tope.
        foreach ([1, 2] as $n) {
            Invoice::create([
                'tenant_id'    => $tenant->id,
                'customer_id'  => $customer->id,
                'invoice_type' => Invoice::TYPE_ADDITIONAL,
                'number'       => "OLD-{$n}",
                'issue_date'   => '2026-05-01',
                'due_date'     => '2026-05-10',
                'period_start' => "2026-0{$n}-01",
                'period_end'   => "2026-0{$n}-28",
                'total'        => 20000,
                'balance_due'  => 20000,
                'status'       => 'issued',
            ]);
        }

        $this->billing->generateMonthlyInvoices();

        // El tope existe para dejar de inflar deuda incobrable. Si la factura de
        // adicionales lo ignorara, sabotearía el único freno que hay.
        $this->assertCount(2, $this->invoicesOf($customer));
    }

    // ── Interacción con el resto ───────────────────────────────────────────

    #[Test]
    public function el_saldo_a_favor_se_aplica_a_la_factura_de_excepcion(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeCustomer($tenant, $router, plan: null, profileOverrides: [
            'credit_balance' => 5000,
        ]);
        $this->giveExtra($tenant, $customer, 20000);

        $this->billing->generateMonthlyInvoices();

        $invoice = $this->invoicesOf($customer)->first();
        $this->assertEquals(20000, $invoice->total);
        $this->assertEquals(15000, $invoice->balance_due);
        $this->assertEquals(0, CustomerProfile::where('user_id', $customer->id)->value('credit_balance'));
    }

    #[Test]
    public function un_cliente_normal_sigue_recibiendo_una_sola_factura_con_todo(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeCustomer($tenant, $router, $this->makePlan($tenant, cost: 50000));
        $this->giveExtra($tenant, $customer, 20000);

        $this->billing->generateMonthlyInvoices();

        // Guarda de regresión: la excepción no debe activarse nunca para quien
        // sí tiene plan. Una sola factura, mensual, con las dos líneas.
        $invoices = $this->invoicesOf($customer);
        $this->assertCount(1, $invoices);
        $this->assertSame(Invoice::TYPE_MONTHLY, $invoices->first()->invoice_type);
        $this->assertEquals(70000, $invoices->first()->total);
    }

    #[Test]
    public function la_factura_de_excepcion_convive_con_la_mensualidad_de_los_vecinos(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->makeRouter($tenant);

        $sinPlan = $this->makeCustomer($tenant, $router, plan: null);
        $normal  = $this->makeCustomer($tenant, $router, $this->makePlan($tenant, cost: 50000));

        $this->giveExtra($tenant, $sinPlan, 20000);

        $creadas = $this->billing->generateMonthlyInvoices();

        $this->assertSame(2, $creadas);
        $this->assertEquals(20000, $this->invoicesOf($sinPlan)->first()->total);
        $this->assertEquals(50000, $this->invoicesOf($normal)->first()->total);
    }
}
