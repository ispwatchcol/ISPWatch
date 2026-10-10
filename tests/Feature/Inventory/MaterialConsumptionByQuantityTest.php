<?php

namespace Tests\Feature\Inventory;

use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\InstallationEquipment;
use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SupportTicketHistory;
use App\Models\Tenant;
use App\Models\TicketEquipment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Consumo de materiales «por cantidad» en la orden y en el ticket.
 *
 * Caso de aceptación de la revisión de inventario: «Fibra óptica», unidad
 * «metro», 9830 m de existencia real. Se recorre el flujo de verdad por la
 * API —entrada, entrega al técnico, consumo— y se fija:
 *
 *  - La existencia en bodega no se le enseña a un técnico sin permiso de
 *    inventario: la orden le explica por qué y la entrega es el paso.
 *  - El consumo descuenta UNA vez del saldo autorizado, queda en la línea de
 *    la orden o del ticket y en el kardex con el vínculo.
 *  - La precisión es del producto (enteros para piezas, decimales para metros)
 *    y la exige el ledger en entradas, entregas y consumos.
 *  - El reenvío con la misma clave no vuelve a descontar.
 *  - «Cable utilizado» ya no es una fuente paralela: lo guardado se conserva
 *    como histórico y lo que llegue nuevo se ignora.
 */
class MaterialConsumptionByQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $technician;
    private User $customer;
    private InventoryBranch $bodega;
    private InventoryStock $fibra;
    private InventoryStock $conector;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        $adminRole = Role::create([
            'name' => 'Admin', 'code' => 'admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);
        // Técnico de campo: llena la hoja y gasta material en la visita, pero
        // no administra inventario, así que la bodega no es fuente suya.
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

        $this->bodega = InventoryBranch::create(['name' => 'Bodega Principal']);

        // Por la API, como lo crea el ISP: así se prueba también la regla.
        $this->fibra = InventoryStock::findOrFail($this->postJson('/api/inventory-stock', [
            'brand' => 'GENÉRICO', 'model' => 'FIBRA ÓPTICA', 'price' => 1200,
            'is_serialized' => false, 'unit' => 'metro', 'quantity_decimals' => 2,
        ])->assertCreated()->json('id'));

        $this->conector = InventoryStock::findOrFail($this->postJson('/api/inventory-stock', [
            'brand' => 'GENÉRICO', 'model' => 'CONECTOR SC/APC', 'price' => 1500,
            'is_serialized' => false, 'unit' => 'unidad', 'quantity_decimals' => 0,
        ])->assertCreated()->json('id'));

        // Entrada real de 9830 m a la bodega (Inventario → Entregas, sin origen).
        $this->postJson('/api/inventory/transfers', [
            'to_type' => 'branch', 'to_id' => $this->bodega->id,
            'materials' => [['stock_id' => $this->fibra->id, 'quantity' => 9830]],
        ])->assertCreated();

        $this->postJson('/api/inventory/transfers', [
            'to_type' => 'branch', 'to_id' => $this->bodega->id,
            'materials' => [['stock_id' => $this->conector->id, 'quantity' => 200]],
        ])->assertCreated();
    }

    // ── Ayudas ───────────────────────────────────────────────────────────

    private function balance(InventoryStock $stock, string $type, int $id): float
    {
        return (float) InventoryBalance::withoutTenantScope()
            ->where('stock_id', $stock->id)->where('holder_type', $type)->where('holder_id', $id)
            ->value('quantity');
    }

    private function movementsOf(InventoryStock $stock): int
    {
        return InventoryMovement::withoutTenantScope()->where('stock_id', $stock->id)->count();
    }

    private function installation(): CustomerInstallation
    {
        return CustomerInstallation::create([
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => $this->customer->id,
            'technician_id'  => $this->technician->id,
            'scheduled_date' => now()->toDateString(),
            'status'         => 'pendiente',
        ]);
    }

    private function ticket(): SupportTicket
    {
        return SupportTicket::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->customer->id,
            'staff_id'  => $this->technician->id,
            'subject'   => 'Cambio de drop',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    /** La bodega le entrega al técnico (Inventario → Entregas). */
    private function deliverToTechnician(InventoryStock $stock, float $qty): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/inventory/transfers', [
            'to_type' => 'user', 'to_id' => $this->technician->id,
            'materials' => [[
                'stock_id' => $stock->id, 'quantity' => $qty,
                'source_type' => 'branch', 'source_id' => $this->bodega->id,
            ]],
        ])->assertCreated();
    }

    private function consume(string $url, InventoryStock $stock, $qty, string $type, int $id, ?string $key = null)
    {
        return $this->postJson($url, array_filter([
            'stock_id' => $stock->id, 'quantity' => $qty,
            'source_type' => $type, 'source_id' => $id,
            'client_request_id' => $key,
        ], fn ($v) => $v !== null));
    }

    // ── Caso de aceptación: 9830 m de fibra ──────────────────────────────

    #[Test]
    public function the_catalog_shows_the_unit_precision_and_real_existence_to_inventory_managers(): void
    {
        $fibra = collect($this->getJson('/api/inventory-stock')->assertOk()->json())
            ->firstWhere('id', $this->fibra->id);

        $this->assertSame('metro', $fibra['unit']);
        $this->assertSame(2, $fibra['quantity_decimals']);
        $this->assertEquals(9830, $fibra['available']);

        // Quien sólo ve soporte recibe el catálogo, sin existencias.
        Sanctum::actingAs($this->technician);
        $row = collect($this->getJson('/api/inventory-stock')->assertOk()->json())->firstWhere('id', $this->fibra->id);
        $this->assertArrayNotHasKey('available', $row);
    }

    #[Test]
    public function stock_in_the_warehouse_is_not_offered_to_a_technician_without_inventory_permission(): void
    {
        $installation = $this->installation();
        Sanctum::actingAs($this->technician);

        $res = $this->getJson("/api/installations/{$installation->id}/equipment/available")->assertOk();

        $this->assertSame([], $res->json('materials'));
        // No dice «no hay saldo»: dice que hay, pero no a su alcance, y cuál es el paso.
        $this->assertSame('not_accessible', $res->json('materials_status.code'));
        $this->assertStringContainsString('Inventario → Entregas', $res->json('materials_status.message'));

        // Y no puede saltarse la regla mandando la bodega a mano.
        $this->consume("/api/installations/{$installation->id}/equipment", $this->fibra, 120, 'branch', $this->bodega->id)
            ->assertStatus(422);
        $this->assertSame(9830.0, $this->balance($this->fibra, 'branch', $this->bodega->id));
    }

    #[Test]
    public function after_the_delivery_the_technician_registers_120_meters_in_the_installation_once(): void
    {
        $this->deliverToTechnician($this->fibra, 500);
        $this->assertSame(9330.0, $this->balance($this->fibra, 'branch', $this->bodega->id));

        $installation = $this->installation();
        Sanctum::actingAs($this->technician);

        $offer = collect($this->getJson("/api/installations/{$installation->id}/equipment/available")
            ->assertOk()->json('materials'))->firstWhere('stock_id', $this->fibra->id);

        $this->assertNotNull($offer, 'La fibra entregada al técnico tiene que aparecer en la orden.');
        $this->assertSame('metro', $offer['unit']);
        $this->assertSame(2, $offer['decimals']);
        $this->assertEquals(500, $offer['quantity']);
        $this->assertSame('user', $offer['source_type']);

        $before = $this->movementsOf($this->fibra);

        $line = $this->consume("/api/installations/{$installation->id}/equipment", $this->fibra, 120, 'user', $this->technician->id)
            ->assertCreated()->json('item');

        $this->assertEquals(120, $line['quantity']);
        $this->assertSame('metro', $line['unit']);
        $this->assertSame(380.0, $this->balance($this->fibra, 'user', $this->technician->id));
        $this->assertSame(9330.0, $this->balance($this->fibra, 'branch', $this->bodega->id), 'La bodega no se toca.');

        $this->assertSame($before + 1, $this->movementsOf($this->fibra));
        $movement = InventoryMovement::withoutTenantScope()->where('stock_id', $this->fibra->id)->latest('id')->first();
        $this->assertSame(InventoryMovement::TYPE_INSTALACION, $movement->type);
        $this->assertEquals(120, $movement->quantity);
        $this->assertSame($installation->id, (int) $movement->installation_id);

        // En el historial (kardex) que consulta inventario, con su orden.
        Sanctum::actingAs($this->admin);
        $kardex = collect($this->getJson("/api/inventory/movements?stock_id={$this->fibra->id}")->assertOk()->json('data'));
        $this->assertSame($installation->id, $kardex->first()['installation_id']);
        $this->assertEquals(120, $kardex->first()['quantity']);
    }

    #[Test]
    public function after_the_delivery_the_technician_registers_fractional_meters_in_a_ticket_once(): void
    {
        $this->deliverToTechnician($this->fibra, 500);

        $ticket = $this->ticket();
        Sanctum::actingAs($this->technician);

        $offer = collect($this->getJson("/api/support/{$ticket->id}/equipment/available")
            ->assertOk()->json('materials'))->firstWhere('stock_id', $this->fibra->id);
        $this->assertSame('metro', $offer['unit']);

        $this->consume("/api/support/{$ticket->id}/equipment", $this->fibra, 120.5, 'user', $this->technician->id)
            ->assertCreated();

        $this->assertSame(379.5, $this->balance($this->fibra, 'user', $this->technician->id));

        $line = TicketEquipment::withoutTenantScope()->where('ticket_id', $ticket->id)->sole();
        $this->assertEquals(120.5, $line->quantity);

        $movement = InventoryMovement::withoutTenantScope()->where('stock_id', $this->fibra->id)->latest('id')->first();
        $this->assertSame($ticket->id, (int) $movement->support_ticket_id);

        $this->assertSame(1, SupportTicketHistory::where('support_ticket_id', $ticket->id)
            ->where('event_type', SupportTicketHistory::EQUIPMENT_DELIVERED)->count());

        Sanctum::actingAs($this->admin);
        $kardex = collect($this->getJson("/api/inventory/movements?stock_id={$this->fibra->id}")->json('data'));
        $this->assertSame($ticket->id, $kardex->first()['support_ticket_id']);
    }

    #[Test]
    public function an_inventory_manager_can_consume_straight_from_the_warehouse(): void
    {
        $installation = $this->installation();

        $this->consume("/api/installations/{$installation->id}/equipment", $this->fibra, 120, 'branch', $this->bodega->id)
            ->assertCreated();

        $this->assertSame(9710.0, $this->balance($this->fibra, 'branch', $this->bodega->id));
    }

    // ── Cantidades inválidas ─────────────────────────────────────────────

    #[Test]
    public function more_than_the_available_balance_is_rejected_without_moving_anything(): void
    {
        $this->deliverToTechnician($this->fibra, 500);
        $installation = $this->installation();
        $ticket       = $this->ticket();
        Sanctum::actingAs($this->technician);

        $before = $this->movementsOf($this->fibra);

        $this->consume("/api/installations/{$installation->id}/equipment", $this->fibra, 500.01, 'user', $this->technician->id)
            ->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->consume("/api/support/{$ticket->id}/equipment", $this->fibra, 501, 'user', $this->technician->id)
            ->assertStatus(422)->assertJsonValidationErrors('quantity');

        $this->assertSame(500.0, $this->balance($this->fibra, 'user', $this->technician->id));
        $this->assertSame($before, $this->movementsOf($this->fibra));
        $this->assertSame(0, InstallationEquipment::withoutTenantScope()->count());
        $this->assertSame(0, TicketEquipment::withoutTenantScope()->count());
    }

    #[Test]
    public function zero_and_negative_quantities_are_rejected(): void
    {
        $installation = $this->installation();

        foreach ([0, -5] as $qty) {
            $this->consume("/api/installations/{$installation->id}/equipment", $this->fibra, $qty, 'branch', $this->bodega->id)
                ->assertStatus(422)->assertJsonValidationErrors('quantity');
        }

        $this->assertSame(9830.0, $this->balance($this->fibra, 'branch', $this->bodega->id));
    }

    #[Test]
    public function an_indivisible_product_only_accepts_whole_quantities_everywhere(): void
    {
        $installation = $this->installation();
        $ticket       = $this->ticket();

        $this->consume("/api/installations/{$installation->id}/equipment", $this->conector, 1.5, 'branch', $this->bodega->id)
            ->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->consume("/api/support/{$ticket->id}/equipment", $this->conector, 2.25, 'branch', $this->bodega->id)
            ->assertStatus(422)->assertJsonValidationErrors('quantity');

        // Entradas y entregas también: la precisión es del producto, no del formulario.
        $this->postJson('/api/inventory/transfers', [
            'to_type' => 'user', 'to_id' => $this->technician->id,
            'materials' => [['stock_id' => $this->conector->id, 'quantity' => 0.5, 'source_type' => 'branch', 'source_id' => $this->bodega->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('quantity');

        $this->assertSame(200.0, $this->balance($this->conector, 'branch', $this->bodega->id));

        // Enteros, sí.
        $this->consume("/api/installations/{$installation->id}/equipment", $this->conector, 2, 'branch', $this->bodega->id)
            ->assertCreated();
        $this->assertSame(198.0, $this->balance($this->conector, 'branch', $this->bodega->id));
    }

    #[Test]
    public function meters_accept_up_to_two_decimals_and_not_three(): void
    {
        $installation = $this->installation();

        $this->consume("/api/installations/{$installation->id}/equipment", $this->fibra, 12.555, 'branch', $this->bodega->id)
            ->assertStatus(422)->assertJsonValidationErrors('quantity');

        $this->consume("/api/installations/{$installation->id}/equipment", $this->fibra, 12.55, 'branch', $this->bodega->id)
            ->assertCreated();

        $this->assertSame(9817.45, $this->balance($this->fibra, 'branch', $this->bodega->id));
    }

    #[Test]
    public function precision_cannot_drop_below_existing_fractional_balances(): void
    {
        $installation = $this->installation();
        $this->consume("/api/installations/{$installation->id}/equipment", $this->fibra, 0.5, 'branch', $this->bodega->id)
            ->assertCreated();

        $this->putJson("/api/inventory-stock/{$this->fibra->id}", [
            'brand' => 'GENÉRICO', 'model' => 'FIBRA ÓPTICA', 'is_serialized' => false, 'unit' => 'metro', 'quantity_decimals' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('quantity_decimals');

        $this->putJson("/api/inventory-stock/{$this->fibra->id}", [
            'brand' => 'GENÉRICO', 'model' => 'FIBRA ÓPTICA', 'is_serialized' => false, 'unit' => 'metro', 'quantity_decimals' => 3,
        ])->assertStatus(422)->assertJsonValidationErrors('quantity_decimals');
    }

    // ── Tenants ──────────────────────────────────────────────────────────

    #[Test]
    public function a_product_or_order_from_another_tenant_cannot_be_consumed(): void
    {
        $otro      = Tenant::factory()->create();
        $otroAdmin = User::factory()->create([
            'tenant_id' => $otro->id,
            'role_id'   => Role::create(['name' => 'Admin', 'code' => 'admin', 'permissions' => ['*'], 'tenant_id' => $otro->id])->id,
        ]);

        $installation = $this->installation();
        $ticket       = $this->ticket();

        Sanctum::actingAs($otroAdmin);
        $ajena = InventoryBranch::create(['name' => 'Bodega ajena']);

        // Ni la orden ni el ticket de otra empresa existen para él.
        $this->consume("/api/installations/{$installation->id}/equipment", $this->fibra, 10, 'branch', $ajena->id)
            ->assertNotFound();
        $this->consume("/api/support/{$ticket->id}/equipment", $this->fibra, 10, 'branch', $ajena->id)
            ->assertNotFound();

        // Y desde su orden, nuestro producto y nuestra bodega tampoco.
        $suyaCliente = User::factory()->create(['tenant_id' => $otro->id]);
        $suya = CustomerInstallation::create([
            'tenant_id' => $otro->id, 'customer_id' => $suyaCliente->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pendiente',
        ]);
        $this->consume("/api/installations/{$suya->id}/equipment", $this->fibra, 10, 'branch', $this->bodega->id)
            ->assertNotFound();

        $this->assertSame(9830.0, $this->balance($this->fibra, 'branch', $this->bodega->id));
    }

    // ── Reenvío y concurrencia ───────────────────────────────────────────

    #[Test]
    public function resending_the_same_installation_request_does_not_discount_twice(): void
    {
        $installation = $this->installation();
        $url = "/api/installations/{$installation->id}/equipment";

        $this->consume($url, $this->fibra, 120, 'branch', $this->bodega->id, 'k-inst-1')
            ->assertCreated()->assertJsonPath('replayed', false);
        $movements = $this->movementsOf($this->fibra);

        $this->consume($url, $this->fibra, 120, 'branch', $this->bodega->id, 'k-inst-1')
            ->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame(9710.0, $this->balance($this->fibra, 'branch', $this->bodega->id));
        $this->assertSame($movements, $this->movementsOf($this->fibra));
        $this->assertSame(1, InstallationEquipment::withoutTenantScope()->count());

        // Otro intento (otra clave) sí es otro consumo.
        $this->consume($url, $this->fibra, 10, 'branch', $this->bodega->id, 'k-inst-2')->assertCreated();
        $this->assertSame(9700.0, $this->balance($this->fibra, 'branch', $this->bodega->id));

        // La clave no se puede reciclar en otra orden.
        $otra = $this->installation();
        $this->consume("/api/installations/{$otra->id}/equipment", $this->fibra, 120, 'branch', $this->bodega->id, 'k-inst-1')
            ->assertStatus(422)->assertJsonValidationErrors('client_request_id');
    }

    #[Test]
    public function resending_the_same_ticket_request_does_not_discount_or_log_twice(): void
    {
        $ticket = $this->ticket();
        $url = "/api/support/{$ticket->id}/equipment";

        $this->consume($url, $this->fibra, 40, 'branch', $this->bodega->id, 'k-tk-1')->assertCreated();
        $this->consume($url, $this->fibra, 40, 'branch', $this->bodega->id, 'k-tk-1')
            ->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame(9790.0, $this->balance($this->fibra, 'branch', $this->bodega->id));
        $this->assertSame(1, TicketEquipment::withoutTenantScope()->where('ticket_id', $ticket->id)->count());
        $this->assertSame(1, SupportTicketHistory::where('support_ticket_id', $ticket->id)
            ->where('event_type', SupportTicketHistory::EQUIPMENT_DELIVERED)->count());
    }

    #[Test]
    public function resending_a_serialized_unit_returns_the_same_line(): void
    {
        $ldf = InventoryStock::create(['brand' => 'HUAWEI', 'model' => 'LDF', 'price' => 100000, 'is_serialized' => true]);
        $unit = InventoryDevice::create([
            'stock_id' => $ldf->id, 'serial' => 'LDF-001', 'mac' => 'AA:BB:CC:00:00:01',
            'branch_id' => $this->bodega->id, 'status' => InventoryDevice::STATUS_STOCK,
        ]);
        $installation = $this->installation();
        $url = "/api/installations/{$installation->id}/equipment";

        $first  = $this->postJson($url, ['device_id' => $unit->id, 'client_request_id' => 'k-dev-1'])->assertCreated();
        $second = $this->postJson($url, ['device_id' => $unit->id, 'client_request_id' => 'k-dev-1'])->assertOk();

        $this->assertSame($first->json('item.id'), $second->json('item.id'));
        $this->assertSame('LDF-001', $second->json('item.serial'));
        $this->assertSame(1, InventoryMovement::withoutTenantScope()->where('device_id', $unit->id)->count());
    }

    #[Test]
    public function the_database_refuses_two_lines_with_the_same_request_key(): void
    {
        // La garantía ante dos peticiones simultáneas es el índice, no la
        // consulta previa: aquí se prueba que existe en el motor.
        $installation = $this->installation();
        $this->consume("/api/installations/{$installation->id}/equipment", $this->fibra, 1, 'branch', $this->bodega->id, 'k-dup')
            ->assertCreated();

        $this->expectException(UniqueConstraintViolationException::class);

        $dup = new InstallationEquipment([
            'installation_id' => $installation->id, 'stock_id' => $this->fibra->id,
            'quantity' => 1, 'client_request_id' => 'k-dup',
        ]);
        $dup->tenant_id = $this->tenant->id;
        $dup->save();
    }

    // ── «Cable utilizado» deja de ser una fuente aparte ──────────────────

    #[Test]
    public function the_sheet_no_longer_accepts_manual_cable_meters(): void
    {
        $installation = $this->installation();

        $this->putJson("/api/installations/{$installation->id}/sheet", [
            'sheet' => ['cable_meters' => 120, 'signal_level' => '-21 dBm', 'observations' => 'ok'],
        ])->assertOk();

        $sheet = $installation->fresh()->sheet;
        $this->assertArrayNotHasKey('cable_meters', $sheet);
        // Lo que no es stock se sigue guardando.
        $this->assertSame('-21 dBm', $sheet['signal_level']);
        $this->assertSame('ok', $sheet['observations']);
    }

    #[Test]
    public function a_historic_manual_cable_value_is_kept_as_is(): void
    {
        $installation = $this->installation();
        $installation->update(['sheet' => ['cable_meters' => 80, 'materials' => 'silicona']]);

        $this->putJson("/api/installations/{$installation->id}/sheet", [
            'sheet' => ['cable_meters' => 10, 'materials' => 'silicona y cinta'],
        ])->assertOk();

        $sheet = $installation->fresh()->sheet;
        $this->assertEquals(80, $sheet['cable_meters'], 'El registro histórico no se borra ni se reescribe.');
        $this->assertSame('silicona y cinta', $sheet['materials']);

        $this->putJson("/api/installations/{$installation->id}/sheet", ['sheet' => ['signal_level' => '-20']])->assertOk();
        $this->assertEquals(80, $installation->fresh()->sheet['cable_meters']);
    }
}
