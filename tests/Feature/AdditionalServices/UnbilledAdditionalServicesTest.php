<?php

namespace Tests\Feature\AdditionalServices;

use App\Models\AdditionalService;
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
 * Detector de fuga silenciosa: servicios activos que este mes no se cobraron.
 *
 * Sin esto, una asignación puede quedarse meses "activa" en la ficha —cliente
 * excluido de facturación, retirado, o con el tope de mora alcanzado— sin que
 * nadie note que no se está facturando.
 *
 * Lo importante es que NO grite en falso: un indicador que se equivoca se acaba
 * ignorando, y entonces no sirve de nada. Por eso reutiliza el mismo filtro que
 * el cobro y calla mientras el ciclo del router no haya corrido.
 */
class UnbilledAdditionalServicesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;
    private User $customer;
    private AdditionalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 20, 9, 0, 0));

        $this->tenant = Tenant::factory()->create();
        $role         = Role::create(['name' => 'Admin', 'permissions' => ['*']]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $this->customer->id, 'name' => 'Juan', 'last_name' => 'Pérez', 'status' => true,
        ]);

        $this->service = AdditionalService::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Alquiler de router extra',
            'price'     => 20000,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function assign(array $attributes = []): CustomerAdditionalService
    {
        return CustomerAdditionalService::create(array_merge([
            'tenant_id'             => $this->tenant->id,
            'customer_id'           => $this->customer->id,
            'additional_service_id' => $this->service->id,
            'starts_at'             => '2026-01-01',
            'assigned_at'           => Carbon::now()->subMonths(3),
        ], $attributes));
    }

    private function invoiceDelMes(array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->customer->id,
            'invoice_type' => Invoice::TYPE_MONTHLY,
            'number'       => 'INV-' . uniqid(),
            'issue_date'   => '2026-07-15',
            'due_date'     => '2026-07-20',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'total'        => 50000,
            'balance_due'  => 50000,
            'status'       => 'issued',
        ], $attributes));
    }

    #[Test]
    public function marca_el_servicio_activo_que_no_entro_en_la_factura_del_mes(): void
    {
        Sanctum::actingAs($this->staff);

        $this->assign();
        $this->invoiceDelMes(); // factura del mes SIN el adicional

        $this->getJson('/api/billing/additional-services/unbilled')
            ->assertStatus(200)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('total', 20000)
            ->assertJsonPath('items.0.customer_name', 'Juan Pérez')
            ->assertJsonPath('items.0.service_name', 'Alquiler de router extra');
    }

    #[Test]
    public function no_avisa_si_el_ciclo_todavia_no_ha_corrido(): void
    {
        Sanctum::actingAs($this->staff);

        $this->assign();
        // Sin factura de julio: el router aún no ha facturado. No es una fuga,
        // es que no ha llegado el momento — avisar aquí sería gritar en falso.

        $this->getJson('/api/billing/additional-services/unbilled')
            ->assertStatus(200)
            ->assertJsonPath('count', 0);
    }

    #[Test]
    public function no_avisa_cuando_el_servicio_si_se_cobro(): void
    {
        Sanctum::actingAs($this->staff);

        $assignment = $this->assign();
        $invoice    = $this->invoiceDelMes();

        InvoiceItem::create([
            'invoice_id'                     => $invoice->id,
            'customer_additional_service_id' => $assignment->id,
            'type'                           => 'additional_service',
            'description'                    => 'Alquiler de router extra',
            'quantity'                       => 1,
            'unit_price'                     => 20000,
            'amount'                         => 20000,
        ]);

        $this->getJson('/api/billing/additional-services/unbilled')
            ->assertStatus(200)
            ->assertJsonPath('count', 0);
    }

    #[Test]
    public function no_avisa_por_una_asignacion_dada_de_baja(): void
    {
        Sanctum::actingAs($this->staff);

        $this->assign(['is_active' => false]);
        $this->invoiceDelMes();

        $this->getJson('/api/billing/additional-services/unbilled')
            ->assertStatus(200)
            ->assertJsonPath('count', 0);
    }

    #[Test]
    public function no_avisa_por_una_asignacion_que_arranca_el_mes_que_viene(): void
    {
        Sanctum::actingAs($this->staff);

        $this->assign(['starts_at' => '2026-08-01']);
        $this->invoiceDelMes();

        $this->getJson('/api/billing/additional-services/unbilled')
            ->assertStatus(200)
            ->assertJsonPath('count', 0);
    }

    #[Test]
    public function una_factura_anulada_no_cuenta_como_factura_del_mes(): void
    {
        Sanctum::actingAs($this->staff);

        $this->assign();
        $this->invoiceDelMes(['status' => 'void']);

        // El cliente no tiene factura vigente del mes: el ciclo no llegó a
        // buen puerto, así que no hay nada que reportar como no cobrado.
        $this->getJson('/api/billing/additional-services/unbilled')
            ->assertStatus(200)
            ->assertJsonPath('count', 0);
    }

    #[Test]
    public function el_aviso_no_cruza_de_una_empresa_a_otra(): void
    {
        Sanctum::actingAs($this->staff);
        $this->assign();
        $this->invoiceDelMes();

        $otroTenant = Tenant::factory()->create();
        $otroStaff  = User::factory()->create([
            'tenant_id' => $otroTenant->id,
            'role_id'   => Role::first()->id,
        ]);

        Sanctum::actingAs($otroStaff);
        $this->getJson('/api/billing/additional-services/unbilled')
            ->assertStatus(200)
            ->assertJsonPath('count', 0);
    }

    #[Test]
    public function la_ficha_del_cliente_marca_la_asignacion_sin_cobrar(): void
    {
        Sanctum::actingAs($this->staff);

        $this->assign();
        $this->invoiceDelMes();

        $this->getJson("/api/billing/customers/{$this->customer->id}/additional-services")
            ->assertStatus(200)
            ->assertJsonPath('0.pending_billing', true);
    }

    #[Test]
    public function la_ficha_no_marca_nada_si_el_ciclo_no_ha_corrido(): void
    {
        Sanctum::actingAs($this->staff);

        $this->assign();

        $this->getJson("/api/billing/customers/{$this->customer->id}/additional-services")
            ->assertStatus(200)
            ->assertJsonPath('0.pending_billing', false);
    }
}
