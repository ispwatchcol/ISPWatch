<?php

namespace Tests\Feature\AdditionalServices;

use App\Models\AdditionalService;
use App\Models\Billing;
use App\Models\CustomerAdditionalService;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Servicios adicionales reutilizables — esquema y reglas de modelo.
 *
 * Hasta ahora un "servicio adicional" no era una entidad: la pantalla emitía una
 * factura suelta con ítems escritos a mano. Estas dos tablas lo convierten en un
 * catálogo (la plantilla) más asignaciones por cliente (quién lo tiene, desde
 * cuándo y a qué precio).
 *
 * Esta fase NO toca la facturación: sólo verifica que los defaults codifican la
 * decisión de negocio correcta, que el precio efectivo resuelve la cascada
 * asignación → catálogo, y que la ventana de vigencia responde bien en los
 * bordes —que es de lo que dependerá el cobro mensual de la fase siguiente.
 */
class AdditionalServicesSchemaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id'   => $this->customer->id,
            'name'      => 'Juan',
            'last_name' => 'Pérez',
            'status'    => true,
        ]);
    }

    private function service(array $attributes = []): AdditionalService
    {
        return AdditionalService::create(array_merge([
            'name'  => 'Alquiler de router extra',
            'price' => 20000,
        ], $attributes));
    }

    private function assignment(AdditionalService $service, array $attributes = []): CustomerAdditionalService
    {
        return CustomerAdditionalService::create(array_merge([
            'customer_id'           => $this->customer->id,
            'additional_service_id' => $service->id,
            'starts_at'             => '2026-07-01',
            'assigned_at'           => now(),
            'assigned_by'           => $this->staff->id,
        ], $attributes));
    }

    // ── Catálogo ────────────────────────────────────────────────────────────

    #[Test]
    public function el_servicio_nace_con_los_defaults_que_acordamos(): void
    {
        Sanctum::actingAs($this->staff);

        $service = $this->service()->refresh();

        // Cobra en mes de cortesía: la promoción que se vendió fue "internet
        // gratis", no "equipos gratis".
        $this->assertTrue($service->charge_on_courtesy_month);

        // 'full' y no 'none' como los planes: un adicional suele ser algo ya
        // entregado, y es el único modo cuyo monto se predice sin hacer cuentas.
        $this->assertSame(Billing::FIRST_INVOICE_FULL, $service->proration_mode);

        $this->assertTrue($service->is_active);
    }

    #[Test]
    public function el_vocabulario_de_prorrateo_es_el_mismo_que_el_de_los_planes(): void
    {
        // Si algún día se agrega un modo a la política de primera factura, los
        // servicios adicionales lo heredan sin tocar nada. Que sean listas
        // distintas es justo lo que este test impide.
        $this->assertSame(Billing::FIRST_INVOICE_MODES, AdditionalService::PRORATION_MODES);
    }

    #[Test]
    public function el_tenant_lo_pone_el_usuario_autenticado(): void
    {
        Sanctum::actingAs($this->staff);

        $this->assertSame($this->tenant->id, $this->service()->tenant_id);
    }

    #[Test]
    public function un_tenant_no_ve_el_catalogo_de_otro(): void
    {
        Sanctum::actingAs($this->staff);
        $this->service(['name' => 'Alquiler de router extra']);

        $otroTenant = Tenant::factory()->create();
        $otroStaff  = User::factory()->create(['tenant_id' => $otroTenant->id]);

        Sanctum::actingAs($otroStaff);
        AdditionalService::create(['name' => 'Soporte premium', 'price' => 5000]);

        $this->assertSame(['Soporte premium'], AdditionalService::pluck('name')->all());

        Sanctum::actingAs($this->staff);
        $this->assertSame(['Alquiler de router extra'], AdditionalService::pluck('name')->all());
    }

    #[Test]
    public function el_catalogo_cuenta_solo_las_asignaciones_vigentes(): void
    {
        Sanctum::actingAs($this->staff);

        $service = $this->service();
        $this->assignment($service);

        $otroCliente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->assignment($service, ['customer_id' => $otroCliente->id, 'is_active' => false]);

        $conteo = AdditionalService::withCount('activeAssignments')->find($service->id);

        $this->assertSame(1, $conteo->active_assignments_count);
    }

    // ── Precio efectivo ─────────────────────────────────────────────────────

    #[Test]
    public function sin_precio_propio_la_asignacion_sigue_al_catalogo(): void
    {
        Sanctum::actingAs($this->staff);

        $service    = $this->service(['price' => 20000]);
        $assignment = $this->assignment($service, ['price' => null]);

        $this->assertSame(20000.0, $assignment->load('service')->effective_price);

        // Y sigue sus cambios: es la mitad del sentido de dejarlo en null.
        $service->update(['price' => 25000]);

        $this->assertSame(25000.0, $assignment->fresh()->load('service')->effective_price);
    }

    #[Test]
    public function el_precio_propio_queda_congelado_aunque_cambie_el_catalogo(): void
    {
        Sanctum::actingAs($this->staff);

        $service    = $this->service(['price' => 20000]);
        $assignment = $this->assignment($service, ['price' => 15000]);

        $service->update(['price' => 25000]);

        $this->assertSame(15000.0, $assignment->fresh()->load('service')->effective_price);
    }

    #[Test]
    public function la_asignacion_recien_creada_ya_conoce_sus_defaults(): void
    {
        Sanctum::actingAs($this->staff);

        // Sin releerla de la base. El cobro mensual trabaja con el objeto que
        // tiene en la mano: si quantity llegara en null, multiplicar dejaría el
        // cargo en cero y nadie se enteraría.
        $assignment = $this->assignment($this->service());

        $this->assertTrue($assignment->is_active);
        $this->assertSame(1, $assignment->quantity);
    }

    // ── Ventana de vigencia ─────────────────────────────────────────────────

    #[Test]
    public function la_ventana_decide_si_la_asignacion_entra_en_el_periodo(): void
    {
        Sanctum::actingAs($this->staff);

        $service     = $this->service();
        $periodStart = Carbon::parse('2026-07-01');
        $periodEnd   = Carbon::parse('2026-07-31');

        // Vigente desde antes: entra.
        $this->assertTrue(
            $this->assignment($service, ['starts_at' => '2026-05-10'])->coversPeriod($periodStart, $periodEnd)
        );

        // Arranca el último día del periodo: todavía entra.
        $this->assertTrue(
            $this->assignment($service, ['starts_at' => '2026-07-31'])->coversPeriod($periodStart, $periodEnd)
        );

        // Arranca el mes siguiente: no.
        $this->assertFalse(
            $this->assignment($service, ['starts_at' => '2026-08-01'])->coversPeriod($periodStart, $periodEnd)
        );

        // Terminó antes de que el periodo empezara: no.
        $this->assertFalse(
            $this->assignment($service, ['starts_at' => '2026-01-01', 'ends_at' => '2026-06-30'])
                ->coversPeriod($periodStart, $periodEnd)
        );

        // Termina dentro del periodo: sí se cobra este mes.
        $this->assertTrue(
            $this->assignment($service, ['starts_at' => '2026-01-01', 'ends_at' => '2026-07-15'])
                ->coversPeriod($periodStart, $periodEnd)
        );

        // Desactivada a mano: no, sin importar las fechas.
        $this->assertFalse(
            $this->assignment($service, ['starts_at' => '2026-01-01', 'is_active' => false])
                ->coversPeriod($periodStart, $periodEnd)
        );
    }

    #[Test]
    public function arrancar_el_primero_del_mes_no_cuenta_como_alta_a_mitad_de_periodo(): void
    {
        Sanctum::actingAs($this->staff);

        $service     = $this->service();
        $periodStart = Carbon::parse('2026-07-01');
        $periodEnd   = Carbon::parse('2026-07-31');

        // Es un mes completo normal: el prorrateo no tiene nada que decidir.
        $this->assertFalse(
            $this->assignment($service, ['starts_at' => '2026-07-01'])->startsInsidePeriod($periodStart, $periodEnd)
        );

        $this->assertTrue(
            $this->assignment($service, ['starts_at' => '2026-07-20'])->startsInsidePeriod($periodStart, $periodEnd)
        );

        $this->assertFalse(
            $this->assignment($service, ['starts_at' => '2026-05-20'])->startsInsidePeriod($periodStart, $periodEnd)
        );
    }

    // ── Traza en la factura ─────────────────────────────────────────────────

    #[Test]
    public function el_item_de_factura_recuerda_de_que_asignacion_salio(): void
    {
        Sanctum::actingAs($this->staff);

        $service    = $this->service();
        $assignment = $this->assignment($service);

        $invoice = Invoice::create([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->customer->id,
            'number'       => 'INV-TEST-0001',
            'issue_date'   => '2026-07-01',
            'due_date'     => '2026-07-10',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'total'        => 20000,
            'balance_due'  => 20000,
            'status'       => 'issued',
        ]);

        $item = InvoiceItem::create([
            'invoice_id'                     => $invoice->id,
            'customer_additional_service_id' => $assignment->id,
            'type'                           => 'additional_service',
            'description'                    => 'Alquiler de router extra',
            'quantity'                       => 1,
            'unit_price'                     => 20000,
            'amount'                         => 20000,
        ]);

        // Ida y vuelta: la factura sabe de dónde salió el cobro y la asignación
        // sabe en qué facturas se cobró. De esa segunda dirección saldrá la
        // idempotencia del cobro mensual en la fase siguiente.
        $this->assertSame($assignment->id, $item->additionalService->id);
        $this->assertSame($item->id, $assignment->invoiceItems()->first()->id);
    }

    #[Test]
    public function los_items_de_siempre_no_arrastran_asignacion(): void
    {
        Sanctum::actingAs($this->staff);

        $invoice = Invoice::create([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->customer->id,
            'number'       => 'INV-TEST-0002',
            'issue_date'   => '2026-07-01',
            'due_date'     => '2026-07-10',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'total'        => 50000,
            'balance_due'  => 50000,
            'status'       => 'issued',
        ]);

        $item = InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'type'        => 'plan',
            'description' => 'Servicio mensual: Plan 10 Megas',
            'quantity'    => 1,
            'unit_price'  => 50000,
            'amount'      => 50000,
        ]);

        $this->assertNull($item->customer_additional_service_id);
        $this->assertNull($item->additionalService);
    }
}
