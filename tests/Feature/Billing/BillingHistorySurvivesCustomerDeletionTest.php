<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerAdditionalService;
use App\Models\CustomerCredit;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceCarryover;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P-43 · El histórico de facturación sobrevive al borrado del cliente.
 *
 * QUÉ SE COMPRUEBA AQUÍ Y QUÉ EN LA OTRA SUITE
 *
 * `CustomerDeletionControlsTest` cubre el borrado como operación: quién puede
 * hacerlo, con qué motivo y qué queda auditado. Esta suite cubre lo contrario:
 * qué NO se destruye y en qué estado queda.
 *
 * POR QUÉ IMPORTA QUE ESTAS PRUEBAS CORRAN DE VERDAD EN SQLITE
 *
 * Porque SQLite aplica claves foráneas cuando `foreign_key_constraints` está en
 * `true`, y lo está. Un `SET NULL` mal escrito se nota aquí, no sólo en el job
 * de PostgreSQL del CI. Lo que sí queda para ese job es la forma exacta de la
 * constraint en el motor real.
 */
class BillingHistorySurvivesCustomerDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        // Mismo motivo que en CustomerDeletionControlsTest: `role_id == 1` pasa
        // cualquier permiso, así que se quema el primer id.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);
    }

    private function cliente(string $nombre = 'Axel', string $apellido = 'Cano'): User
    {
        $user = User::factory()->create([
            'tenant_id'     => $this->tenant->id,
            'user_name'     => $nombre,
            'user_lastname' => $apellido,
        ]);

        CustomerProfile::create([
            'user_id' => $user->id, 'name' => $nombre, 'last_name' => $apellido,
            'cedula' => '1234567890', 'status' => true,
        ]);

        return $user;
    }

    private function factura(User $cliente, float $total = 50000): Invoice
    {
        return Invoice::create([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $cliente->id,
            'invoice_type' => Invoice::TYPE_MONTHLY,
            'issue_date'   => now()->toDateString(),
            'due_date'     => now()->addDays(15)->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end'   => now()->endOfMonth()->toDateString(),
            'subtotal'     => $total,
            'total'        => $total,
            'balance_due'  => $total,
            'status'       => 'issued',
        ]);
    }

    /**
     * Borra al cliente por el mismo camino que la aplicación.
     *
     * Se llama al servicio y no al endpoint porque lo que se prueba aquí es el
     * destino de los datos, no el control de acceso — de eso se ocupa la otra
     * suite. Así estas pruebas no se rompen si mañana cambia el permiso.
     */
    private function borrar(User $cliente): void
    {
        $servicio = app(\App\Services\CustomerDeletionService::class);
        $perfil   = CustomerProfile::withoutGlobalScopes()->where('user_id', $cliente->id)->firstOrFail();

        $servicio->delete($cliente, $perfil);
    }

    // ── El snapshot ──────────────────────────────────────────────────────

    #[Test]
    public function el_titular_se_congela_al_crear_la_factura(): void
    {
        $factura = $this->factura($this->cliente());

        $this->assertSame('Axel Cano', $factura->customer_name);
        $this->assertSame('1234567890', $factura->customer_document);
    }

    #[Test]
    public function el_titular_congelado_no_se_reescribe_si_el_cliente_cambia_de_nombre(): void
    {
        // Una factura emitida dice a quién se le facturó ENTONCES. Corregir un
        // apellido mal escrito no reescribe el papel que ya se envió.
        $cliente = $this->cliente();
        $factura = $this->factura($cliente);

        $cliente->update(['user_name' => 'Axel Mauricio', 'user_lastname' => 'Cano Rojas']);
        $factura->update(['notes' => 'Se corrigió el nombre del titular.']);

        $this->assertSame('Axel Cano', $factura->fresh()->customer_name);
    }

    #[Test]
    public function el_pago_tambien_congela_a_su_titular(): void
    {
        $pago = Payment::create([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->cliente()->id,
            'amount'       => 50000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
            'status'       => 'completed',
        ]);

        $this->assertSame('Axel Cano', $pago->customer_name);
        $this->assertSame('1234567890', $pago->customer_document);
    }

    // ── Lo que sobrevive ─────────────────────────────────────────────────

    #[Test]
    public function factura_pago_y_su_detalle_sobreviven_desvinculados(): void
    {
        $cliente = $this->cliente();
        $factura = $this->factura($cliente);

        $item = InvoiceItem::create([
            'invoice_id'  => $factura->id,
            'type'        => 'plan',
            'description' => 'Servicio mensual: Plan 10 megas',
            'quantity'    => 1,
            'unit_price'  => 50000,
            'amount'      => 50000,
        ]);

        $pago = Payment::create([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $cliente->id,
            'amount'       => 50000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
            'status'       => 'completed',
        ]);

        $asignacion = PaymentAllocation::create([
            'payment_id' => $pago->id,
            'invoice_id' => $factura->id,
            'amount'     => 50000,
        ]);

        $this->borrar($cliente);

        $this->assertDatabaseMissing('users', ['id' => $cliente->id]);

        // La factura y el pago siguen ahí, sin titular pero con nombre.
        foreach ([['invoices', $factura->id], ['payments', $pago->id]] as [$tabla, $id]) {
            $fila = DB::table($tabla)->where('id', $id)->first();

            $this->assertNotNull($fila, "Una fila de {$tabla} no debería desaparecer con su titular.");
            $this->assertNull($fila->customer_id, "La fila de {$tabla} debe quedar desvinculada.");
            $this->assertSame('Axel Cano', $fila->customer_name);
            $this->assertSame('1234567890', $fila->customer_document);
        }

        // El detalle cuelga de la factura y del pago, no del cliente: sobrevive
        // porque sobreviven ellos. Es lo que dice qué se cobró y qué saldó qué.
        $this->assertDatabaseHas('invoice_items', ['id' => $item->id, 'amount' => 50000]);
        $this->assertDatabaseHas('payment_allocations', ['id' => $asignacion->id, 'amount' => 50000]);
    }

    #[Test]
    public function el_arrastre_pendiente_y_el_saldo_a_favor_tambien_sobreviven(): void
    {
        $cliente = $this->cliente();
        $factura = $this->factura($cliente);

        $arrastre = InvoiceCarryover::create([
            'tenant_id'       => $this->tenant->id,
            'customer_id'     => $cliente->id,
            'from_invoice_id' => $factura->id,
            'amount'          => 12000,
            'status'          => InvoiceCarryover::STATUS_PENDING,
        ]);

        $credito = CustomerCredit::create([
            'tenant_id'     => $this->tenant->id,
            'customer_id'   => $cliente->id,
            'type'          => CustomerCredit::TYPE_EARNED,
            'amount'        => 8000,
            'balance_after' => 8000,
        ]);

        $this->borrar($cliente);

        // Un arrastre pendiente de un cliente que ya no está NO se marca como
        // aplicado ni se borra: se queda como constancia de que quedó sin
        // cobrar. Marcarlo aplicado sería mentir sobre dinero que nunca entró.
        $this->assertDatabaseHas('invoice_carryovers', [
            'id' => $arrastre->id, 'customer_id' => null, 'status' => InvoiceCarryover::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('customer_credits', [
            'id' => $credito->id, 'customer_id' => null, 'amount' => 8000,
        ]);
    }

    // ── Lo que no puede quedar suelto ────────────────────────────────────

    #[Test]
    public function el_servicio_adicional_sobrevive_pero_desactivado(): void
    {
        // Sobrevive porque justifica cargos ya emitidos. Se desactiva porque
        // `BillingService::unbilledAdditionalServices()` recorre los activos y
        // convierte su `customer_id` en `(int) null`: un servicio sin dueño
        // seguiría generando cargos, atribuidos al cliente 0.
        $cliente = $this->cliente();

        $servicio = \App\Models\AdditionalService::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'IP fija',
            'price'     => 15000,
            'is_active' => true,
        ]);

        $asignado = CustomerAdditionalService::create([
            'tenant_id'             => $this->tenant->id,
            'customer_id'           => $cliente->id,
            'additional_service_id' => $servicio->id,
            'price'                 => 15000,
            'is_active'             => true,
            'starts_at'             => now()->toDateString(),
        ]);

        $this->borrar($cliente);

        $fila = DB::table('customer_additional_services')->where('id', $asignado->id)->first();

        $this->assertNotNull($fila);
        $this->assertNull($fila->customer_id);
        $this->assertFalse((bool) $fila->is_active, 'Un servicio sin titular no puede seguir activo.');
    }

    // ── La red de seguridad del motor ────────────────────────────────────

    #[Test]
    public function un_delete_a_mano_tampoco_se_lleva_la_factura(): void
    {
        // El servicio hace lo correcto, pero no cubre un `DELETE` escrito en
        // una consola de base de datos ni un `User::where(...)->delete()`, que
        // no pasa por él. Para eso está la clave foránea en `SET NULL`: la
        // aplicación hace lo correcto, la base de datos impide lo incorrecto.
        //
        // Es el mismo reparto que el PR A dejó escrito para los catálogos.
        $cliente = $this->cliente();
        $factura = $this->factura($cliente);

        $this->assertSame('Axel Cano', $factura->customer_name);

        DB::table('users')->where('id', $cliente->id)->delete();

        $fila = DB::table('invoices')->where('id', $factura->id)->first();

        $this->assertNotNull($fila, 'La cascada volvería a destruir el histórico.');
        $this->assertNull($fila->customer_id);

        // El snapshot se congeló al crear, así que sigue ahí aunque este camino
        // no pase por `preservarContabilidad()`.
        $this->assertSame('Axel Cano', $fila->customer_name);
    }

    #[Test]
    public function un_arrastre_sin_titular_no_se_le_cobra_a_otro_cliente(): void
    {
        // `applyPendingCarryoversTo()` busca por `customer_id`. En SQL,
        // `customer_id = NULL` nunca es cierto, así que un arrastre huérfano no
        // puede colarse en la factura de nadie. Se fija por prueba porque el día
        // que alguien cambie esa consulta por un `whereNull`-tolerante, el
        // dinero de un cliente borrado aparecería cobrado a otro.
        $borrado = $this->cliente();
        $otro    = $this->cliente('Laura', 'Martinez');

        InvoiceCarryover::create([
            'tenant_id'   => $this->tenant->id,
            'customer_id' => $borrado->id,
            'amount'      => 12000,
            'status'      => InvoiceCarryover::STATUS_PENDING,
        ]);

        $this->borrar($borrado);

        $pendiente = InvoiceCarryover::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('customer_id', $otro->id)
            ->sum('amount');

        $this->assertEquals(0, $pendiente, 'El arrastre huérfano no es de nadie más.');
    }
}
