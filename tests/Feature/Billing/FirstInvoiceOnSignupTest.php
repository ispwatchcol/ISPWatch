<?php

namespace Tests\Feature\Billing;

use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Role;
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
 * La primera factura sale AL DAR DE ALTA al cliente, no cuando llega el día de
 * facturación del router.
 *
 * Caso real que originó esto: un cliente cargado el 18 de agosto con prorrateo
 * recibió su factura de INSTALACIÓN (la emite el módulo de instalaciones, en el
 * acto) pero nunca la del servicio. Su router no tenía `create_invoice`
 * configurado, así que la corrida mensual lo saltaba entero — la factura
 * prorrateada que el formulario le había mostrado al operador no iba a existir
 * jamás.
 *
 * Reglas cubiertas aquí:
 *   - el alta emite la prorrateada aunque el router no tenga día de facturación
 *   - la corrida mensual posterior NO la duplica
 *   - 'none' (por defecto) sigue sin cobrar nada al alta
 *   - excluido de facturación, plan de cortesía y cobro vencido no facturan
 *   - un alta retroactiva la sigue resolviendo la corrida mensual, no el alta
 *   - el endpoint de alta responde con la factura emitida
 */
class FirstInvoiceOnSignupTest extends TestCase
{
    use RefreshDatabase;

    protected BillingService $billing;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billing = app(BillingService::class);
        // Día 18: alta a mitad de mes, como el caso real.
        Carbon::setTestNow(Carbon::create(2026, 8, 18, 17, 25));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** @param int|null $createDay null = router SIN día de facturación (el caso real). */
    private function makeRouter(
        Tenant $tenant,
        ?int $createDay = null,
        string $policy = Billing::FIRST_INVOICE_PRORATED,
        string $mode = Billing::MODE_ANTICIPADO,
    ): Router {
        $this->seq++;

        $config = Billing::create([
            'create_invoice'       => $createDay ? Carbon::create(2026, 1, $createDay)->toDateString() : null,
            'billing_mode'         => $mode,
            'first_invoice_policy' => $policy,
            'status'               => 'pending',
        ]);

        return Router::create([
            'name'              => "Router {$this->seq}",
            'tenant_id'         => $tenant->id,
            'billing_router_id' => $config->id,
            'status'            => 'active',
        ]);
    }

    private function makePlan(Tenant $tenant, float $cost = 60000, bool $courtesy = false): Plan
    {
        return Plan::factory()->create([
            'tenant_id'    => $tenant->id,
            'cost_product' => $cost,
            'is_courtesy'  => $courtesy,
        ]);
    }

    private function makeCustomer(
        Tenant $tenant,
        Router $router,
        Plan $plan,
        Carbon $installedOn,
        array $profileOverrides = [],
    ): CustomerProfile {
        $this->seq++;

        $user = User::factory()->create([
            'tenant_id'  => $tenant->id,
            'created_at' => $installedOn,
        ]);

        $profile = CustomerProfile::create(array_merge([
            'user_id'           => $user->id,
            'name'              => "Cliente{$this->seq}",
            'last_name'         => "Apellido{$this->seq}",
            'router_id'         => $router->id,
            'status'            => true,
            'service_status'    => 'activo',
            'installation_date' => $installedOn->toDateString(),
        ], $profileOverrides));

        UserService::create([
            'user_id'         => $user->id,
            'service_plan_id' => $plan->id,
            'status'          => $plan->is_courtesy ? UserService::STATUS_GRATIS : UserService::STATUS_ACTIVE,
            'start_date'      => $installedOn,
        ]);

        return $profile->fresh();
    }

    private function monthlyInvoicesOf(User|CustomerProfile $customer)
    {
        $id = $customer instanceof CustomerProfile ? $customer->user_id : $customer->id;

        return Invoice::where('customer_id', $id)
            ->where('invoice_type', Invoice::TYPE_MONTHLY)
            ->get();
    }

    // ── El caso real ────────────────────────────────────────────────────

