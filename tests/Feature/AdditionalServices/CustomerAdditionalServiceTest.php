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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Asignación de servicios adicionales a un cliente.
 *
 * Asignar no factura nada: crea la fila que la generación mensual leerá para
 * sumar el adicional dentro de la factura del cliente. Lo que se prueba aquí es
 * el aislamiento entre empresas y entre clientes, y que el historial de cobro
 * no se pueda borrar por accidente.
 */
class CustomerAdditionalServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Role $role;
    private User $staff;
    private User $customer;
    private AdditionalService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->role   = Role::create(['name' => 'Admin', 'permissions' => ['*']]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $this->role->id,
        ]);

        $this->customer = $this->customerOf($this->tenant, 'Juan', 'Pérez');

        Sanctum::actingAs($this->staff);
        $this->service = AdditionalService::create([
            'name'  => 'Alquiler de router extra',
            'price' => 20000,
        ]);
    }

    private function customerOf(Tenant $tenant, string $name, string $lastName): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        CustomerProfile::create([
            'user_id' => $user->id, 'name' => $name, 'last_name' => $lastName, 'status' => true,
        ]);

        return $user;
    }

    private function url(?int $customer = null, ?int $id = null): string
    {
        $base = '/api/billing/customers/' . ($customer ?? $this->customer->id) . '/additional-services';

        return $id ? "{$base}/{$id}" : $base;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'additional_service_id' => $this->service->id,
            'starts_at'             => '2026-08-01',
        ], $overrides);
    }

    // ── Alta ────────────────────────────────────────────────────────────────

    #[Test]
    public function asigna_el_servicio_y_deja_constancia_de_quien_y_cuando(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson($this->url(), $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('quantity', 1)
            ->assertJsonPath('is_active', true)
            // Sin precio propio, hereda el del catálogo.
            ->assertJsonPath('effective_price', 20000)
            ->assertJsonPath('assigned_by', $this->staff->id)
            ->assertJsonPath('service.name', 'Alquiler de router extra');

        $this->assertNotNull(CustomerAdditionalService::first()->assigned_at);
    }

    #[Test]
    public function el_precio_propio_manda_sobre_el_del_catalogo(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson($this->url(), $this->payload(['price' => 15000]))
            ->assertStatus(201)
            ->assertJsonPath('effective_price', 15000);
    }

    #[Test]
    public function no_se_puede_asignar_dos_veces_el_mismo_servicio_activo(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson($this->url(), $this->payload())->assertStatus(201);

        // Dos filas activas cobrarían dos veces sin que se note. Para eso está
        // la cantidad, y el mensaje lo dice.
        $this->postJson($this->url(), $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'cantidad'));
    }

    #[Test]
    public function una_asignacion_dada_de_baja_no_impide_volver_a_asignar(): void
    {
        Sanctum::actingAs($this->staff);

        $id = $this->postJson($this->url(), $this->payload())->json('id');
        $this->putJson($this->url(null, $id), ['is_active' => false])->assertStatus(200);

        $this->postJson($this->url(), $this->payload())->assertStatus(201);
    }

    #[Test]
    public function no_asigna_un_servicio_desactivado(): void
    {
        Sanctum::actingAs($this->staff);

        $this->service->update(['is_active' => false]);

        $this->postJson($this->url(), $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'desactivado'));
    }

    #[Test]
    public function la_fecha_de_fin_no_puede_ser_anterior_al_inicio(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson($this->url(), $this->payload([
            'starts_at' => '2026-08-01',
            'ends_at'   => '2026-07-01',
        ]))->assertStatus(422);
    }

    // ── Aislamiento ─────────────────────────────────────────────────────────

    #[Test]
    public function no_se_puede_asignar_el_servicio_de_otra_empresa(): void
    {
        $otroTenant = Tenant::factory()->create();
        $otroStaff  = User::factory()->create(['tenant_id' => $otroTenant->id, 'role_id' => $this->role->id]);

        Sanctum::actingAs($otroStaff);
        $ajeno = AdditionalService::create(['name' => 'Soporte premium', 'price' => 5000]);

        Sanctum::actingAs($this->staff);
        $this->postJson($this->url(), $this->payload(['additional_service_id' => $ajeno->id]))
            ->assertStatus(422);

        $this->assertSame(0, CustomerAdditionalService::count());
    }

    #[Test]
    public function no_se_puede_tocar_al_cliente_de_otra_empresa(): void
    {
        $otroTenant  = Tenant::factory()->create();
        $otroCliente = $this->customerOf($otroTenant, 'Ana', 'Gómez');

        Sanctum::actingAs($this->staff);

        $this->getJson($this->url($otroCliente->id))->assertStatus(404);
        $this->postJson($this->url($otroCliente->id), $this->payload())->assertStatus(404);
    }

    #[Test]
    public function no_se_puede_editar_la_asignacion_de_otro_cliente_de_la_misma_empresa(): void
    {
        Sanctum::actingAs($this->staff);

        $id    = $this->postJson($this->url(), $this->payload())->json('id');
        $vecino = $this->customerOf($this->tenant, 'Luis', 'Torres');

        // El id de asignación es válido, pero no es de ESE cliente. Sin la
        // comprobación cruzada, la URL de un cliente serviría para editar la
        // asignación de cualquier otro de la misma empresa.
        $this->putJson($this->url($vecino->id, $id), ['quantity' => 9])->assertStatus(404);
        $this->deleteJson($this->url($vecino->id, $id))->assertStatus(404);

        $this->assertSame(1, CustomerAdditionalService::find($id)->quantity);
    }

    // ── Edición y baja ──────────────────────────────────────────────────────

    #[Test]
    public function el_listado_trae_precio_efectivo_y_quien_asigno(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson($this->url(), $this->payload(['quantity' => 2]))->assertStatus(201);

        $this->getJson($this->url())
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.quantity', 2)
            ->assertJsonPath('0.effective_price', 20000)
            ->assertJsonPath('0.assigner.name', $this->staff->name);
    }

    #[Test]
    public function reactivar_actualiza_desde_cuando_la_tiene(): void
    {
        Sanctum::actingAs($this->staff);

        $id = $this->postJson($this->url(), $this->payload())->json('id');

        $this->putJson($this->url(null, $id), ['is_active' => false])->assertStatus(200);
        CustomerAdditionalService::where('id', $id)->update(['assigned_at' => '2020-01-01 00:00:00']);

        $this->putJson($this->url(null, $id), ['is_active' => true])->assertStatus(200);

        $this->assertTrue(
            CustomerAdditionalService::find($id)->assigned_at->isAfter('2021-01-01'),
            'Al reactivar, assigned_at debe volver a decir desde cuándo la tiene esta vez.'
        );
    }

    #[Test]
    public function una_asignacion_que_nunca_facturo_si_se_borra(): void
    {
        Sanctum::actingAs($this->staff);

        $id = $this->postJson($this->url(), $this->payload())->json('id');

        $this->deleteJson($this->url(null, $id))->assertStatus(200);

        $this->assertNull(CustomerAdditionalService::find($id));
    }

    #[Test]
    public function una_asignacion_ya_cobrada_no_se_borra_se_desactiva(): void
    {
        Sanctum::actingAs($this->staff);

        $id = $this->postJson($this->url(), $this->payload())->json('id');

        $invoice = Invoice::create([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->customer->id,
            'number'       => 'INV-TEST-0001',
            'issue_date'   => '2026-08-01',
            'due_date'     => '2026-08-10',
            'period_start' => '2026-08-01',
            'period_end'   => '2026-08-31',
            'total'        => 20000,
            'balance_due'  => 20000,
            'status'       => 'issued',
        ]);
        InvoiceItem::create([
            'invoice_id'                     => $invoice->id,
            'customer_additional_service_id' => $id,
            'type'                           => 'additional_service',
            'description'                    => 'Alquiler de router extra',
            'quantity'                       => 1,
            'unit_price'                     => 20000,
            'amount'                         => 20000,
        ]);

        $this->deleteJson($this->url(null, $id))
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Desactívalo'));

        $this->assertNotNull(CustomerAdditionalService::find($id));

        $this->putJson($this->url(null, $id), ['is_active' => false])
            ->assertStatus(200)
            ->assertJsonPath('is_active', false);
    }
}
