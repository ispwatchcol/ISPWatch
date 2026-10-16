<?php

namespace Tests\Feature\Billing;

use App\Constants\Permissions;
use App\Models\CustomerCredit;
use App\Models\CustomerInstallation;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use App\Services\BooksAuditService;
use App\Services\InstallationBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
    public function con_la_factura_saldada_en_facturacion_la_cartera_rechaza_un_valor_recibido(): void
    {
        $orden   = $this->orden(0);
        $factura = $this->guardarCartera($orden);
        $abono   = $this->abonoEnFacturacion($factura, 100000);

        // P-103: antes se asignaba igual y la factura quedaba con 120.000
        // asignados sobre un total de 100.000. Antes de KAN-124, además, se
        // reescribía el abono de Facturación.
        try {
            DB::transaction(fn () => $this->guardarCartera($orden, ['payment_received' => 20000]));
            $this->fail('La cartera aceptó registrar dinero sobre una factura ya saldada en Facturación.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_received', $e->errors());
            $this->assertStringContainsString('$0', $e->errors()['payment_received'][0]);
        }

        $fila = Payment::withoutGlobalScope('tenant')->find($abono->id);
        $this->assertEquals(100000, (float) $fila->amount);
        $this->assertNull($fila->installation_id);
        $this->assertSame(1, $this->pagos()->count(), 'Quedó un pago de cartera a medias.');
        $this->assertEquals(100000, (float) PaymentAllocation::where('invoice_id', $factura->id)->sum('amount'));
        $this->assertSame([], app(BooksAuditService::class)->run($this->tenant->id));
    }

    #[Test]
    public function con_un_abono_parcial_la_cartera_solo_cubre_lo_que_falta(): void
    {
        $orden   = $this->orden(40000);
        $factura = $this->guardarCartera($orden);
        $this->abonoEnFacturacion($factura, 30000);

        // Facturación cerró la factura: los 30.000 que faltaban viajan a la
        // mensualidad. Lo recibido por la cartera ya no puede subir…
        try {
            $this->guardarCartera($orden, ['payment_received' => 50000]);
            $this->fail('La cartera cubrió algo que ya viajó a la mensualidad.');
        } catch (ValidationException) {
        }

        $propio = $this->pagos()->where('installation_id', $orden->id)->sole();
        $this->assertEquals(40000, (float) $propio->amount);

        // …pero volver a guardar lo mismo sí se puede.
        $factura = $this->guardarCartera($orden, ['payment_received' => 40000]);
        $this->assertEquals(0, (float) $factura->balance_due);
        $this->assertEquals(40000, (float) PaymentAllocation::where('payment_id', $propio->id)->sum('amount'));
        $this->assertSame([], app(BooksAuditService::class)->run($this->tenant->id));
    }

    #[Test]
    public function por_la_api_el_rechazo_es_un_422_y_la_orden_no_queda_a_medias(): void
    {
        // `CheckPermission` deja pasar SIEMPRE a `role_id == 1`: se quema.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);
        $rol = Role::create([
            'name' => 'Cartera', 'code' => 'staff',
            'permissions' => [Permissions::VIEW_SUPPORT, Permissions::VIEW_CLIENTS, Permissions::ADD_CLIENTS, Permissions::EDIT_DISCOUNT],
            'tenant_id' => $this->tenant->id,
        ]);
        $asesor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rol->id]);

        $orden   = $this->orden(0);
        $factura = $this->guardarCartera($orden);
        $this->abonoEnFacturacion($factura, 100000);

        $this->actingAs($asesor)
            ->putJson("/api/installations/{$orden->id}/billing", [
                'payment_received' => 20000,
                'payment_notes'    => 'Pagó en efectivo al técnico',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_received');

        $orden->refresh();
        $this->assertEquals(0, (float) $orden->payment_received, 'La orden guardó el valor rechazado.');
        $this->assertNull($orden->payment_notes);
        $this->assertSame(1, $this->pagos()->count());
    }

    #[Test]
    public function sin_abonos_de_facturacion_el_cobro_de_mas_sigue_siendo_saldo_a_favor(): void
    {
        $orden = $this->orden(120000);
        $this->guardarCartera($orden);

        $propio = $this->pagos()->where('installation_id', $orden->id)->sole();
        $this->assertEquals(100000, (float) PaymentAllocation::where('payment_id', $propio->id)->sum('amount'));
        $this->assertEquals(20000, (float) CustomerCredit::where('from_payment_id', $propio->id)
            ->where('type', CustomerCredit::TYPE_EARNED)->sum('amount'));
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