    #[Test]
    public function el_alta_emite_la_prorrateada_aunque_el_router_no_tenga_dia_de_facturacion(): void
    {
        $tenant   = Tenant::factory()->create();
        $plan     = $this->makePlan($tenant, 60000);
        $router   = $this->makeRouter($tenant, createDay: null);
        $customer = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 8, 18));

        $invoice = $this->billing->issueFirstInvoiceOnSignup($customer);

        $this->assertNotNull($invoice, 'El alta debe emitir la factura prorrateada del mes en curso.');
        // 31 días de agosto, instalado el 18 ⇒ 13 días cobrables.
        $this->assertEqualsWithDelta(round(60000 * 13 / 31), (float) $invoice->total, 0.01);
        $this->assertSame(Invoice::TYPE_MONTHLY, $invoice->invoice_type);
        $this->assertSame('2026-08-18', $invoice->period_start->toDateString());
        $this->assertSame('2026-08-31', $invoice->period_end->toDateString());
        $this->assertStringContainsString('proporcional', $invoice->items->first()->description);
    }

    #[Test]
    public function la_corrida_mensual_posterior_no_duplica_la_factura_del_alta(): void
    {
        $tenant   = Tenant::factory()->create();
        $plan     = $this->makePlan($tenant, 60000);
        // Con día 25: la corrida sí dispararía este mismo mes, más tarde.
        $router   = $this->makeRouter($tenant, createDay: 25);
        $customer = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 8, 18));

        $this->billing->issueFirstInvoiceOnSignup($customer);

        Carbon::setTestNow(Carbon::create(2026, 8, 25, 3, 0));
        $this->billing->generateMonthlyInvoices();

        $this->assertCount(1, $this->monthlyInvoicesOf($customer), 'La corrida mensual no debe duplicar la factura del alta.');
    }

    #[Test]
    public function la_factura_vence_segun_el_payment_day_del_router(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000);
        $router = $this->makeRouter($tenant);
        $router->billingConfig->update(['payment_day' => '2026-01-05']);

        $customer = $this->makeCustomer($tenant, $router->fresh('billingConfig'), $plan, Carbon::create(2026, 8, 18));

        $invoice = $this->billing->issueFirstInvoiceOnSignup($customer);

        // El 5 de agosto ya pasó ⇒ vence el 5 de septiembre; nunca nace vencida.
        $this->assertSame('2026-09-05', $invoice->due_date->toDateString());
        $this->assertSame('2026-08-18', $invoice->issue_date->toDateString());
    }

    // ── Cuándo NO se factura ────────────────────────────────────────────

    #[Test]
    public function la_politica_none_sigue_sin_cobrar_el_mes_de_instalacion(): void
    {
        $tenant   = Tenant::factory()->create();
        $plan     = $this->makePlan($tenant);
        $router   = $this->makeRouter($tenant, policy: Billing::FIRST_INVOICE_NONE);
        $customer = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 8, 18));

        $this->assertNull($this->billing->issueFirstInvoiceOnSignup($customer));
        $this->assertCount(0, $this->monthlyInvoicesOf($customer));
    }

    #[Test]
    public function el_cliente_marcado_no_facturar_no_recibe_factura_al_alta(): void
    {
        $tenant   = Tenant::factory()->create();
        $plan     = $this->makePlan($tenant);
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 8, 18), [
            'exclude_from_billing' => true,
        ]);

        $this->assertNull($this->billing->issueFirstInvoiceOnSignup($customer));
    }

    #[Test]
    public function el_plan_de_cortesia_no_factura_al_alta(): void
    {
        $tenant   = Tenant::factory()->create();
        $plan     = $this->makePlan($tenant, 0, courtesy: true);
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 8, 18));

        $this->assertNull($this->billing->issueFirstInvoiceOnSignup($customer));
    }

    #[Test]
    public function en_cobro_vencido_la_primera_factura_la_sigue_emitiendo_la_corrida(): void
    {
        $tenant   = Tenant::factory()->create();
        $plan     = $this->makePlan($tenant, 60000);
        $router   = $this->makeRouter($tenant, createDay: 1, mode: Billing::MODE_VENCIDO);
        $customer = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 8, 18));

        $this->assertNull(
            $this->billing->issueFirstInvoiceOnSignup($customer),
            'Cobrar por adelantado al operador que eligió cobro vencido sería cambiarle el negocio.'
        );

        // Y en septiembre la corrida sí la emite, prorrateada.
        Carbon::setTestNow(Carbon::create(2026, 9, 1, 3, 0));
        $this->billing->generateMonthlyInvoices();

        $invoices = $this->monthlyInvoicesOf($customer);
        $this->assertCount(1, $invoices);
        $this->assertEqualsWithDelta(round(60000 * 13 / 31), (float) $invoices->first()->total, 0.01);
    }

    #[Test]
    public function un_alta_retroactiva_la_resuelve_la_corrida_mensual_no_el_alta(): void
    {
        $tenant   = Tenant::factory()->create();
        $plan     = $this->makePlan($tenant, 60000);
        $router   = $this->makeRouter($tenant, createDay: 1);
        // Cliente antiguo que recién se carga al sistema: su mensualidad de
        // agosto es una mensualidad normal, no una "primera factura".
        $customer = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 5, 10));

        $this->assertNull($this->billing->issueFirstInvoiceOnSignup($customer));

        $this->billing->generateMonthlyInvoices();

        $invoices = $this->monthlyInvoicesOf($customer);
        $this->assertCount(1, $invoices);
        $this->assertEqualsWithDelta(60000, (float) $invoices->first()->total, 0.01);
    }

    #[Test]
    public function el_cliente_retirado_no_factura_al_alta(): void
    {
        $tenant   = Tenant::factory()->create();
        $plan     = $this->makePlan($tenant);
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 8, 18), [
            'service_status' => 'retirado',
        ]);

        $this->assertNull($this->billing->issueFirstInvoiceOnSignup($customer));
    }

    // ── Endpoint de alta ────────────────────────────────────────────────

    #[Test]
    public function el_endpoint_de_alta_devuelve_la_factura_emitida(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000);
        $router = $this->makeRouter($tenant, createDay: null);

        $role = Role::create([
            'name'        => 'Operador ' . uniqid(),
            'code'        => 'staff',
            'tenant_id'   => $tenant->id,
            'permissions' => ['view_clients', 'add_clients'],
        ]);

        // El alta le asigna al cliente el rol 'Cliente' del tenant.
        Role::create([
            'name'        => 'Cliente',
            'code'        => 'client',
            'tenant_id'   => $tenant->id,
            'permissions' => [],
        ]);

        $staff = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);

        $response = $this->actingAs($staff)->postJson('/api/customers', [
            'email'             => 'daniel.montes@example.com',
            'password'          => 'secret123',
            'name'              => 'DANIEL ALEJANDRO',
            'last_name'         => 'MONTES ROA',
            'cedula'            => '1234567890',
            'installation_date' => '2026-08-18',
            'service_id'        => $plan->id,
            'router_id'         => $router->id,
            'tenant_id'         => $tenant->id,
            'push_to_router'    => false,
        ]);

        $response->assertCreated();

        $expected = round(60000 * 13 / 31);
        $response->assertJsonPath('first_invoice.period', '2026-08');
        $this->assertEqualsWithDelta($expected, $response->json('first_invoice.total'), 0.01);

        $customerId = $response->json('customer.user_id');
        $this->assertCount(1, $this->monthlyInvoicesOf(CustomerProfile::find($customerId)));
    }
}
