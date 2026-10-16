<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerInstallation;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use App\Services\BooksAuditService;
use App\Services\InstallationBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KAN-124 (P-95): la cartera sólo toca el pago que ella creó.
 *
 * Antes `syncPayment()` tomaba la PRIMERA asignación de la factura de
 * instalación como suya. Si esa era un abono hecho en Facturación, guardar la
 * cartera con «Valor recibido» en 0 lo borraba, y con otro valor le reescribía
 * el monto. Ahora el pago de la cartera lleva `installation_id` y los demás se
 * respetan.
 */
class InstallationPaymentOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $cliente;
    private InstallationBillingService $cartera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant  = Tenant::factory()->create();
        $this->cliente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cartera = app(InstallationBillingService::class);
    }

    private function orden(float $recibido): CustomerInstallation
    {
        return CustomerInstallation::create([
            'tenant_id'         => $this->tenant->id,
            'customer_id'       => $this->cliente->id,
            'scheduled_date'    => now()->toDateString(),
            'address'           => 'Calle 1',
            'status'            => 'pendiente',
            'installation_cost' => 100000,
            'payment_received'  => $recibido,
            'payment_method'    => 'Efectivo',
        ]);
    }

    private function guardarCartera(CustomerInstallation $orden, array $cambios = []): Invoice
    {
        if ($cambios !== []) {
            $orden->update($cambios);
        }

        return $this->cartera->upsertInstallationInvoice($orden->refresh(), $this->tenant->id);
    }

    /** Abono registrado en Facturación, como lo hace la pantalla de pagos. */
    private function abonoEnFacturacion(Invoice $factura, float $monto): Payment
    {
        return app(BillingService::class)->registerPayment([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->cliente->id,
            'amount'       => $monto,
            'payment_date' => now()->toDateString(),
            'method'       => 'Efectivo',
            'allocations'  => [['invoice_id' => $factura->id, 'amount' => $monto]],
        ]);
    }

    private function pagos()
    {
        return Payment::withoutGlobalScope('tenant')->where('customer_id', $this->cliente->id);
    }

    private function migracion()
    {
        return require database_path('migrations/2026_10_16_000001_add_installation_id_to_payments_table.php');
    }

    #[Test]
    public function guardar_la_cartera_en_cero_no_borra_un_abono_de_facturacion(): void
    {
        $orden   = $this->orden(0);
        $factura = $this->guardarCartera($orden);
        $abono   = $this->abonoEnFacturacion($factura, 100000);

        // Se vuelve a guardar la cartera, todavía con «Valor recibido» en 0.
        $factura = $this->guardarCartera($orden, ['payment_notes' => 'Se revisó la orden']);

        $this->assertNotNull(Payment::withoutGlobalScope('tenant')->find($abono->id), 'Se borró el abono de Facturación.');
        $this->assertSame(1, PaymentAllocation::where('payment_id', $abono->id)->where('invoice_id', $factura->id)->count());
        $this->assertEquals(0, (float) $factura->balance_due);
        $this->assertSame('paid', $factura->status);
        $this->assertSame([], app(BooksAuditService::class)->run($this->tenant->id));
    }

    #[Test]
    public function un_valor_recibido_nuevo_no_reescribe_el_abono_de_facturacion(): void
    {
        $orden   = $this->orden(0);
        $factura = $this->guardarCartera($orden);
        $abono   = $this->abonoEnFacturacion($factura, 100000);

        $this->guardarCartera($orden, ['payment_received' => 20000]);

        $fila = Payment::withoutGlobalScope('tenant')->find($abono->id);
        $this->assertEquals(100000, (float) $fila->amount, 'La cartera reescribió el monto del abono de Facturación.');
        $this->assertNull($fila->installation_id);
        $this->assertEquals(100000, (float) PaymentAllocation::where('payment_id', $abono->id)->sum('amount'));
    }

    #[Test]
    public function la_cartera_sigue_gestionando_su_propio_pago(): void
    {
        $orden   = $this->orden(40000);
        $factura = $this->guardarCartera($orden);
        $abono   = $this->abonoEnFacturacion($factura, 60000);

        $propio = $this->pagos()->where('installation_id', $orden->id)->sole();
        $this->assertEquals(40000, (float) $propio->amount);

        // Bajar lo recibido edita SU pago, sin crear otro.
        $this->guardarCartera($orden, ['payment_received' => 30000]);
        $this->assertSame(2, $this->pagos()->count());
        $this->assertEquals(30000, (float) $propio->fresh()->amount);

        // En 0 lo quita, y el abono de Facturación queda.
        $factura = $this->guardarCartera($orden, ['payment_received' => 0]);
        $this->assertNull(Payment::withoutGlobalScope('tenant')->find($propio->id));
        $this->assertNotNull(Payment::withoutGlobalScope('tenant')->find($abono->id));
        $this->assertEquals(40000, (float) $factura->balance_due);
        $this->assertSame('partial', $factura->status);
    }

    #[Test]
    public function el_relleno_marca_el_pago_viejo_de_la_cartera_y_no_el_de_facturacion(): void
    {
        $orden   = $this->orden(40000);
        $factura = $this->guardarCartera($orden);
        $abono   = $this->abonoEnFacturacion($factura, 60000);
        $propio  = $this->pagos()->where('installation_id', $orden->id)->sole();

        // Libros de antes del arreglo: ningún pago tiene marca. (No se usa
        // down()/up(): en SQLite reconstruir `payments` borra en cascada sus
        // asignaciones.)
        DB::table('payments')->update(['installation_id' => null]);
        $this->assertSame(1, $this->migracion()->backfill());
        $this->assertSame(0, $this->migracion()->backfill(), 'El relleno no es idempotente.');

        $this->assertSame($orden->id, (int) DB::table('payments')->where('id', $propio->id)->value('installation_id'));
        $this->assertNull(DB::table('payments')->where('id', $abono->id)->value('installation_id'));

        // Volver a guardar no duplica el pago de la cartera.
        $this->guardarCartera($orden);
        $this->assertSame(2, $this->pagos()->count());

        $this->guardarCartera($orden, ['payment_received' => 0]);
        $this->assertNotNull(Payment::withoutGlobalScope('tenant')->find($abono->id));
        $this->assertNull(Payment::withoutGlobalScope('tenant')->find($propio->id));
    }

    #[Test]
    public function el_relleno_no_marca_nada_si_la_orden_no_tiene_valor_recibido(): void
    {
        $orden   = $this->orden(0);
        $factura = $this->guardarCartera($orden);
        $abono   = $this->abonoEnFacturacion($factura, 100000);

        $this->assertSame(0, $this->migracion()->backfill());
        $this->assertNull(DB::table('payments')->where('id', $abono->id)->value('installation_id'));
    }
}
