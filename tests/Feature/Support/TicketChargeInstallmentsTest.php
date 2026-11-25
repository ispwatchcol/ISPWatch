<?php

namespace Tests\Feature\Support;

use App\Models\Billing;
use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceCarryover;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Router;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use App\Services\BillingService;
use App\Services\BooksAuditService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cuotas en los cargos de un ticket (2026-11-25).
 *
 * Pedido: «la opción de poner a cuotas los cobros de los tickets, igual que la
 * instalación; cuota sumada a la mensualidad o factura aparte, seleccionable;
 * y que se pueda cambiar después». La mecánica es la del acuerdo de pago de la
 * instalación (mismo planificador), así que aquí se fija que funciona igual
 * desde un cargo de ticket y que los libros cierran.
 */
class TicketChargeInstallmentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $cliente;
    private User $operador;
    private SupportTicket $ticket;
    private BillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        Carbon::setTestNow(Carbon::create(2026, 6, 10, 9, 0, 0));

        $this->billing = app(BillingService::class);
        $this->tenant  = Tenant::factory()->create();

        Role::create(['name' => 'Superadmin global', 'code' => 'superadmin', 'permissions' => ['*'], 'tenant_id' => null]);
        $rol = Role::create(['name' => 'Admin', 'code' => 'admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id]);
        $this->operador = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rol->id]);

        // Cliente facturable: router con día de facturación 15, plan de $50.000.
        $config = Billing::create([
            'create_invoice' => Carbon::create(2026, 1, 15)->toDateString(),
            'billing_mode'   => Billing::MODE_ANTICIPADO,
            'status'         => 'pending',
        ]);
        $router = Router::create([
            'name' => 'Router cuotas ticket', 'tenant_id' => $this->tenant->id,
            'billing_router_id' => $config->id, 'status' => 'active',
        ]);
        $plan  = Plan::factory()->create(['tenant_id' => $this->tenant->id, 'cost_product' => 50000]);
        $desde = Carbon::now()->subMonths(6)->startOfDay();

        $this->cliente = User::factory()->create(['tenant_id' => $this->tenant->id, 'created_at' => $desde]);
        CustomerProfile::create([
            'user_id' => $this->cliente->id, 'name' => 'Ana', 'last_name' => 'Ruiz',
            'router_id' => $router->id, 'status' => true, 'installation_date' => $desde->toDateString(),
        ]);
        UserService::create([
            'user_id' => $this->cliente->id, 'service_plan_id' => $plan->id,
            'status' => UserService::STATUS_ACTIVE, 'start_date' => $desde,
        ]);

        $this->ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->cliente->id,
            'subject' => 'Cambio de router', 'status' => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Cargo de $150.000 (router nuevo + visita). */
    private function cobrar(array $cuotas = [])
    {
        return $this->actingAs($this->operador)->postJson("/api/support/{$this->ticket->id}/charge", $cuotas + [
            'items' => [
                ['description' => 'Router nuevo', 'quantity' => 1, 'unit_price' => 120000],
                ['description' => 'Visita técnica', 'quantity' => 1, 'unit_price' => 30000],
            ],
        ]);
    }

    private function cambiar(Invoice $madre, array $datos)
    {
        return $this->actingAs($this->operador)
            ->putJson("/api/support/{$this->ticket->id}/charges/{$madre->id}/installments", $datos);
    }

    private function madre(): Invoice
    {
        return Invoice::where('ticket_id', $this->ticket->id)->latest('id')->firstOrFail();
    }

    /** @return \Illuminate\Support\Collection<int, InvoiceCarryover> */
    private function cuotas(Invoice $madre)
    {
        return InvoiceCarryover::installments()->where('from_invoice_id', $madre->id)->orderBy('installment_number')->get();
    }

    private function assertLibrosCierran(): void
    {
        $hallazgos = collect(app(BooksAuditService::class)->run($this->tenant->id))
            ->whereIn('code', ['C1', 'C2', 'C3', 'C4', 'C13']);

        $this->assertSame([], $hallazgos->map(fn ($h) => $h['code'] . ': ' . $h['title'])->values()->all(),
            'Los libros tienen que cerrar con cuotas de un cargo de ticket.');
    }

    // ── Sin cuotas: nada cambia ──────────────────────────────────────────

    #[Test]
    public function sin_cuotas_el_cargo_es_una_sola_factura_como_siempre(): void
    {
        $this->cobrar()->assertCreated();
        $madre = $this->madre();

        $this->assertEquals(150000, (float) $madre->balance_due);
        $this->assertSame(Invoice::TYPE_SERVICE_CHARGE, $madre->invoice_type);
        $this->assertCount(0, $this->cuotas($madre));
    }

    // ── Dentro de la mensualidad ─────────────────────────────────────────

    #[Test]
    public function en_mensualidad_cada_factura_mensual_cobra_una_cuota_que_dice_que_es_del_ticket(): void
    {
        $this->cobrar(['installments_count' => 3, 'installments_mode' => CustomerInstallation::INSTALLMENTS_MONTHLY])->assertCreated();
        $madre = $this->madre();

        // El cargo queda saldado: lo debido espera en cuotas, fuera de la mora.
        $this->assertEquals(0, (float) $madre->balance_due);
        $this->assertEquals(150000, (float) $madre->carried_out);
        $this->assertSame('paid', $madre->status);
        $this->assertSame([50000.0, 50000.0, 50000.0], $this->cuotas($madre)->pluck('amount')->map(fn ($m) => (float) $m)->all());

        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));
        $this->billing->generateMonthlyInvoices();

        $mensual = Invoice::where('customer_id', $this->cliente->id)->where('invoice_type', Invoice::TYPE_MONTHLY)->latest('id')->firstOrFail();
        $renglon = $mensual->items()->where('description', 'like', 'Cuota 1 de 3%')->first();
        $this->assertNotNull($renglon, 'La mensualidad debe cobrar la cuota 1.');
        $this->assertStringContainsString("ticket #{$this->ticket->id}", $renglon->description);
        $this->assertSame(1, $mensual->items()->where('description', 'like', 'Cuota %')->count(), 'Una cuota por mes, no las tres.');

        $this->assertLibrosCierran();
    }

    // ── En facturas aparte ───────────────────────────────────────────────

    #[Test]
    public function aparte_emite_una_factura_por_cuota_y_las_futuras_no_cuentan_como_mora(): void
    {
        $this->cobrar([
            'installments_count' => 3,
            'installments_mode' => CustomerInstallation::INSTALLMENTS_SEPARATE,
            'installments_first_due_date' => '2026-07-05',
        ])->assertCreated();
        $madre = $this->madre();

        $facturas = $this->cuotas($madre)->map(fn ($c) => $c->toInvoice);
        $this->assertSame(['2026-07-05', '2026-08-05', '2026-09-05'], $facturas->map(fn ($f) => $f->due_date->toDateString())->all());
        $this->assertTrue($facturas->every(fn ($f) => $f->invoice_type === Invoice::TYPE_SERVICE_CHARGE));
        // Sin ticket_id: si lo llevaran, la lista de cargos del ticket sumaría dos veces.
        $this->assertTrue($facturas->every(fn ($f) => $f->ticket_id === null));
        $this->assertStringContainsString("ticket #{$this->ticket->id}", $facturas->first()->notes);

        // Ninguna ha vencido: no cuentan para el tope de facturación.
        $contar = new \ReflectionMethod(BillingService::class, 'pendingInvoiceCount');
        $this->assertSame(0, $contar->invoke($this->billing, $this->tenant->id, $this->cliente->id));

        // Vencida la primera, esa sí cuenta.
        Carbon::setTestNow(Carbon::create(2026, 7, 6, 9, 0, 0));
        $this->assertSame(1, $contar->invoke($this->billing, $this->tenant->id, $this->cliente->id));

        $this->assertLibrosCierran();
    }

    // ── Cambiar el acuerdo después ───────────────────────────────────────

    #[Test]
    public function se_puede_cambiar_el_numero_y_el_modo_y_quitar_las_cuotas(): void
    {
        $this->cobrar(['installments_count' => 3, 'installments_mode' => CustomerInstallation::INSTALLMENTS_MONTHLY])->assertCreated();
        $madre = $this->madre();

        $this->cambiar($madre, ['installments_count' => 2, 'installments_mode' => CustomerInstallation::INSTALLMENTS_MONTHLY])->assertOk();
        $this->assertSame([75000.0, 75000.0], $this->cuotas($madre)->pluck('amount')->map(fn ($m) => (float) $m)->all());

        $this->cambiar($madre, [
            'installments_count' => 2, 'installments_mode' => CustomerInstallation::INSTALLMENTS_SEPARATE,
            'installments_first_due_date' => '2026-07-01',
        ])->assertOk()->assertJsonPath('invoice.installments.mode', CustomerInstallation::INSTALLMENTS_SEPARATE);
        $this->assertCount(2, $this->cuotas($madre)->filter(fn ($c) => $c->to_invoice_id !== null));

        // Quitarlas: las facturas de cuota intactas se ANULAN (no se borran) y
        // el cargo vuelve a deber todo.
        $anuladas = $this->cuotas($madre)->pluck('to_invoice_id');
        $this->cambiar($madre, ['installments_count' => 0])->assertOk();

        $madre->refresh();
        $this->assertEquals(150000, (float) $madre->balance_due);
        $this->assertCount(0, $this->cuotas($madre));
        $this->assertTrue(Invoice::whereIn('id', $anuladas)->get()->every(fn ($f) => $f->estaAnulada()));

        $this->assertLibrosCierran();
    }

    #[Test]
    public function lo_ya_cobrado_queda_fijo(): void
    {
        $this->cobrar([
            'installments_count' => 3, 'installments_mode' => CustomerInstallation::INSTALLMENTS_SEPARATE,
            'installments_first_due_date' => '2026-07-05',
        ])->assertCreated();
        $madre = $this->madre();

        // Pagan la cuota 1 por Facturación.
        $primera = $this->cuotas($madre)->first()->toInvoice;
        $this->billing->registerPayment([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id,
            'amount' => 50000, 'payment_date' => now()->toDateString(), 'method' => 'cash',
            'allocations' => [['invoice_id' => $primera->id, 'amount' => 50000]],
        ]);

        // No se puede cambiar de modo con cuotas ya cobradas.
        $this->cambiar($madre, ['installments_count' => 3, 'installments_mode' => CustomerInstallation::INSTALLMENTS_MONTHLY])->assertStatus(422);

        // Sí repartir lo que falta en más cuotas: la 1 sigue igual.
        $this->cambiar($madre, [
            'installments_count' => 5, 'installments_mode' => CustomerInstallation::INSTALLMENTS_SEPARATE,
            'installments_first_due_date' => '2026-07-05',
        ])->assertOk();
        $cuotas = $this->cuotas($madre);
        $this->assertCount(5, $cuotas);
        $this->assertEquals(50000, (float) $cuotas->first()->amount);
        $this->assertEquals(100000, round((float) $cuotas->slice(1)->sum('amount'), 2));

        // Quitar el acuerdo: la cuota pagada se queda; lo que faltaba vuelve al cargo.
        $this->cambiar($madre, ['installments_count' => 0])->assertOk();
        $this->assertEquals(100000, (float) $madre->fresh()->balance_due);
        $this->assertCount(1, $this->cuotas($madre));

        $this->assertLibrosCierran();
    }

    // ── Rechazos limpios ─────────────────────────────────────────────────

    #[Test]
    public function un_plan_invalido_no_deja_un_cargo_suelto(): void
    {
        $this->cobrar(['installments_count' => 3])->assertStatus(422)->assertJsonValidationErrors('installments_mode');
        $this->assertSame(0, Invoice::where('ticket_id', $this->ticket->id)->count());

        $this->cobrar(['installments_count' => CustomerInstallation::MAX_INSTALLMENTS + 1, 'installments_mode' => 'monthly'])->assertStatus(422);
        $this->assertSame(0, Invoice::where('ticket_id', $this->ticket->id)->count());
    }

    #[Test]
    public function la_lista_de_cargos_trae_el_plan_de_cada_uno(): void
    {
        $this->cobrar(['installments_count' => 2, 'installments_mode' => CustomerInstallation::INSTALLMENTS_MONTHLY])->assertCreated();
        $this->cobrar()->assertCreated();

        $cargos = $this->actingAs($this->operador)->getJson("/api/support/{$this->ticket->id}/charges")->assertOk()->json();

        $this->assertCount(2, $cargos, 'Las cuotas no aparecen como cargos aparte.');
        $conPlan = collect($cargos)->firstWhere('installments', '!=', null);
        $this->assertSame(2, $conPlan['installments']['count']);
        $this->assertSame(CustomerInstallation::INSTALLMENTS_MONTHLY, $conPlan['installments']['mode']);
        $this->assertNull(collect($cargos)->firstWhere('installments', null)['installments']);
    }

    #[Test]
    public function no_se_toca_un_cargo_de_otro_ticket(): void
    {
        $this->cobrar()->assertCreated();
        $madre = $this->madre();
        $otro = SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->cliente->id,
            'subject' => 'Otro', 'status' => 'open', 'priority' => 'low', 'category' => 'technical',
        ]);

        $this->actingAs($this->operador)
            ->putJson("/api/support/{$otro->id}/charges/{$madre->id}/installments", ['installments_count' => 2, 'installments_mode' => 'monthly'])
            ->assertNotFound();
    }
}
