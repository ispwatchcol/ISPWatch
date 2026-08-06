<?php

namespace Tests\Feature\AdditionalServices;

use App\Models\AdditionalService;
use App\Models\Billing;
use App\Models\CustomerAdditionalService;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CRUD del catálogo de servicios adicionales.
 *
 * Va bajo el permiso `view_billing`, igual que formas de pago y tipos de
 * factura: un permiso propio dejaría a los roles admin existentes sin la
 * pestaña hasta re-sembrarlos.
 */
class AdditionalServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;
    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $this->role->id,
        ]);
    }

    /**
     * Staff de OTRA empresa, con los mismos permisos.
     *
     * El rol importa: sin él el middleware devuelve 403 y el test "pasaría" por
     * falta de permisos en vez de por el aislamiento entre tenants, que es lo
     * que se quiere comprobar.
     */
    private function staffDeOtroTenant(): User
    {
        return User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'role_id'   => $this->role->id,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'  => 'Alquiler de router extra',
            'price' => 20000,
        ], $overrides);
    }

    #[Test]
    public function crea_un_servicio_con_los_defaults_del_negocio(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson('/api/billing/additional-services', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('name', 'Alquiler de router extra')
            ->assertJsonPath('charge_on_courtesy_month', true)
            ->assertJsonPath('proration_mode', Billing::FIRST_INVOICE_FULL)
            ->assertJsonPath('is_active', true);

        $this->assertSame($this->tenant->id, AdditionalService::first()->tenant_id);
    }

    #[Test]
    public function acepta_los_tres_modos_de_prorrateo_y_rechaza_cualquier_otro(): void
    {
        Sanctum::actingAs($this->staff);

        foreach (Billing::FIRST_INVOICE_MODES as $i => $mode) {
            $this->postJson('/api/billing/additional-services', $this->payload([
                'name'           => "Servicio {$i}",
                'proration_mode' => $mode,
            ]))->assertStatus(201)->assertJsonPath('proration_mode', $mode);
        }

        $this->postJson('/api/billing/additional-services', $this->payload([
            'name'           => 'Servicio raro',
            'proration_mode' => 'quincenal',
        ]))->assertStatus(422);
    }

    #[Test]
    public function no_admite_dos_servicios_con_el_mismo_nombre_aunque_cambie_la_capitalizacion(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson('/api/billing/additional-services', $this->payload())->assertStatus(201);

        // Dos "Alquiler de router extra" en el desplegable de asignación son
        // indistinguibles para quien asigna.
        $this->postJson('/api/billing/additional-services', $this->payload([
            'name' => 'ALQUILER DE ROUTER EXTRA',
        ]))->assertStatus(422);
    }

    #[Test]
    public function el_nombre_repetido_de_otro_tenant_no_estorba(): void
    {
        Sanctum::actingAs($this->staffDeOtroTenant());
        $this->postJson('/api/billing/additional-services', $this->payload())->assertStatus(201);

        Sanctum::actingAs($this->staff);
        $this->postJson('/api/billing/additional-services', $this->payload())->assertStatus(201);
    }

    #[Test]
    public function el_listado_solo_muestra_los_del_tenant_y_cuenta_sus_asignaciones(): void
    {
        Sanctum::actingAs($this->staff);
        $mio = AdditionalService::create($this->payload());

        $cliente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $cliente->id, 'name' => 'Ana', 'last_name' => 'Ruiz', 'status' => true,
        ]);
        CustomerAdditionalService::create([
            'customer_id'           => $cliente->id,
            'additional_service_id' => $mio->id,
            'starts_at'             => '2026-07-01',
        ]);

        Sanctum::actingAs($this->staffDeOtroTenant());
        AdditionalService::create($this->payload(['name' => 'Soporte premium']));

        Sanctum::actingAs($this->staff);
        $this->getJson('/api/billing/additional-services')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Alquiler de router extra')
            ->assertJsonPath('0.active_assignments_count', 1);
    }

    #[Test]
    public function no_se_puede_editar_el_servicio_de_otro_tenant(): void
    {
        Sanctum::actingAs($this->staffDeOtroTenant());
        $ajeno = AdditionalService::create($this->payload());

        Sanctum::actingAs($this->staff);

        $this->putJson("/api/billing/additional-services/{$ajeno->id}", ['price' => 1])
            ->assertStatus(404);

        $this->deleteJson("/api/billing/additional-services/{$ajeno->id}")
            ->assertStatus(404);

        $this->assertSame('20000.00', $ajeno->fresh()->price);
    }

    #[Test]
    public function actualiza_solo_lo_que_viene_en_el_payload(): void
    {
        Sanctum::actingAs($this->staff);

        $service = AdditionalService::create($this->payload([
            'charge_on_courtesy_month' => false,
            'proration_mode'           => Billing::FIRST_INVOICE_PRORATED,
        ]));

        $this->putJson("/api/billing/additional-services/{$service->id}", ['price' => 25000])
            ->assertStatus(200)
            ->assertJsonPath('price', '25000.00')
            // Lo que no viajó en el payload no se toca: un PUT parcial no puede
            // resucitar los defaults y cambiarle el cobro a quien ya lo tiene.
            ->assertJsonPath('charge_on_courtesy_month', false)
            ->assertJsonPath('proration_mode', Billing::FIRST_INVOICE_PRORATED);
    }

    #[Test]
    public function un_servicio_sin_asignaciones_si_se_borra(): void
    {
        Sanctum::actingAs($this->staff);

        $service = AdditionalService::create($this->payload());

        $this->deleteJson("/api/billing/additional-services/{$service->id}")
            ->assertStatus(200);

        $this->assertNull(AdditionalService::find($service->id));
    }

    #[Test]
    public function un_servicio_asignado_no_se_borra_se_desactiva(): void
    {
        Sanctum::actingAs($this->staff);

        $service = AdditionalService::create($this->payload());

        $cliente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerAdditionalService::create([
            'customer_id'           => $cliente->id,
            'additional_service_id' => $service->id,
            'starts_at'             => '2026-07-01',
            // Incluso dada de baja: la factura vieja sigue apuntando aquí.
            'is_active'             => false,
        ]);

        $this->deleteJson("/api/billing/additional-services/{$service->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Desactívalo'));

        $this->assertNotNull(AdditionalService::find($service->id));

        // Y desactivarlo sí funciona: es la salida que ofrece el mensaje.
        $this->putJson("/api/billing/additional-services/{$service->id}", ['is_active' => false])
            ->assertStatus(200)
            ->assertJsonPath('is_active', false);
    }

    #[Test]
    public function exige_autenticacion(): void
    {
        $this->getJson('/api/billing/additional-services')->assertStatus(401);
        $this->postJson('/api/billing/additional-services', $this->payload())->assertStatus(401);
    }
}
