<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\Billing;
use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceCarryover;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use App\Services\BillingService;
use App\Services\BooksAuditService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Acuerdo de pago» de la instalación: el saldo se divide en cuotas.
 *
 * Antes la casilla se guardaba y no hacía nada: la factura de instalación
 * nacía vencida ese mismo día por el total, y al cliente que había pactado
 * pagar en cuotas se le contaba como moroso desde el día siguiente.
 *
 * LAS REGLAS QUE SE FIJAN AQUÍ
 *
 *  1. Lo financiado sale de la factura de instalación por `carried_out`: la
 *     factura queda saldada y fuera de la mora y del corte.
 *  2. Modo «mensualidad»: cada factura mensual cobra UNA cuota, en orden.
 *  3. Modo «facturas aparte»: cada cuota es su propia factura, con
 *     vencimientos mes a mes; las que no han vencido no frenan la mensualidad.
 *  4. Editar el acuerdo reparte de nuevo lo que falta, pero lo ya cobrado no
 *     se toca; y lo que no cuadra se rechaza sin dejar nada a medias.
 *  5. Los libros cierran (C1, C2, C3, C4, C13) en todos los casos.
 */
class InstallationInstallmentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $cliente;
    private User $cartera;
    private Billing $config;
    private BillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 6, 10, 9, 0, 0));

        $this->billing = app(BillingService::class);
        $this->tenant  = Tenant::factory()->create();

        // `CheckPermission` deja pasar SIEMPRE a `role_id == 1`: se quema.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);

        $rol = Role::create([
            'name' => 'Cartera', 'code' => 'staff',
            'permissions' => [
                Permissions::VIEW_SUPPORT,
                Permissions::VIEW_CLIENTS,
                Permissions::ADD_CLIENTS,
                Permissions::EDIT_DISCOUNT,
            ],
            'tenant_id' => $this->tenant->id,
        ]);
        $this->cartera = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rol->id]);

        // Cliente facturable: router con día de facturación 15, plan de $50.000.
        $this->config = Billing::create([
            'create_invoice' => Carbon::create(2026, 1, 15)->toDateString(),
            'billing_mode'   => Billing::MODE_ANTICIPADO,
            'status'         => 'pending',
        ]);
        $router = Router::create([
            'name'              => 'Router cuotas',
            'tenant_id'         => $this->tenant->id,
            'billing_router_id' => $this->config->id,
            'status'            => 'active',
        ]);
        $plan  = Plan::factory()->create(['tenant_id' => $this->tenant->id, 'cost_product' => 50000]);
        $desde = Carbon::now()->subMonths(6)->startOfDay();

        $this->cliente = User::factory()->create(['tenant_id' => $this->tenant->id, 'created_at' => $desde]);
        CustomerProfile::create([
            'user_id'           => $this->cliente->id,
            'name'              => 'Ana',
            'last_name'         => 'Cuotas',
            'router_id'         => $router->id,
            'status'            => true,
            'installation_date' => $desde->toDateString(),
        ]);
        UserService::create([
            'user_id'         => $this->cliente->id,
            'service_plan_id' => $plan->id,
            'status'          => UserService::STATUS_ACTIVE,
            'start_date'      => $desde,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function orden(): CustomerInstallation
    {
        return CustomerInstallation::create([
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => $this->cliente->id,
            'scheduled_date' => now()->toDateString(),
            'address'        => 'Calle 1',
            'status'         => 'pendiente',
        ]);
    }

    private function guardar(CustomerInstallation $orden, array $datos)
    {
        return $this->actingAs($this->cartera)->putJson("/api/installations/{$orden->id}/billing", $datos);
    }

    /** $150.000 con $30.000 de abono: quedan $120.000 por financiar. */
    private function acuerdo(string $modo, int $cuotas, array $extra = []): array
    {
        return $extra + [
            'installation_cost'  => 150000,
            'payment_received'   => 30000,
            'payment_method'     => 'Efectivo',
            'payment_agreement'  => true,
            'installments_mode'  => $modo,
            'installments_count' => $cuotas,
        ];
    }

    private function madre(CustomerInstallation $orden): Invoice
    {
        return Invoice::where('installation_id', $orden->id)->firstOrFail();
    }

    /** @return \Illuminate\Support\Collection<int, InvoiceCarryover> */
    private function cuotas(Invoice $madre)
    {
        return InvoiceCarryover::installments()
            ->where('from_invoice_id', $madre->id)
            ->orderBy('installment_number')
            ->get();
    }

    private function mensual(): ?Invoice
    {
        return Invoice::where('customer_id', $this->cliente->id)
            ->where('invoice_type', Invoice::TYPE_MONTHLY)
            ->latest('id')
            ->first();
    }

    private function generarMensualidad(int $mes): ?Invoice
    {
        Carbon::setTestNow(Carbon::create(2026, $mes, 15, 9, 0, 0));
        $this->billing->generateMonthlyInvoices();

        return $this->mensual();
    }

    private function assertLibrosCierran(): void
    {
        $hallazgos = collect(app(BooksAuditService::class)->run($this->tenant->id))
            ->whereIn('code', ['C1', 'C2', 'C3', 'C4', 'C13']);

        $this->assertSame(
            [],
            $hallazgos->map(fn ($h) => $h['code'] . ': ' . $h['title'])->values()->all(),
            'Los libros tienen que cerrar con cuotas de instalación.'
        );
    }

    private function vencidasDelCliente(): int
    {
        return Invoice::where('customer_id', $this->cliente->id)
            ->where('due_date', '<', now())
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', ['void', 'cancelled', 'paid'])
            ->count();
    }

    // ── Modo mensualidad ─────────────────────────────────────────────────

    #[Test]
    public function el_acuerdo_saca_lo_financiado_de_la_factura_de_instalacion(): void
    {
        $orden = $this->orden();

        $respuesta = $this->guardar($orden, $this->acuerdo('monthly', 3))->assertOk();

        $madre = $this->madre($orden);
        $this->assertEquals(150000, (float) $madre->total);
        $this->assertEquals(0, (float) $madre->balance_due, 'Lo financiado no se debe hoy.');
        $this->assertEquals(120000, (float) $madre->carried_out);
        $this->assertSame('paid', $madre->status);

        $cuotas = $this->cuotas($madre);
        $this->assertCount(3, $cuotas);
        $this->assertSame([1, 2, 3], $cuotas->pluck('installment_number')->all());
        $this->assertSame([40000.0, 40000.0, 40000.0], $cuotas->map(fn ($c) => (float) $c->amount)->all());
        $this->assertTrue($cuotas->every(fn ($c) => $c->status === InvoiceCarryover::STATUS_PENDING));
        $this->assertTrue($cuotas->every(fn ($c) => $c->payment_id === null));

        $respuesta->assertJsonPath('installation.installment_plan.count', 3);
        $respuesta->assertJsonPath('installation.installment_plan.mode', 'monthly');
        $respuesta->assertJsonPath('installation.installment_plan.financed', 120000);

        $this->assertLibrosCierran();
    }

    #[Test]
    public function con_acuerdo_la_instalacion_no_cuenta_como_vencida(): void
    {
        $orden = $this->orden();
        $this->guardar($orden, $this->acuerdo('monthly', 3))->assertOk();

        // Una semana después: sin acuerdo, la factura de instalación ya
        // estaría vencida y contaría para el corte.
        Carbon::setTestNow(Carbon::create(2026, 6, 17, 9, 0, 0));

        $this->assertSame(0, $this->vencidasDelCliente());
    }

    #[Test]
    public function cada_mensualidad_cobra_una_cuota_en_orden(): void
    {
        // Nadie paga en esta prueba: sin quitar el tope, septiembre no se
        // facturaría por las tres mensualidades pendientes.
        $this->config->update(['stop_invoicing_extra' => null]);

        $orden = $this->orden();
        $this->guardar($orden, $this->acuerdo('monthly', 3))->assertOk();
        $madre = $this->madre($orden);

        foreach ([6 => 1, 7 => 2, 8 => 3] as $mes => $cuota) {
            $factura = $this->generarMensualidad($mes);

            $this->assertEquals(90000, (float) $factura->total, "La mensualidad de {$mes} trae plan + una cuota.");
            $this->assertEquals(40000, (float) $factura->carried_in);

            $item = $factura->items()->where('type', 'installment')->sole();
            $this->assertStringContainsString("Cuota {$cuota} de 3", $item->description);
            $this->assertStringContainsString($madre->number, $item->description);
        }

        // Septiembre: el acuerdo ya se cobró entero.
        $septiembre = $this->generarMensualidad(9);
        $this->assertEquals(50000, (float) $septiembre->total);
        $this->assertSame(0, $this->cuotas($madre)->where('status', InvoiceCarryover::STATUS_PENDING)->count());

        $this->assertLibrosCierran();
    }

    #[Test]
    public function la_vista_previa_de_la_mensualidad_suma_solo_la_cuota_del_mes(): void
    {
        $orden = $this->orden();
        $this->guardar($orden, $this->acuerdo('monthly', 3))->assertOk();

        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $perfil = CustomerProfile::where('user_id', $this->cliente->id)->firstOrFail();
        $fila   = $this->billing->explainMonthlyInvoice($perfil, $perfil->router, Carbon::now());

        $this->assertSame('missing', $fila['decision'] ?? null, json_encode($fila));
        $this->assertEquals(40000, (float) $fila['preview']['carryover']);
        $this->assertEquals(90000, (float) $fila['preview']['total']);
    }

    #[Test]
    public function la_caja_ve_las_cuotas_aparte_del_arrastre(): void
    {
        $orden = $this->orden();
        $this->guardar($orden, $this->acuerdo('monthly', 3))->assertOk();

        $caja = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => Role::create([
                'name' => 'Caja', 'code' => 'staff',
                'permissions' => [Permissions::VIEW_BILLING], 'tenant_id' => $this->tenant->id,
            ])->id,
        ]);

        // El arrastre se cobra ENTERO en la próxima factura; las cuotas van una
        // por mes. Sumarlas al arrastre haría anunciar $120.000 «para la
        // próxima factura».
        $this->actingAs($caja)
            ->getJson("/api/billing/customers/{$this->cliente->id}/balance")
            ->assertOk()
            ->assertJsonPath('carryover_balance', 0)
            ->assertJsonPath('installments_balance', 120000)
            ->assertJsonPath('installments_pending', 3);
    }

    #[Test]
    public function el_reparto_va_en_pesos_enteros_y_la_ultima_cuota_lleva_el_resto(): void
    {
        $orden = $this->orden();
        $this->guardar($orden, $this->acuerdo('monthly', 3, ['payment_received' => 50000]))->assertOk();

        $this->assertSame(
            [33333.0, 33333.0, 33334.0],
            $this->cuotas($this->madre($orden))->map(fn ($c) => (float) $c->amount)->all()
        );
    }

    #[Test]
    public function volver_a_guardar_reparte_de_nuevo_lo_que_nadie_ha_cobrado(): void
    {
        $orden = $this->orden();
        $this->guardar($orden, $this->acuerdo('monthly', 3))->assertOk();

        $this->guardar($orden, $this->acuerdo('monthly', 2, [
            'discount' => 20000, 'discount_reason' => 'Retención',
        ]))->assertOk();

        $madre = $this->madre($orden);
        $this->assertSame([50000.0, 50000.0], $this->cuotas($madre)->map(fn ($c) => (float) $c->amount)->all());
        $this->assertEquals(100000, (float) $madre->carried_out);
        $this->assertLibrosCierran();
    }

    #[Test]
    public function lo_ya_cobrado_queda_fijo_y_el_resto_se_reparte(): void
    {
        $orden = $this->orden();
        $this->guardar($orden, $this->acuerdo('monthly', 3))->assertOk();
        $this->generarMensualidad(6); // cobra la cuota 1

        // Bajar a 1 cuota cuando ya se cobró una y quedan $80.000: no cuadra.
        $this->guardar($orden, $this->acuerdo('monthly', 1))
            ->assertStatus(422)
            ->assertJsonValidationErrors('installments_count');

        // Un descuento de $20.000: la cuota 1 sigue en $40.000 y las otras
        // dos se reparten los $60.000 que quedan.
        $this->guardar($orden, $this->acuerdo('monthly', 3, [
            'discount' => 20000, 'discount_reason' => 'Retención',
        ]))->assertOk();

        $cuotas = $this->cuotas($this->madre($orden));
        $this->assertSame([40000.0, 30000.0, 30000.0], $cuotas->map(fn ($c) => (float) $c->amount)->all());
        $this->assertSame(InvoiceCarryover::STATUS_APPLIED, $cuotas[0]->status);
        $this->assertLibrosCierran();
    }

    #[Test]
    public function con_acuerdo_hay_que_decir_en_cuantas_cuotas_y_nada_queda_a_medias(): void
    {
        $orden = $this->orden();

        $this->guardar($orden, [
            'installation_cost' => 150000,
            'payment_agreement' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('installments_count');

        $this->assertNull($orden->fresh()->installation_cost, 'La orden no puede quedar guardada a medias.');
        $this->assertDatabaseCount('invoices', 0);
    }

    #[Test]
    public function si_todo_se_pago_el_acuerdo_no_exige_cuotas(): void
    {
        $orden = $this->orden();

        $this->guardar($orden, [
            'installation_cost' => 100000,
            'payment_received'  => 100000,
            'payment_agreement' => true,
        ])->assertOk();

        $this->assertSame('paid', $this->madre($orden)->status);
        $this->assertCount(0, $this->cuotas($this->madre($orden)));
    }

    #[Test]
    public function quitar_el_acuerdo_devuelve_el_saldo_a_la_factura(): void
    {
        $orden = $this->orden();
        $this->guardar($orden, $this->acuerdo('monthly', 3))->assertOk();

        $this->guardar($orden, ['payment_agreement' => false])->assertOk();

        $madre = $this->madre($orden);
        $this->assertEquals(120000, (float) $madre->balance_due);
        $this->assertEquals(0, (float) $madre->carried_out);
        $this->assertSame('partial', $madre->status);
        $this->assertCount(0, $this->cuotas($madre));
        $this->assertNull($orden->fresh()->installments_count, 'Sin acuerdo no queda «3 cuotas» guardado.');
        $this->assertLibrosCierran();
    }

    // ── Modo facturas aparte ─────────────────────────────────────────────

    #[Test]
    public function en_facturas_aparte_cada_cuota_es_una_factura_con_su_vencimiento(): void
    {
        $orden = $this->orden();
        $this->guardar($orden, $this->acuerdo('separate', 3, [
            'installments_first_due_date' => '2026-07-10',
        ]))->assertOk();

        $madre = $this->madre($orden);
        $this->assertEquals(0, (float) $madre->balance_due);
        $this->assertEquals(120000, (float) $madre->carried_out);

        $cuotas = $this->cuotas($madre);
        $this->assertCount(3, $cuotas);
        $this->assertTrue($cuotas->every(fn ($c) => $c->status === InvoiceCarryover::STATUS_APPLIED));

        $facturas = Invoice::whereIn('id', $cuotas->pluck('to_invoice_id'))->orderBy('due_date')->get();
        $this->assertSame(['2026-07-10', '2026-08-10', '2026-09-10'], $facturas->map(fn ($f) => $f->due_date->toDateString())->all());
        $this->assertTrue($facturas->every(fn ($f) => $f->invoice_type === Invoice::TYPE_INSTALLATION));
        $this->assertTrue($facturas->every(fn ($f) => (float) $f->total === 40000.0 && (float) $f->carried_in === 40000.0));
        $this->assertTrue($facturas->every(fn ($f) => $f->installation_id === null));

        // La orden sigue apuntando a SU factura, no a una cuota.
        $this->assertSame($madre->id, $orden->fresh()->invoice->id);

        $this->assertLibrosCierran();
    }

    #[Test]
    public function las_cuotas_que_no_han_vencido_no_frenan_la_mensualidad(): void
    {
        // Tope estricto: con 1 factura pendiente se deja de facturar.
        $this->config->update(['overdue_invoices' => 1, 'stop_invoicing_extra' => 0]);

        $orden = $this->orden();
        $this->guardar($orden, $this->acuerdo('separate', 3, [
            'installments_first_due_date' => '2026-07-10',
        ]))->assertOk();

        $junio = $this->generarMensualidad(6);

        $this->assertNotNull($junio, 'Tres cuotas futuras no pueden dejar al cliente sin mensualidad.');
        $this->assertEquals(50000, (float) $junio->total, 'En modo aparte la mensualidad no trae cuotas.');
    }

    #[Test]
    public function una_cuota_aparte_vencida_si_cuenta_para_el_corte(): void
    {
        $orden = $this->orden();
        $this->guardar($orden, $this->acuerdo('separate', 3, [
            'installments_first_due_date' => '2026-07-10',
        ]))->assertOk();

        Carbon::setTestNow(Carbon::create(2026, 7, 12, 9, 0, 0));

        $this->assertSame(1, $this->vencidasDelCliente());
    }

    #[Test]
    public function en_facturas_aparte_volver_a_guardar_reescribe_en_su_sitio(): void
    {
        $orden = $this->orden();
        $this->guardar($orden, $this->acuerdo('separate', 3, [
            'installments_first_due_date' => '2026-07-10',
        ]))->assertOk();

        $antes = $this->cuotas($this->madre($orden))->pluck('to_invoice_id')->all();

        // Mismo número de cuotas, otro valor: las mismas facturas, nuevos montos.
        $this->guardar($orden, $this->acuerdo('separate', 3, [
            'installments_first_due_date' => '2026-07-10',
            'installation_cost'           => 120000,
        ]))->assertOk();

        $cuotas = $this->cuotas($this->madre($orden));
        $this->assertSame($antes, $cuotas->pluck('to_invoice_id')->all(), 'No se gastan números de factura nuevos.');
        $this->assertSame([30000.0, 30000.0, 30000.0], $cuotas->map(fn ($c) => (float) $c->amount)->all());

        // Bajar a 2: la tercera factura se ANULA, no se borra.
        $this->guardar($orden, $this->acuerdo('separate', 2, [
            'installments_first_due_date' => '2026-07-10',
            'installation_cost'           => 120000,
        ]))->assertOk();

        $this->assertCount(2, $this->cuotas($this->madre($orden)));
        $this->assertTrue(Invoice::find($antes[2])->estaAnulada());
        $this->assertLibrosCierran();
    }

    #[Test]
    public function una_cuota_aparte_pagada_bloquea_el_cambio_de_modo(): void
    {
        $orden = $this->orden();
        $this->guardar($orden, $this->acuerdo('separate', 3, [
            'installments_first_due_date' => '2026-07-10',
        ]))->assertOk();

        $primera = Invoice::find($this->cuotas($this->madre($orden))->first()->to_invoice_id);

        $this->billing->registerPayment([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->cliente->id,
            'amount'       => 40000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
            'allocations'  => [['invoice_id' => $primera->id, 'amount' => 40000]],
        ]);

        $this->guardar($orden, $this->acuerdo('monthly', 3))
            ->assertStatus(422)
            ->assertJsonValidationErrors('installments_mode');

        // La pagada no se toca; las otras dos se pueden reescribir.
        $this->guardar($orden, $this->acuerdo('separate', 3, [
            'installments_first_due_date' => '2026-07-10',
            'discount' => 20000, 'discount_reason' => 'Retención',
        ]))->assertOk();

        $this->assertSame(
            [40000.0, 30000.0, 30000.0],
            $this->cuotas($this->madre($orden))->map(fn ($c) => (float) $c->amount)->all()
        );
        $this->assertLibrosCierran();
    }

    #[Test]
    public function guardar_la_cartera_no_resucita_una_factura_anulada(): void
    {
        $orden = $this->orden();
        $this->guardar($orden, ['installation_cost' => 150000])->assertOk();

        $madre = $this->madre($orden);
        $this->billing->voidInvoice($madre, 'Se facturó por error', $this->cartera->id);

        $this->guardar($orden, $this->acuerdo('monthly', 3))
            ->assertOk()
            ->assertJsonPath('invoice.status', $madre->fresh()->status);

        $this->assertTrue($madre->fresh()->estaAnulada());
        $this->assertCount(0, $this->cuotas($madre));
    }
}
