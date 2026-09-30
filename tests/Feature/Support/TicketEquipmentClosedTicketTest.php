<?php

namespace Tests\Feature\Support;

use App\Models\CustomerProfile;
use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\TicketEquipment;
use App\Models\User;
use App\Support\TicketWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Equipos del ticket — entrega A del selector de inventario.
 *
 * Un ticket cerrado no mueve inventario: ni entrega, ni consumo, ni retiro, ni
 * reversa. Para eso se reabre, que exige permiso y motivo y deja evento. Lo que
 * sí sigue abierto es COBRAR lo que ya se usó, y cobrar no vuelve a descontar.
 *
 * Además, la sección de consumibles explica por qué sale vacía en vez de
 * desaparecer.
 */
class TicketEquipmentClosedTicketTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $technician;
    private User $customer;
    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        $adminRole = Role::create([
            'name' => 'Admin', 'code' => 'admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);
        $techRole = Role::create([
            'name'        => 'Técnico',
            'code'        => 'technician',
            'permissions' => ['view_support', 'ticket_view', 'ticket_intervene', 'ticket_equipment'],
            'tenant_id'   => $this->tenant->id,
        ]);

        $this->admin      = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $adminRole->id]);
        $this->technician = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $techRole->id]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $this->customer->id, 'name' => 'Ana', 'last_name' => 'Ruiz', 'status' => true,
        ]);

        Sanctum::actingAs($this->admin);

        $this->branch = InventoryBranch::create(['name' => 'Bodega Principal']);
    }

    private function ticket(string $status = 'open'): SupportTicket
    {
        return SupportTicket::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->customer->id,
            'staff_id'  => $this->technician->id,
            'subject'   => 'Sin señal desde anoche',
            'status'    => $status, 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    private function close(SupportTicket $ticket): SupportTicket
    {
        $ticket->update([
            'status'          => TicketWorkflow::CERRADO,
            'confirmed_cause' => 'RF', 'solution' => 'AC02', 'result' => 'R01',
            'resolved_at'     => now()->subHour(), 'closed_at' => now(),
        ]);

        return $ticket->fresh();
    }

    private function cable(): InventoryStock
    {
        return InventoryStock::create([
            'brand' => 'GENÉRICO', 'model' => 'CABLE UTP', 'price' => 1200, 'is_serialized' => false, 'unit' => 'm',
        ]);
    }

    private function router(): InventoryStock
    {
        return InventoryStock::create([
            'brand' => 'TP-LINK', 'model' => 'ARCHER C6', 'price' => 150000, 'is_serialized' => true,
        ]);
    }

    private function balance(InventoryStock $stock, string $type, int $id, float $qty): void
    {
        InventoryBalance::create([
            'stock_id' => $stock->id, 'holder_type' => $type, 'holder_id' => $id, 'quantity' => $qty,
        ]);
    }

    private function movements(): int
    {
        return InventoryMovement::withoutTenantScope()->count();
    }

    // ── Ticket cerrado ───────────────────────────────────────────────────

    #[Test]
    public function a_closed_ticket_rejects_deliveries_consumption_and_retrievals(): void
    {
        $router = $this->router();
        $nuevo  = InventoryDevice::create([
            'stock_id' => $router->id, 'serial' => 'SN-NUEVO', 'user_id' => $this->admin->id,
            'status' => InventoryDevice::STATUS_ASSIGNED,
        ]);
        $viejo = InventoryDevice::create([
            'stock_id' => $router->id, 'serial' => 'SN-VIEJO', 'customer_id' => $this->customer->id,
            'status' => InventoryDevice::STATUS_INSTALLED,
        ]);
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->admin->id, 50);

        $ticket = $this->close($this->ticket());
        $url    = "/api/support/{$ticket->id}/equipment";

        $this->postJson($url, ['device_id' => $nuevo->id])
            ->assertStatus(422)->assertJsonPath('error', 'ticket_already_closed');
        $this->postJson($url, [
            'stock_id' => $cable->id, 'quantity' => 5, 'source_type' => 'user', 'source_id' => $this->admin->id,
        ])->assertStatus(422)->assertJsonPath('error', 'ticket_already_closed');
        $this->postJson($url, [
            'direction' => 'in', 'device_id' => $viejo->id, 'source_type' => 'user', 'source_id' => $this->admin->id,
        ])->assertStatus(422)->assertJsonPath('error', 'ticket_already_closed');

        $this->assertSame(0, $this->movements());
        $this->assertSame(0, TicketEquipment::withoutTenantScope()->count());
        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $nuevo->fresh()->status);
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $viejo->fresh()->status);
        $this->assertEquals(50, (float) InventoryBalance::withoutTenantScope()->firstOrFail()->quantity);
    }

    #[Test]
    public function a_closed_ticket_rejects_reversals_and_keeps_its_lines(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->admin->id, 50);
        $ticket = $this->ticket();

        $itemId = $this->postJson("/api/support/{$ticket->id}/equipment", [
            'stock_id' => $cable->id, 'quantity' => 20, 'source_type' => 'user', 'source_id' => $this->admin->id,
        ])->assertCreated()->json('item.id');

        $ticket = $this->close($ticket);
        $antes  = $this->movements();

        $this->deleteJson("/api/support/{$ticket->id}/equipment/{$itemId}", [
            'reason' => 'Se cargó de más por error en la visita.',
        ])->assertStatus(422)->assertJsonPath('error', 'ticket_already_closed');

        $this->assertSame($antes, $this->movements());
        $this->assertNull(TicketEquipment::withoutTenantScope()->findOrFail($itemId)->reversed_at);
        $this->assertEquals(30, (float) InventoryBalance::withoutTenantScope()->firstOrFail()->quantity);

        // El expediente se sigue leyendo entero.
        $this->getJson("/api/support/{$ticket->id}/equipment")->assertOk()->assertJsonCount(1);
    }

    #[Test]
    public function a_legacy_resolved_ticket_is_also_closed_for_inventory(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->admin->id, 50);
        $ticket = $this->ticket(TicketWorkflow::RESOLVED);

        $this->postJson("/api/support/{$ticket->id}/equipment", [
            'stock_id' => $cable->id, 'quantity' => 5, 'source_type' => 'user', 'source_id' => $this->admin->id,
        ])->assertStatus(422)->assertJsonPath('error', 'ticket_already_closed');
    }

    #[Test]
    public function reopening_with_a_reason_allows_moving_equipment_again(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->admin->id, 50);
        $ticket = $this->close($this->ticket());

        $this->postJson("/api/support/{$ticket->id}/reopen", [
            'reason' => 'Faltó registrar el cable usado en la visita.',
        ])->assertOk();

        $this->postJson("/api/support/{$ticket->id}/equipment", [
            'stock_id' => $cable->id, 'quantity' => 5, 'source_type' => 'user', 'source_id' => $this->admin->id,
        ])->assertCreated();

        $this->assertEquals(45, (float) InventoryBalance::withoutTenantScope()->firstOrFail()->quantity);
    }

    #[Test]
    public function a_used_line_can_still_be_charged_on_a_closed_ticket_without_moving_inventory(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->admin->id, 50);
        $ticket = $this->ticket();

        $this->postJson("/api/support/{$ticket->id}/equipment", [
            'stock_id' => $cable->id, 'quantity' => 20, 'source_type' => 'user', 'source_id' => $this->admin->id,
        ])->assertCreated();

        $ticket = $this->close($ticket);
        $antes  = $this->movements();

        // Lo que hace «Cobrar equipo del ticket» + un concepto manual de servicio.
        $this->postJson("/api/support/{$ticket->id}/charge", [
            'items' => [
                ['description' => 'GENÉRICO CABLE UTP', 'quantity' => 20, 'unit' => 'm', 'unit_price' => 1200],
                ['description' => 'Visita técnica', 'quantity' => 1, 'unit' => 'Servicio', 'unit_price' => 20000],
            ],
        ])->assertCreated();

        $this->assertSame($antes, $this->movements(), 'Cobrar no descuenta inventario otra vez.');
        $this->assertEquals(30, (float) InventoryBalance::withoutTenantScope()->firstOrFail()->quantity);
        $this->assertSame(1, Invoice::where('ticket_id', $ticket->id)->count());
    }

    #[Test]
    public function the_available_list_reports_the_closed_lock(): void
    {
        $ticket = $this->close($this->ticket());

        $this->getJson("/api/support/{$ticket->id}/equipment/available")
            ->assertOk()
            ->assertJsonPath('locked.is_locked', true)
            ->assertJsonPath('locked.reason', 'closed');

        $this->getJson("/api/support/{$this->ticket()->id}/equipment/available")
            ->assertOk()
            ->assertJsonPath('locked.is_locked', false);
    }

    // ── Consumibles visibles y explicados ────────────────────────────────

    #[Test]
    public function a_technician_is_told_why_the_consumables_list_is_empty(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'branch', $this->branch->id, 100);
        $ticket = $this->ticket();

        Sanctum::actingAs($this->technician);

        $response = $this->getJson("/api/support/{$ticket->id}/equipment/available")
            ->assertOk()
            ->assertJsonPath('materials', [])
            ->assertJsonPath('materials_status.code', 'not_accessible');

        $this->assertStringContainsString('esta visita', $response->json('materials_status.message'));
        $this->assertStringNotContainsString('Bodega Principal', $response->getContent());
    }

    #[Test]
    public function the_consumables_list_is_ok_when_the_technician_carries_stock(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->technician->id, 30);
        $ticket = $this->ticket();

        Sanctum::actingAs($this->technician);

        $this->getJson("/api/support/{$ticket->id}/equipment/available")
            ->assertOk()
            ->assertJsonCount(1, 'materials')
            ->assertJsonPath('materials_status.code', 'ok');
    }

    #[Test]
    public function another_tenants_stock_does_not_count_for_the_explanation(): void
    {
        $this->cable();
        $ticket = $this->ticket();

        $otro  = Tenant::factory()->create();
        $rol   = Role::create(['name' => 'Admin', 'permissions' => ['*'], 'tenant_id' => $otro->id]);
        $ajeno = User::factory()->create(['tenant_id' => $otro->id, 'role_id' => $rol->id]);

        Sanctum::actingAs($ajeno);
        $bodega = InventoryBranch::create(['name' => 'Bodega Ajena']);
        $stock  = InventoryStock::create(['brand' => 'X', 'model' => 'Y', 'is_serialized' => false, 'unit' => 'm']);
        InventoryBalance::create([
            'stock_id' => $stock->id, 'holder_type' => 'branch', 'holder_id' => $bodega->id, 'quantity' => 999,
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/support/{$ticket->id}/equipment/available")
            ->assertOk()
            ->assertJsonPath('materials_status.code', 'no_stock');
    }
}
