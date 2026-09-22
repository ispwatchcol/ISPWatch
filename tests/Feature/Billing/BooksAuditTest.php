<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerCredit;
use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceCarryover;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use App\Services\BooksAuditService;
use App\Services\InstallationBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Auditoría de los libros.
 *
 * Dos mitades, y la primera importa tanto como la segunda:
 *
 *  1. SILENCIO sobre libros sanos. Un auditor que grita por movimientos
 *     legítimos se acaba silenciando, y con él se pierden los hallazgos de
 *     verdad. El caso que lo prueba es el que rompía al verificador viejo: un
 *     cliente que GASTÓ su saldo a favor.
 *
 *  2. Que cada comprobación efectivamente dispare cuando se rompe lo suyo.
 */
class BooksAuditTest extends TestCase
{
    use RefreshDatabase;

    private BooksAuditService $auditor;
    private BillingService $billing;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = app(BooksAuditService::class);
        $this->billing = app(BillingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function customer(Tenant $tenant): User
    {
        $this->seq++;

        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id'        => $user->id,
            'tenant_id'      => $tenant->id,
            'name'           => "Cliente{$this->seq}",
            'last_name'      => "Apellido{$this->seq}",
            'status'         => true,
            'credit_balance' => 0,
        ]);

        return $user;
    }

    private function invoice(Tenant $tenant, User $customer, float $total, array $extra = []): Invoice
    {
        $invoice = Invoice::create($extra + [
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'number'       => uniqid('INV-'),
            'invoice_type' => Invoice::TYPE_MONTHLY,
            'issue_date'   => now()->subDays(10),
            'due_date'     => now()->addDays(5),
            'period_start' => now()->startOfMonth(),
            'period_end'   => now()->endOfMonth(),
            'subtotal'     => $total,
            'tax'          => 0,
            'total'        => $total,
            'balance_due'  => $total,
            'status'       => 'issued',
        ]);

        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'type'        => 'service',
            'description' => 'Servicio de internet',
            'quantity'    => 1,
            'unit_price'  => $total,
            'amount'      => $total,
        ]);

        return $invoice;
    }

    private function pay(Tenant $tenant, User $customer, float $amount): Payment
    {
        return $this->billing->registerPayment([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'amount'       => $amount,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ]);
    }

    /** @return array<string,array<string,mixed>> hallazgos indexados por código */
    private function audit(?int $tenantId = null): array
    {
        $out = [];

        foreach ($this->auditor->run($tenantId) as $h) {
            $out[$h['code']] = $h;
        }

        return $out;
    }

    private function assertLibrosCierran(?int $tenantId = null): void
    {
        $h = $this->audit($tenantId);

        $this->assertSame(
            [],
            array_keys($h),
            'La auditoría denunció libros que están sanos: ' . json_encode(
                array_map(fn ($x) => $x['title'] . ' (' . $x['count'] . ')', $h),
                JSON_UNESCAPED_UNICODE
            )
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1 · Silencio sobre libros sanos
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function unos_libros_sanos_no_producen_ningun_hallazgo(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $this->invoice($tenant, $customer, 60000);
        $this->pay($tenant, $customer, 60000);

        $this->assertLibrosCierran($tenant->id);
    }

    /**
     * EL caso. Un cliente paga de más, y meses después su saldo a favor paga
     * una factura. Aplicar saldo baja `balance_due` sin crear ninguna
     * asignación, así que a partir de ahí ese dinero no está ni en
     * `payment_allocations` ni en `credit_balance`.
     *
     * El verificador viejo comparaba contra `credit_balance` y denunciaba a
     * este cliente por los $10.000 que gastó. No era un descuadre: era el saldo
     * a favor haciendo exactamente lo suyo.
     */
    #[Test]
    public function un_cliente_que_gasto_su_saldo_a_favor_no_es_un_descuadre(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        // Paga $70.000 una factura de $60.000: quedan $10.000 de saldo.
        $this->invoice($tenant, $customer, 60000);
        $this->pay($tenant, $customer, 70000);

        $this->assertEqualsWithDelta(
            10000,
            CustomerProfile::where('user_id', $customer->id)->value('credit_balance'),
            0.01
        );

        // La factura del mes siguiente se come ese saldo.
        $segunda = $this->invoice($tenant, $customer, 60000);
        $this->billing->applyCreditToManualInvoice($segunda);

        $this->assertEqualsWithDelta(50000, $segunda->refresh()->balance_due, 0.01);
        $this->assertEqualsWithDelta(
            0,
            CustomerProfile::where('user_id', $customer->id)->value('credit_balance'),
            0.01
        );

        $this->assertLibrosCierran($tenant->id);
    }

    /** Un abono parcial cierra la factura y manda el faltante al arrastre. */
    #[Test]
    public function un_abono_parcial_con_arrastre_no_es_un_descuadre(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $factura = $this->invoice($tenant, $customer, 60000);
        $this->pay($tenant, $customer, 40000);

        $factura->refresh();

        // La factura queda saldada y los $20.000 viajan al arrastre.
        $this->assertEqualsWithDelta(0, $factura->balance_due, 0.01);
        $this->assertEqualsWithDelta(20000, $factura->carried_out, 0.01);

        $this->assertLibrosCierran($tenant->id);
    }

    #[Test]
    public function una_factura_anulada_no_produce_hallazgos(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $factura = $this->invoice($tenant, $customer, 60000);
        $this->billing->voidInvoice($factura, 'Emitida por error');

        $this->assertLibrosCierran($tenant->id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2 · Cada comprobación dispara cuando se rompe lo suyo
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function c1_detecta_una_factura_cuyo_saldo_no_cuadra(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $factura = $this->invoice($tenant, $customer, 60000);
        $this->pay($tenant, $customer, 60000);

        // Alguien toca el saldo a pelo: la factura dice que aún se deben
        // $25.000 aunque está íntegramente pagada.
        $factura->refresh()->forceFill(['balance_due' => 25000])->save();

        $h = $this->audit($tenant->id);

        $this->assertArrayHasKey('C1', $h);
        $this->assertSame(1, $h['C1']['count']);
        $this->assertEqualsWithDelta(25000, $h['C1']['amount'], 0.01);
        $this->assertSame('critical', $h['C1']['severity']);
    }

    #[Test]
    public function c2_detecta_una_anulada_que_conserva_saldo(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $this->invoice($tenant, $customer, 60000, [
            'status'      => Invoice::STATUS_VOID,
            'balance_due' => 60000,
        ]);

        $h = $this->audit($tenant->id);

        $this->assertArrayHasKey('C2', $h);
        $this->assertEqualsWithDelta(60000, $h['C2']['amount'], 0.01);
    }

    #[Test]
    public function c3_detecta_una_factura_que_no_suma_sus_renglones(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $factura = $this->invoice($tenant, $customer, 60000);

        // Se agrega un renglón sin tocar los totales: el PDF suma $75.000 y la
        // factura dice $60.000.
        InvoiceItem::create([
            'invoice_id'  => $factura->id,
            'type'        => 'service',
            'description' => 'Punto adicional de TV',
            'quantity'    => 1,
            'unit_price'  => 15000,
            'amount'      => 15000,
        ]);

        $h = $this->audit($tenant->id);

        $this->assertArrayHasKey('C3', $h);
        $this->assertEqualsWithDelta(15000, $h['C3']['amount'], 0.01);
    }

    #[Test]
    public function c5_detecta_un_pago_con_mas_asignado_del_que_entro(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $factura = $this->invoice($tenant, $customer, 60000);
        $pago    = $this->pay($tenant, $customer, 60000);

        // Se duplica la asignación: $120.000 aplicados sobre un pago de $60.000.
        PaymentAllocation::create([
            'payment_id' => $pago->id,
            'invoice_id' => $factura->id,
            'amount'     => 60000,
        ]);

        $h = $this->audit($tenant->id);

        $this->assertArrayHasKey('C5', $h);
        $this->assertEqualsWithDelta(60000, $h['C5']['amount'], 0.01);
    }

    /**
     * El caso que motivó todo el módulo de auditoría: se borra una factura ya
     * pagada y el dinero deja de respaldar nada.
     */
    #[Test]
    public function c6_detecta_dinero_recibido_que_no_respalda_nada(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $factura = $this->invoice($tenant, $customer, 60000);
        $this->pay($tenant, $customer, 60000);

        // La asignación desaparece sin devolver el dinero a ninguna parte.
        PaymentAllocation::where('invoice_id', $factura->id)->delete();

        $h = $this->audit($tenant->id);

        $this->assertArrayHasKey('C6', $h);
        $this->assertEqualsWithDelta(60000, $h['C6']['amount'], 0.01);
    }

    #[Test]
    public function c7_detecta_un_saldo_a_favor_que_su_libro_no_explica(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        // Saldo escrito a pelo en la ficha, sin ningún movimiento detrás.
        CustomerProfile::where('user_id', $customer->id)->update(['credit_balance' => 80000]);

        $h = $this->audit($tenant->id);

        $this->assertArrayHasKey('C7', $h);
        $this->assertEqualsWithDelta(80000, $h['C7']['amount'], 0.01);
    }

    #[Test]
    public function c8_detecta_un_pago_aplicado_a_la_factura_de_otra_empresa(): void
    {
        $unaEmpresa  = Tenant::factory()->create();
        $otraEmpresa = Tenant::factory()->create();

        $clienteA = $this->customer($unaEmpresa);
        $clienteB = $this->customer($otraEmpresa);

        $facturaDeB = $this->invoice($otraEmpresa, $clienteB, 60000);
        $pagoDeA    = $this->pay($unaEmpresa, $clienteA, 60000);

        PaymentAllocation::create([
            'payment_id' => $pagoDeA->id,
            'invoice_id' => $facturaDeB->id,
            'amount'     => 60000,
        ]);

        $h = $this->audit($unaEmpresa->id);

        $this->assertArrayHasKey('C8', $h);
        $this->assertEqualsWithDelta(60000, $h['C8']['amount'], 0.01);
    }

    #[Test]
    public function c10_detecta_los_pagos_que_el_panel_de_finanzas_no_ve(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $this->invoice($tenant, $customer, 60000);
        $pago = $this->pay($tenant, $customer, 60000);

        $pago->forceFill(['status' => 'pending'])->save();

        $h = $this->audit($tenant->id);

        $this->assertArrayHasKey('C10', $h);
        $this->assertEqualsWithDelta(60000, $h['C10']['amount'], 0.01);
        $this->assertSame('warning', $h['C10']['severity']);
    }

    /**
     * C12 es una red de seguridad, no la defensa principal: el número repetido
     * lo impide un índice único (tenant_id, number) desde la migración del
     * numerador. Lo que se verifica aquí es esa defensa —que la base rechace el
     * duplicado— porque es la que de verdad protege la identidad fiscal del
     * documento; C12 queda para el caso de que ese índice llegue a faltar en
     * algún esquema.
     */
    #[Test]
    public function la_base_rechaza_dos_facturas_con_el_mismo_numero(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $this->invoice($tenant, $customer, 60000, ['number' => 'FAC-001']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->invoice($tenant, $customer, 60000, ['number' => 'FAC-001']);
    }

    /** Dos empresas distintas SÍ pueden usar el mismo número: cada una lleva el suyo. */
    #[Test]
    public function dos_empresas_pueden_usar_el_mismo_numero_de_factura(): void
    {
        $unaEmpresa  = Tenant::factory()->create();
        $otraEmpresa = Tenant::factory()->create();

        $this->invoice($unaEmpresa, $this->customer($unaEmpresa), 60000, ['number' => 'FAC-001']);
        $this->invoice($otraEmpresa, $this->customer($otraEmpresa), 60000, ['number' => 'FAC-001']);

        $this->assertArrayNotHasKey('C12', $this->audit());
    }

    #[Test]
    public function c13_detecta_un_arrastre_pendiente_de_una_factura_anulada(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $factura = $this->invoice($tenant, $customer, 60000, [
            'status'      => Invoice::STATUS_VOID,
            'balance_due' => 0,
        ]);

        InvoiceCarryover::create([
            'tenant_id'       => $tenant->id,
            'customer_id'     => $customer->id,
            'from_invoice_id' => $factura->id,
            'amount'          => 20000,
            'status'          => InvoiceCarryover::STATUS_PENDING,
        ]);

        $h = $this->audit($tenant->id);

        $this->assertArrayHasKey('C13', $h);
        $this->assertEqualsWithDelta(20000, $h['C13']['amount'], 0.01);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3 · La auditoría no cruza empresas
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function el_descuadre_de_una_empresa_no_aparece_en_la_auditoria_de_otra(): void
    {
        $rota = Tenant::factory()->create();
        $sana = Tenant::factory()->create();

        $cliente = $this->customer($rota);
        $factura = $this->invoice($rota, $cliente, 60000);
        $factura->forceFill(['balance_due' => 12345])->save();

        $clienteSano = $this->customer($sana);
        $this->invoice($sana, $clienteSano, 50000);
        $this->pay($sana, $clienteSano, 50000);

        $this->assertArrayHasKey('C1', $this->audit($rota->id));
        $this->assertLibrosCierran($sana->id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4 · El excedente del cobro de una instalación
    // ─────────────────────────────────────────────────────────────────────

    /**
     * El instalador cobra más de lo que vale la instalación. Antes,
     * `min(recibido, total)` dejaba la diferencia sin asignar y sin acreditar:
     * dinero recibido que no estaba en ninguna parte de los libros.
     */
    #[Test]
    public function el_excedente_cobrado_en_una_instalacion_entra_como_saldo_a_favor(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $instalacion = CustomerInstallation::create([
            'tenant_id'         => $tenant->id,
            'customer_id'       => $customer->id,
            'scheduled_date'    => now()->toDateString(),
            'address'           => 'Calle 1',
            'status'            => 'pendiente',
            'installation_cost' => 100000,
            'payment_received'  => 150000,
            'payment_method'    => 'cash',
        ]);

        app(InstallationBillingService::class)
            ->upsertInstallationInvoice($instalacion, $tenant->id);

        $this->assertEqualsWithDelta(
            50000,
            CustomerProfile::where('user_id', $customer->id)->value('credit_balance'),
            0.01,
            'El excedente del cobro de instalación no quedó como saldo a favor.'
        );

        $this->assertLibrosCierran($tenant->id);
    }

    /** Corregir a la baja lo recibido no puede dejar saldo inventado. */
    #[Test]
    public function corregir_a_la_baja_el_cobro_de_instalacion_ajusta_el_saldo(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $instalacion = CustomerInstallation::create([
            'tenant_id'         => $tenant->id,
            'customer_id'       => $customer->id,
            'scheduled_date'    => now()->toDateString(),
            'address'           => 'Calle 1',
            'status'            => 'pendiente',
            'installation_cost' => 100000,
            'payment_received'  => 150000,
            'payment_method'    => 'cash',
        ]);

        $servicio = app(InstallationBillingService::class);
        $servicio->upsertInstallationInvoice($instalacion, $tenant->id);

        // El instalador se equivocó: fueron $120.000, no $150.000.
        $instalacion->update(['payment_received' => 120000]);
        $servicio->upsertInstallationInvoice($instalacion->refresh(), $tenant->id);

        $this->assertEqualsWithDelta(
            20000,
            CustomerProfile::where('user_id', $customer->id)->value('credit_balance'),
            0.01
        );

        $this->assertEqualsWithDelta(
            20000,
            CustomerCredit::ledgerBalanceFor($customer->id),
            0.01
        );

        $this->assertLibrosCierran($tenant->id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5 · El verificador viejo, ya corregido
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function verify_orphan_payments_ya_no_denuncia_a_quien_gasto_su_saldo(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $this->invoice($tenant, $customer, 60000);
        $this->pay($tenant, $customer, 70000);

        $segunda = $this->invoice($tenant, $customer, 60000);
        $this->billing->applyCreditToManualInvoice($segunda);

        $this->assertSame(
            [],
            $this->billing->auditOrphanPayments($tenant->id),
            'El verificador de dinero huérfano volvió a denunciar un saldo a favor legítimo.'
        );
    }
}
