<?php

namespace Tests\Feature\Inventory;

use App\Constants\Permissions as P;
use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «El técnico sólo debe poder ver los equipos que están asignados a él cuando
 * los vaya a usar para tickets, instalaciones o ver el inventario» (2026-11-26,
 * bitácora § 138).
 *
 * Antes, en la orden o el ticket de OTRO técnico, un técnico veía y podía
 * descargar los equipos de ese otro (la excepción del técnico asignado era para
 * todos), y con «Ver inventario» veía además todas las bodegas. Ahora:
 *  - el técnico ve y usa sólo lo suyo, en la orden y en el ticket;
 *  - la oficina (quien administra inventario) conserva la captura de lo del
 *    técnico asignado y las bodegas;
 *  - «Mis equipos» muestra lo propio sin precios, y la lista completa de
 *    equipos ya no se abre con `view_support`.
 */
class TechnicianSeesOnlyOwnEquipmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $tecnicoA;
    private User $tecnicoB;
    private User $oficina;
    private User $cliente;
    private InventoryBranch $bodega;
    private InventoryStock $router;
    private InventoryStock $cable;
    private InventoryDevice $deA;
    private InventoryDevice $deB;
    private InventoryDevice $deBodega;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        // `role_id == 1` pasa por el bypass: se quema ese id.
        Role::create(['name' => 'Superadmin global', 'code' => 'superadmin', 'permissions' => ['*'], 'tenant_id' => null]);

        $tecnico = Role::create([
            'name' => 'Técnico', 'code' => 'technician', 'tenant_id' => $this->tenant->id,
            'permissions' => [P::VIEW_SUPPORT, P::TICKET_VIEW, P::TICKET_EQUIPMENT, P::TICKET_INTERVENE, P::VIEW_OWN_INVENTORY],
        ]);
        $staff = Role::create([
            'name' => 'Staff', 'code' => 'staff', 'tenant_id' => $this->tenant->id,
            'permissions' => [P::VIEW_SUPPORT, P::VIEW_INVENTORY, P::TICKET_VIEW, P::TICKET_EQUIPMENT],
        ]);

        $this->tecnicoA = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $tecnico->id]);
        $this->tecnicoB = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $tecnico->id]);
        $this->oficina  = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $staff->id]);

        $this->cliente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create(['user_id' => $this->cliente->id, 'name' => 'Ana', 'last_name' => 'Ruiz', 'status' => true]);

        Sanctum::actingAs($this->oficina);

        $this->bodega = InventoryBranch::create(['name' => 'CHAGUANI']);
        $this->router = InventoryStock::create(['brand' => 'RUIJIE', 'model' => 'RG-EW300 PRO', 'price' => 90000, 'is_serialized' => true]);
        $this->cable  = InventoryStock::create(['brand' => 'GENERICO', 'model' => 'UTP', 'price' => 1200, 'is_serialized' => false, 'unit' => 'metro']);

        $this->deA      = InventoryDevice::create(['stock_id' => $this->router->id, 'serial' => 'SN-A', 'status' => 'assigned', 'user_id' => $this->tecnicoA->id]);
        $this->deB      = InventoryDevice::create(['stock_id' => $this->router->id, 'serial' => 'SN-B', 'status' => 'assigned', 'user_id' => $this->tecnicoB->id]);
        $this->deBodega = InventoryDevice::create(['stock_id' => $this->router->id, 'serial' => 'SN-BODEGA', 'status' => 'stock', 'branch_id' => $this->bodega->id]);

        foreach ([['user', $this->tecnicoA->id, 20], ['user', $this->tecnicoB->id, 30], ['branch', $this->bodega->id, 500]] as [$tipo, $id, $cantidad]) {
            InventoryBalance::create(['stock_id' => $this->cable->id, 'holder_type' => $tipo, 'holder_id' => $id, 'quantity' => $cantidad]);
        }
    }

    /** Orden asignada al técnico B. */
    private function ordenDeB(): CustomerInstallation
    {
        return CustomerInstallation::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id,
            'technician_id' => $this->tecnicoB->id, 'scheduled_date' => now()->toDateString(), 'status' => 'pendiente',
        ]);
    }

    /** Ticket asignado al técnico B. */
    private function ticketDeB(): SupportTicket
    {
        return SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->cliente->id, 'staff_id' => $this->tecnicoB->id,
            'subject' => 'Sin internet', 'status' => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    /** @return array{devices: array<int>, materials: array<int, float>, sources: array<int, string>} */
    private function disponible(string $url): array
    {
        $r = $this->getJson($url)->assertOk();

        return [
            'devices'   => collect($r->json('devices'))->pluck('id')->sort()->values()->all(),
            'materials' => collect($r->json('materials'))->map(fn ($m) => (float) $m['quantity'])->sort()->values()->all(),
            'sources'   => collect($r->json('devices'))->pluck('source_label')
                ->merge(collect($r->json('materials'))->pluck('source_label'))->unique()->values()->all(),
        ];
    }

    #[Test]
    public function en_la_orden_de_otro_tecnico_el_tecnico_solo_ve_lo_suyo(): void
    {
        $orden = $this->ordenDeB();
        Sanctum::actingAs($this->tecnicoA);

        $d = $this->disponible("/api/installations/{$orden->id}/equipment/available");

        $this->assertSame([$this->deA->id], $d['devices'], 'Ni lo del técnico asignado ni la bodega.');
        $this->assertSame([20.0], $d['materials']);
        $this->assertSame(['Mis equipos'], $d['sources']);

        // Y tampoco lo puede descargar mandando el id a mano.
        $this->postJson("/api/installations/{$orden->id}/equipment", ['device_id' => $this->deB->id])
            ->assertStatus(422);
        $this->assertSame('assigned', $this->deB->fresh()->status);
    }

    #[Test]
    public function en_el_ticket_de_otro_tecnico_el_tecnico_solo_ve_lo_suyo(): void
    {
        $ticket = $this->ticketDeB();
        Sanctum::actingAs($this->tecnicoA);

        $d = $this->disponible("/api/support/{$ticket->id}/equipment/available");

        $this->assertSame([$this->deA->id], $d['devices']);
        $this->assertSame([20.0], $d['materials']);

        $this->postJson("/api/support/{$ticket->id}/equipment", ['device_id' => $this->deB->id])->assertStatus(422);
        $this->assertSame('assigned', $this->deB->fresh()->status);
    }

    #[Test]
    public function el_tecnico_asignado_usa_lo_suyo_y_no_ve_la_bodega(): void
    {
        $orden = $this->ordenDeB();
        Sanctum::actingAs($this->tecnicoB);

        $d = $this->disponible("/api/installations/{$orden->id}/equipment/available");

        $this->assertSame([$this->deB->id], $d['devices']);
        $this->assertSame([30.0], $d['materials']);
    }

    #[Test]
    public function la_oficina_conserva_la_captura_de_lo_del_tecnico_asignado(): void
    {
        // La secretaria que llena en oficina la hoja de una visita que hizo B.
        $orden = $this->ordenDeB();
        Sanctum::actingAs($this->oficina);

        $d = $this->disponible("/api/installations/{$orden->id}/equipment/available");

        $this->assertSame([$this->deB->id, $this->deBodega->id], $d['devices'], 'Lo de B y la bodega; lo de A no.');
        $this->assertSame([30.0, 500.0], $d['materials']);

        $this->postJson("/api/installations/{$orden->id}/equipment", ['device_id' => $this->deB->id])->assertCreated();
        $this->assertSame('installed', $this->deB->fresh()->status);
    }

    #[Test]
    public function mis_equipos_muestra_solo_lo_propio_y_sin_precios(): void
    {
        Sanctum::actingAs($this->tecnicoA);

        $r = $this->getJson('/api/inventory/mine')->assertOk();

        $this->assertSame([$this->deA->id], collect($r->json('devices'))->pluck('id')->all());
        $this->assertSame([20.0], collect($r->json('materials'))->pluck('quantity')->map(fn ($q) => (float) $q)->all());
        $this->assertArrayNotHasKey('price', $r->json('devices.0'), 'El técnico no ve dinero.');

        // Quien administra inventario sí ve el precio de lo suyo.
        InventoryDevice::create(['stock_id' => $this->router->id, 'serial' => 'SN-OFI', 'status' => 'assigned', 'user_id' => $this->oficina->id]);
        Sanctum::actingAs($this->oficina);
        $this->getJson('/api/inventory/mine')->assertOk()->assertJsonPath('devices.0.serial', 'SN-OFI')
            ->assertJsonPath('devices.0.price', fn ($p) => (float) $p === 90000.0);
    }

    #[Test]
    public function la_lista_completa_de_equipos_ya_no_se_abre_con_soporte(): void
    {
        Sanctum::actingAs($this->tecnicoA);

        $this->getJson('/api/inventory')->assertForbidden();
        $this->getJson("/api/inventory/{$this->deB->id}")->assertForbidden();

        Sanctum::actingAs($this->oficina);
        $this->getJson('/api/inventory')->assertOk()->assertJsonCount(3);
    }

    #[Test]
    public function sin_ninguno_de_los_dos_permisos_no_hay_mis_equipos(): void
    {
        $soloSoporte = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => Role::create(['name' => 'Soporte', 'code' => null, 'permissions' => [P::VIEW_SUPPORT], 'tenant_id' => $this->tenant->id])->id,
        ]);
        Sanctum::actingAs($soloSoporte);

        $this->getJson('/api/inventory/mine')->assertForbidden();
    }

    #[Test]
    public function la_migracion_da_mis_equipos_a_los_roles_tecnicos(): void
    {
        $tecnico = Role::create(['name' => 'Técnico X', 'code' => 'technician', 'permissions' => [P::VIEW_CLIENTS], 'tenant_id' => $this->tenant->id]);
        $conta   = Role::create(['name' => 'Contabilidad', 'code' => 'accounting', 'permissions' => [P::VIEW_BILLING], 'tenant_id' => $this->tenant->id]);
        $comodin = Role::create(['name' => 'Dueño', 'code' => 'technician', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id]);

        $migracion = require database_path('migrations/2026_11_26_000002_grant_view_own_inventory_to_technician_roles.php');
        $migracion->up();
        $migracion->up(); // idempotente

        $permisos = fn (Role $r) => Role::withoutGlobalScopes()->find($r->id)->permissions;
        $this->assertSame([P::VIEW_CLIENTS, P::VIEW_OWN_INVENTORY], $permisos($tecnico));
        $this->assertSame([P::VIEW_BILLING], $permisos($conta));
        $this->assertSame(['*'], $permisos($comodin));

        $migracion->down();
        $this->assertSame([P::VIEW_CLIENTS], $permisos($tecnico));
    }
}
