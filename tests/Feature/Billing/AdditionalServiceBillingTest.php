<?php

namespace Tests\Feature\Billing;

use App\Mail\InvoiceCreatedMail;
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
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Servicios adicionales recurrentes dentro del ciclo mensual.
 *
 * La regla que se está probando: un servicio adicional **no emite factura
 * propia**, se suma como un ítem más a la mensualidad que el cliente ya recibe
 * según el ciclo de su router.
 *
 * Lo delicado no es la suma, es cuándo NO hay que cobrar: asignaciones fuera de
 * ventana, meses de cortesía, y sobre todo no cobrar dos veces el mismo mes.
 */
class AdditionalServiceBillingTest extends TestCase
{
    use RefreshDatabase;

    protected BillingService $billing;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billing = app(BillingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Andamiaje (mismo patrón que RouterMonthlyBillingTest) ───────────────

    /** `none` por defecto: los tests de cobro no deben depender del correo. */
    private function makeBilling(int $createDay, string $notificationType = 'none'): Billing
    {
        return Billing::create([
            'create_invoice'    => Carbon::create(2026, 1, $createDay)->toDateString(),
            'billing_mode'      => Billing::MODE_ANTICIPADO,
            'notification_type' => $notificationType,
            'status'            => 'pending',
        ]);
    }

    private function makeRouter(Tenant $tenant, Billing $billing): Router
    {
        $this->seq++;

        return Router::create([
            'name'              => "Router {$this->seq}",
            'tenant_id'         => $tenant->id,
            'billing_router_id' => $billing->id,
            'status'            => 'active',
        ]);
    }

    private function makePlan(Tenant $tenant, float $cost = 50000, int $freeMonths = 0): Plan
    {
        return Plan::factory()->create([
            'tenant_id'                 => $tenant->id,
            'cost_product'              => $cost,
            'is_courtesy'               => false,
            'first_invoice_free_months' => $freeMonths,
        ]);
    }

    private function makeCustomer(
        Tenant $tenant,
        Router $router,
        Plan $plan,
        ?Carbon $serviceStart = null,
        ?string $firstInvoiceMode = null,
    ): User {
        $this->seq++;
        $serviceStart ??= Carbon::now()->subMonths(6)->startOfDay();

        $user = User::factory()->create([
            'tenant_id'  => $tenant->id,
            'created_at' => $serviceStart,
        ]);

        CustomerProfile::create([
            'user_id'            => $user->id,
            'name'               => "Cliente{$this->seq}",
            'last_name'          => "Apellido{$this->seq}",
            'router_id'          => $router->id,
            'status'             => true,
            'service_status'     => 'activo',
            'installation_date'  => $serviceStart->toDateString(),
            'first_invoice_mode' => $firstInvoiceMode,
        ]);

        UserService::create([
            'user_id'         => $user->id,
            'service_plan_id' => $plan->id,
            'status'          => UserService::STATUS_ACTIVE,
            'start_date'      => $serviceStart,
        ]);

        return $user;
    }

    /** Sin sesión autenticada, tenant_id se pasa a mano (lo pondría el trait). */
    private function makeService(Tenant $tenant, array $attributes = []): AdditionalService
    {
        $this->seq++;

        return AdditionalService::create(array_merge([
            'tenant_id' => $tenant->id,
            'name'      => "Servicio {$this->seq}",
            'price'     => 20000,
        ], $attributes));
    }

    private function assign(
        Tenant $tenant,
        User $customer,
        AdditionalService $service,
        array $attributes = [],
    ): CustomerAdditionalService {
        return CustomerAdditionalService::create(array_merge([
            'tenant_id'             => $tenant->id,
            'customer_id'           => $customer->id,
            'additional_service_id' => $service->id,
            'starts_at'             => Carbon::now()->subMonths(3)->toDateString(),
            'assigned_at'           => Carbon::now()->subMonths(3),
        ], $attributes));
    }

    /**
     * Escenario base: hoy 15/07/2026, router que factura el 15, cliente antiguo
     * con plan de $50.000. El periodo resultante es julio completo.
     */
    private function escenario(float $planCost = 50000, int $freeMonths = 0): array
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0, 0));

        $tenant   = Tenant::factory()->create();
        $plan     = $this->makePlan($tenant, $planCost, $freeMonths);
        $router   = $this->makeRouter($tenant, $this->makeBilling(15));
        $customer = $this->makeCustomer($tenant, $router, $plan);

        return [$tenant, $customer, $router, $plan];
    }

    private function invoiceOf(User $customer): ?Invoice
    {
        return Invoice::where('customer_id', $customer->id)->first();
    }

    // ── Lo esencial ────────────────────────────────────────────────────────

    #[Test]
    public function el_adicional_se_suma_a_la_mensualidad_y_no_genera_factura_aparte(): void
    {
        [$tenant, $customer] = $this->escenario();
        $this->assign($tenant, $customer, $this->makeService($tenant, ['price' => 20000]));

        $this->billing->generateMonthlyInvoices();

        // UNA factura, no dos.
        $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());

        $invoice = $this->invoiceOf($customer);
        $this->assertEquals(70000, $invoice->total);
        $this->assertEquals(70000, $invoice->subtotal);
        $this->assertEquals(70000, $invoice->balance_due);
        $this->assertSame('issued', $invoice->status);

        $item = InvoiceItem::where('invoice_id', $invoice->id)
            ->where('type', 'additional_service')->first();

        $this->assertNotNull($item);
        $this->assertEquals(20000, $item->amount);
        $this->assertEquals(1, $item->quantity);
    }

    #[Test]
    public function el_precio_propio_del_cliente_manda_sobre_el_del_catalogo(): void
    {
        [$tenant, $customer] = $this->escenario();
        $this->assign($tenant, $customer, $this->makeService($tenant, ['price' => 20000]), ['price' => 12000]);

        $this->billing->generateMonthlyInvoices();

        $this->assertEquals(62000, $this->invoiceOf($customer)->total);
    }

    #[Test]
    public function la_cantidad_multiplica_el_cargo(): void
    {
        [$tenant, $customer] = $this->escenario();
        $this->assign($tenant, $customer, $this->makeService($tenant, ['price' => 20000]), ['quantity' => 3]);

        $this->billing->generateMonthlyInvoices();

        $item = InvoiceItem::where('type', 'additional_service')->first();
        $this->assertEquals(20000, $item->unit_price);
        $this->assertEquals(3, $item->quantity);
        // unit_price × quantity = amount, exacto: es lo que el cliente ve.
        $this->assertEquals(60000, $item->amount);
        $this->assertEquals(110000, $this->invoiceOf($customer)->total);
    }

    #[Test]
    public function varios_adicionales_se_suman_todos(): void
    {
        [$tenant, $customer] = $this->escenario();
        $this->assign($tenant, $customer, $this->makeService($tenant, ['price' => 20000]));
        $this->assign($tenant, $customer, $this->makeService($tenant, ['price' => 5000]));

        $this->billing->generateMonthlyInvoices();

        $this->assertEquals(75000, $this->invoiceOf($customer)->total);
        $this->assertSame(2, InvoiceItem::where('type', 'additional_service')->count());
    }

    // ── Idempotencia ───────────────────────────────────────────────────────

    #[Test]
    public function correr_la_generacion_dos_veces_no_duplica_el_adicional(): void
    {
        [$tenant, $customer] = $this->escenario();
        $this->assign($tenant, $customer, $this->makeService($tenant, ['price' => 20000]));

        $this->billing->generateMonthlyInvoices();
        $this->billing->generateMonthlyInvoices();

        $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());
        $this->assertSame(1, InvoiceItem::where('type', 'additional_service')->count());
        $this->assertEquals(70000, $this->invoiceOf($customer)->total);
    }

    #[Test]
    public function borrar_la_factura_libera_el_mes_y_el_adicional_vuelve_a_cobrarse(): void
    {
        [$tenant, $customer] = $this->escenario();
        $this->assign($tenant, $customer, $this->makeService($tenant, ['price' => 20000]));

        $this->billing->generateMonthlyInvoices();
        $this->invoiceOf($customer)->delete();

        // La idempotencia se deriva de los ítems, que se fueron con la factura.
        // Con un contador de "último periodo cobrado" en la asignación, este mes
        // no se volvería a cobrar nunca.
        $this->billing->generateMonthlyInvoices();

        $this->assertEquals(70000, $this->invoiceOf($customer)->total);
    }

    // ── Ventana de vigencia ────────────────────────────────────────────────

    #[Test]
    public function no_cobra_una_asignacion_que_arranca_el_mes_siguiente(): void
    {
        [$tenant, $customer] = $this->escenario();
        $this->assign($tenant, $customer, $this->makeService($tenant), ['starts_at' => '2026-08-01']);

        $this->billing->generateMonthlyInvoices();

        $this->assertEquals(50000, $this->invoiceOf($customer)->total);
        $this->assertSame(0, InvoiceItem::where('type', 'additional_service')->count());
    }

    #[Test]
    public function no_cobra_una_asignacion_dada_de_baja(): void
    {
        [$tenant, $customer] = $this->escenario();
        $this->assign($tenant, $customer, $this->makeService($tenant), ['is_active' => false]);

        $this->billing->generateMonthlyInvoices();

        $this->assertEquals(50000, $this->invoiceOf($customer)->total);
    }

    #[Test]
    public function deja_de_cobrar_despues_de_la_fecha_de_fin(): void
    {
        [$tenant, $customer] = $this->escenario();
        $this->assign($tenant, $customer, $this->makeService($tenant), ['ends_at' => '2026-06-30']);

        $this->billing->generateMonthlyInvoices();

        $this->assertEquals(50000, $this->invoiceOf($customer)->total);
    }

    #[Test]
    public function un_servicio_desactivado_en_el_catalogo_se_sigue_cobrando_a_quien_ya_lo_tiene(): void
    {
        [$tenant, $customer] = $this->escenario();
        $service = $this->makeService($tenant, ['price' => 20000, 'is_active' => false]);
        $this->assign($tenant, $customer, $service);

        $this->billing->generateMonthlyInvoices();

        // Desactivar en el catálogo significa "no ofrecerlo más al asignar", no
        // "dejar de cobrárselo en silencio a quien ya lo tiene". Para eso está
        // dar de baja la asignación, que es explícito y por cliente.
        $this->assertEquals(70000, $this->invoiceOf($customer)->total);
    }

    // ── Prorrateo del primer mes ───────────────────────────────────────────

    #[Test]
    public function prorratea_cuando_la_asignacion_arranca_a_mitad_de_mes(): void
    {
        [$tenant, $customer] = $this->escenario();
        $service = $this->makeService($tenant, [
            'price'          => 31000,
            'proration_mode' => Billing::FIRST_INVOICE_PRORATED,
        ]);
        // Julio tiene 31 días; alta el 21 ⇒ 10 días (31 − 21) ⇒ 31000 × 10/31.
        $this->assign($tenant, $customer, $service, ['starts_at' => '2026-07-21']);

        $this->billing->generateMonthlyInvoices();

        $item = InvoiceItem::where('type', 'additional_service')->first();
        $this->assertEquals(10000, $item->amount);
        $this->assertStringContainsString('proporcional', $item->description);
        $this->assertEquals(60000, $this->invoiceOf($customer)->total);
    }

    #[Test]
    public function con_modo_none_el_primer_cobro_sale_el_ciclo_siguiente(): void
    {
        [$tenant, $customer] = $this->escenario();
        $service = $this->makeService($tenant, ['proration_mode' => Billing::FIRST_INVOICE_NONE]);
        $this->assign($tenant, $customer, $service, ['starts_at' => '2026-07-21']);

        $this->billing->generateMonthlyInvoices();

        $this->assertEquals(50000, $this->invoiceOf($customer)->total);
    }

    #[Test]
    public function con_modo_full_cobra_el_mes_entero_aunque_arranque_a_mitad(): void
    {
        [$tenant, $customer] = $this->escenario();
        // 'full' es el valor por defecto del catálogo.
        $service = $this->makeService($tenant, ['price' => 20000]);
        $this->assign($tenant, $customer, $service, ['starts_at' => '2026-07-21']);

        $this->billing->generateMonthlyInvoices();

        $this->assertEquals(70000, $this->invoiceOf($customer)->total);
    }

    #[Test]
    public function una_asignacion_antigua_no_se_prorratea_nunca(): void
    {
        [$tenant, $customer] = $this->escenario();
        $service = $this->makeService($tenant, [
            'price'          => 20000,
            'proration_mode' => Billing::FIRST_INVOICE_PRORATED,
        ]);
        // Arrancó hace meses: el prorrateo sólo aplica al mes en que se dio de alta.
        $this->assign($tenant, $customer, $service, ['starts_at' => '2026-03-10']);

        $this->billing->generateMonthlyInvoices();

        $this->assertEquals(70000, $this->invoiceOf($customer)->total);
    }

    #[Test]
    public function arrancar_el_primero_del_mes_cobra_completo_aun_en_modo_proporcional(): void
    {
        [$tenant, $customer] = $this->escenario();
        $service = $this->makeService($tenant, [
            'price'          => 20000,
            'proration_mode' => Billing::FIRST_INVOICE_PRORATED,
        ]);
        $this->assign($tenant, $customer, $service, ['starts_at' => '2026-07-01']);

        $this->billing->generateMonthlyInvoices();

        // El 1º es un mes completo: no hay días que descontar.
        $this->assertEquals(70000, $this->invoiceOf($customer)->total);
    }

    // ── Mes de cortesía ────────────────────────────────────────────────────

    /**
     * Cliente instalado el mes pasado con un mes de cortesía: el plan sale en
     * cero este mes.
     */
    private function escenarioCortesia(string $notificationType = 'none'): array
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0, 0));

        $tenant   = Tenant::factory()->create();
        $plan     = $this->makePlan($tenant, 50000, freeMonths: 1);
        $router   = $this->makeRouter($tenant, $this->makeBilling(15, $notificationType));
        $customer = $this->makeCustomer(
            $tenant, $router, $plan,
            serviceStart: Carbon::create(2026, 6, 10),
            firstInvoiceMode: Billing::FIRST_INVOICE_FULL,
        );

        return [$tenant, $customer];
    }

    #[Test]
    public function un_mes_de_cortesia_sin_adicionales_sigue_naciendo_en_cero_y_saldado(): void
    {
        [, $customer] = $this->escenarioCortesia();

        $this->billing->generateMonthlyInvoices();

        // Guarda de regresión: al quitar los atajos `$free ? 0 : ...` esto tenía
        // que seguir comportándose exactamente igual que antes.
        $invoice = $this->invoiceOf($customer);
        $this->assertEquals(0, $invoice->total);
        $this->assertEquals(0, $invoice->balance_due);
        $this->assertSame('paid', $invoice->status);
    }

    #[Test]
    public function en_mes_de_cortesia_cobra_solo_los_adicionales_marcados(): void
    {
        [$tenant, $customer] = $this->escenarioCortesia();

        $equipo = $this->makeService($tenant, [
            'price'                    => 20000,
            'charge_on_courtesy_month' => true,
        ]);
        $regalo = $this->makeService($tenant, [
            'price'                    => 9000,
            'charge_on_courtesy_month' => false,
        ]);

        $this->assign($tenant, $customer, $equipo, ['starts_at' => '2026-06-10']);
        $this->assign($tenant, $customer, $regalo, ['starts_at' => '2026-06-10']);

        $this->billing->generateMonthlyInvoices();

        $invoice = $this->invoiceOf($customer);

        // El plan va en cero (cortesía) pero el alquiler del equipo se cobra:
        // la promoción vendida fue "internet gratis", no "equipos gratis".
        $this->assertEquals(20000, $invoice->total);
        $this->assertEquals(20000, $invoice->balance_due);
        // Y por tanto la factura vuelve al circuito normal de cobro.
        $this->assertSame('issued', $invoice->status);

        $this->assertSame(1, InvoiceItem::where('type', 'additional_service')->count());
    }

    #[Test]
    public function un_mes_de_cortesia_con_adicionales_si_se_notifica(): void
    {
        Mail::fake();

        [$tenant, $customer] = $this->escenarioCortesia(notificationType: 'email');
        $this->assign(
            $tenant, $customer,
            $this->makeService($tenant, ['price' => 20000, 'charge_on_courtesy_month' => true]),
            ['starts_at' => '2026-06-10'],
        );

        $this->billing->generateMonthlyInvoices();

        // El plan va gratis pero el equipo se cobra: el cliente tiene que
        // enterarse de que debe $20.000. El aviso estaba dentro de un
        // `if (!$free)` que lo habría dejado mudo.
        Mail::assertSent(
            InvoiceCreatedMail::class,
            fn (InvoiceCreatedMail $m) => (float) $m->amount === 20000.0
        );
    }

    #[Test]
    public function un_mes_de_cortesia_sin_adicionales_sigue_sin_notificarse(): void
    {
        Mail::fake();

        $this->escenarioCortesia(notificationType: 'email');

        $this->billing->generateMonthlyInvoices();

        // Avisar de una factura de $0 que no hay que pagar sólo confunde.
        Mail::assertNothingSent();
    }

    // ── Interacción con el resto del cobro ─────────────────────────────────

    #[Test]
    public function el_saldo_a_favor_se_aplica_sobre_el_total_ya_con_adicionales(): void
    {
        [$tenant, $customer] = $this->escenario();
        $this->assign($tenant, $customer, $this->makeService($tenant, ['price' => 20000]));

        CustomerProfile::where('user_id', $customer->id)->update(['credit_balance' => 30000]);

        $this->billing->generateMonthlyInvoices();

        $invoice = $this->invoiceOf($customer);

        // Si el crédito se aplicara antes de sumar el adicional, balance_due
        // quedaría en 40000 (70000 − 30000 mal calculado sobre 50000 = 20000).
        $this->assertEquals(70000, $invoice->total);
        $this->assertEquals(40000, $invoice->balance_due);
        $this->assertEquals(0, CustomerProfile::where('user_id', $customer->id)->value('credit_balance'));
    }

    #[Test]
    public function la_primera_factura_prorrateada_del_plan_no_confunde_el_mes_del_adicional(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0, 0));

        $tenant   = Tenant::factory()->create();
        $plan     = $this->makePlan($tenant, 31000);
        $router   = $this->makeRouter($tenant, $this->makeBilling(15));
        // Instalado el 11 de julio con plan prorrateado: la factura arranca el
        // 11, no el 1º. El adicional, en cambio, razona sobre el mes natural.
        $customer = $this->makeCustomer(
            $tenant, $router, $plan,
            serviceStart: Carbon::create(2026, 7, 11),
            firstInvoiceMode: Billing::FIRST_INVOICE_PRORATED,
        );

        $service = $this->makeService($tenant, [
            'price'          => 20000,
            'proration_mode' => Billing::FIRST_INVOICE_PRORATED,
        ]);
        $this->assign($tenant, $customer, $service, ['starts_at' => '2026-05-01']);

        $this->billing->generateMonthlyInvoices();

        $invoice = $this->invoiceOf($customer);

        // Plan: 31000 × 20/31 = 20000. Adicional: antiguo ⇒ mes completo, 20000.
        // Si el adicional hubiera usado el period_start de la factura (11/07) en
        // vez del mes natural, se habría prorrateado por error.
        $this->assertEquals(40000, $invoice->total);
        $this->assertEquals(20000, InvoiceItem::where('type', 'additional_service')->value('amount'));
    }

    #[Test]
    public function los_adicionales_de_un_cliente_no_se_cuelan_en_la_factura_de_otro(): void
    {
        [$tenant, $customer, $router, $plan] = $this->escenario();
        $vecino = $this->makeCustomer($tenant, $router, $plan);

        $this->assign($tenant, $customer, $this->makeService($tenant, ['price' => 20000]));

        $this->billing->generateMonthlyInvoices();

        $this->assertEquals(70000, $this->invoiceOf($customer)->total);
        $this->assertEquals(50000, $this->invoiceOf($vecino)->total);
    }
}
