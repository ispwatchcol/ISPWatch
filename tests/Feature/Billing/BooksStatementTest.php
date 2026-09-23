<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BooksStatementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Extracto conciliable de un mes.
 *
 * Lo que se verifica no es «la cifra correcta» —no hay una sola— sino que cada
 * criterio mida lo que dice medir, y que la diferencia entre dos criterios
 * salga con su importe exacto. De eso depende poder decirle a un cliente «tus
 * tres millones son las facturas anuladas» en vez de discutir a ciegas.
 */
class BooksStatementTest extends TestCase
{
    use RefreshDatabase;

    private BooksStatementService $extracto;
    private Tenant $tenant;
    private User $cliente;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-15 10:00:00');

        $this->extracto = app(BooksStatementService::class);
        $this->tenant   = Tenant::factory()->create();
        $this->cliente  = $this->customer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        CustomerProfile::create([
            'user_id'        => $user->id,
            'tenant_id'      => $this->tenant->id,
            'name'           => 'Cliente',
            'last_name'      => 'Prueba',
            'status'         => true,
            'credit_balance' => 0,
        ]);

        return $user;
    }

    private function invoice(float $total, array $extra = []): Invoice
    {
        $this->seq++;

        $invoice = Invoice::create($extra + [
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->cliente->id,
            'number'       => 'FAC-' . $this->seq,
            'invoice_type' => Invoice::TYPE_MONTHLY,
            'issue_date'   => '2026-08-01',
            'due_date'     => '2026-08-10',
            'period_start' => '2026-08-01',
            'period_end'   => '2026-08-31',
            'subtotal'     => $total,
            'tax'          => 0,
            'total'        => $total,
            'balance_due'  => $total,
            'status'       => 'issued',
        ]);

        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'type'        => 'service',
            'description' => 'Servicio',
            'quantity'    => 1,
            'unit_price'  => $total,
            'amount'      => $total,
        ]);

        return $invoice;
    }

    private function payment(float $amount, array $extra = []): Payment
    {
        return Payment::create($extra + [
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->cliente->id,
            'amount'       => $amount,
            'payment_date' => '2026-08-05',
            'method'       => 'cash',
            'status'       => 'completed',
        ]);
    }

    private function agosto(): array
    {
        return $this->extracto->forMonth($this->tenant->id, Carbon::parse('2026-08-01'));
    }

    /** @return array<string,float> importe de cada puente, por su texto */
    private function puentes(array $extracto): array
    {
        $out = [];

        foreach ($extracto['puentes'] as $p) {
            $out[$p['contra']] = $p['delta'];
        }

        return $out;
    }

    // ── Facturado ────────────────────────────────────────────────────────

    #[Test]
    public function las_facturas_anuladas_salen_del_panel_pero_se_cuentan_aparte(): void
    {
        $this->invoice(100000);
        $this->invoice(30000, ['status' => Invoice::STATUS_VOID, 'balance_due' => 0]);

        $e = $this->agosto();

        $this->assertEqualsWithDelta(100000, $e['facturado']['panel']['value'], 0.01);
        $this->assertEqualsWithDelta(130000, $e['facturado']['con_anuladas']['value'], 0.01);

        $this->assertContains(
            30000.0,
            array_values($this->puentes($e)),
            'La anulada no apareció como diferencia de criterio con su importe.'
        );
    }

    #[Test]
    public function contar_por_periodo_y_por_emision_da_cifras_distintas(): void
    {
        // Emitida el 1 de agosto pero por el servicio de julio: el cliente la
        // anota en julio y el sistema en agosto.
        $this->invoice(100000);
        $this->invoice(45000, [
            'issue_date'   => '2026-08-01',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
        ]);

        $e = $this->agosto();

        $this->assertEqualsWithDelta(145000, $e['facturado']['panel']['value'], 0.01);
        $this->assertEqualsWithDelta(100000, $e['facturado']['por_periodo']['value'], 0.01);

        $this->assertContains(-45000.0, array_values($this->puentes($e)));
    }

    #[Test]
    public function las_instalaciones_no_cuentan_como_mensualidad(): void
    {
        $this->invoice(100000);
        $this->invoice(250000, ['invoice_type' => Invoice::TYPE_INSTALLATION]);

        $e = $this->agosto();

        $this->assertEqualsWithDelta(350000, $e['facturado']['panel']['value'], 0.01);
        $this->assertEqualsWithDelta(100000, $e['facturado']['solo_mensualidades']['value'], 0.01);
        $this->assertContains(250000.0, array_values($this->puentes($e)));
    }

    // ── Recaudado ────────────────────────────────────────────────────────

    /**
     * La diferencia entre las dos pantallas del producto: Recaudos suma todos
     * los pagos, Finanzas sólo los `completed`.
     */
    #[Test]
    public function el_listado_de_recaudos_y_el_panel_no_suman_lo_mismo(): void
    {
        // `void` es el único estado distinto de `completed` que admite el enum
        // de la columna; PostgreSQL rechaza cualquier otro con un CHECK y SQLite
        // no, así que un valor inventado sólo falla en CI.
        $this->payment(80000);
        $this->payment(25000, ['status' => 'void']);

        $e = $this->agosto();

        $this->assertEqualsWithDelta(80000, $e['recaudado']['panel']['value'], 0.01);
        $this->assertEqualsWithDelta(105000, $e['recaudado']['listado']['value'], 0.01);
        $this->assertContains(25000.0, array_values($this->puentes($e)));
    }

    #[Test]
    public function un_pago_digitado_tarde_cae_en_meses_distintos_segun_el_criterio(): void
    {
        // Pagó el 5 de agosto, pero se digitó en septiembre.
        $pago = $this->payment(70000);
        $pago->forceFill(['created_at' => '2026-09-03 09:00:00'])->save();

        $e = $this->agosto();

        $this->assertEqualsWithDelta(70000, $e['recaudado']['panel']['value'], 0.01);
        $this->assertEqualsWithDelta(0, $e['recaudado']['por_digitacion']['value'], 0.01);
        $this->assertContains(-70000.0, array_values($this->puentes($e)));
    }

    #[Test]
    public function los_recaudos_sin_titular_se_reportan_aparte(): void
    {
        $this->payment(50000);
        $this->payment(15000, ['customer_id' => null, 'customer_name' => 'Cliente retirado']);

        $e = $this->agosto();

        $this->assertEqualsWithDelta(65000, $e['recaudado']['panel']['value'], 0.01);
        $this->assertEqualsWithDelta(15000, $e['recaudado']['sin_titular']['value'], 0.01);
        $this->assertContains(15000.0, array_values($this->puentes($e)));
    }

    // ── Sin diferencias ──────────────────────────────────────────────────

    #[Test]
    public function un_mes_limpio_no_produce_ninguna_diferencia_de_criterio(): void
    {
        $this->invoice(100000);

        // Por el camino real, para que el pago quede ASIGNADO a la factura. Un
        // `Payment::create()` a secas no salda nada, y entonces el extracto
        // tiene razón en señalar que ese dinero no respalda ninguna factura.
        app(\App\Services\BillingService::class)->registerPayment([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->cliente->id,
            'amount'       => 100000,
            'payment_date' => '2026-08-05',
            'method'       => 'cash',
        ]);

        $e = $this->agosto();

        $this->assertSame(
            [],
            $e['puentes'],
            'Un mes sin anuladas, sin arrastre y sin pagos raros no debería tener diferencias.'
        );
    }

    // ── El comando ───────────────────────────────────────────────────────

    #[Test]
    public function el_comando_senala_la_diferencia_que_explica_el_desfase_reclamado(): void
    {
        $this->invoice(100000);
        $this->invoice(3000000, ['status' => Invoice::STATUS_VOID, 'balance_due' => 0]);

        $this->artisan("billing:statement --tenant={$this->tenant->id} --month=2026-08 --target=3000000")
            ->expectsOutputToContain('coincide con')
            ->assertExitCode(0);
    }

    #[Test]
    public function el_comando_avisa_cuando_ningun_criterio_explica_el_desfase(): void
    {
        $this->invoice(100000);
        $this->payment(100000);

        $this->artisan("billing:statement --tenant={$this->tenant->id} --month=2026-08 --target=3000000")
            ->expectsOutputToContain('NO se explica')
            ->assertExitCode(0);
    }

    #[Test]
    public function el_comando_exige_una_empresa_existente(): void
    {
        $this->artisan('billing:statement --tenant=999999 --month=2026-08')
            ->assertExitCode(1);
    }
}
