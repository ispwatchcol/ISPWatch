<?php

namespace Tests\Feature\Inventory;

use App\Imports\InventoryImport;
use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\Expense;
use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\TicketEquipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El alta de un material «por cantidad» por el camino de la pantalla.
 *
 * Reporte: «el formulario sólo permite crear equipos con serial». Al
 * reproducirlo, el catálogo sí ofrecía «Por cantidad», pero el formulario que
 * el menú llama «Agregar equipo» listaba también los materiales y aceptaba
 * darles un serial: «Fibra drop» quedaba como un equipo, sin metros, y la
 * existencia del catálogo lo sumaba como un metro más (100 m → 101).
 *
 * Se fija aquí el recorrido correcto con las mismas llamadas que hace la
 * pantalla —alta sin serial, entrada de 100 m, entrega, consumo en orden y en
 * ticket— y que el camino equivocado ya no deja datos inconsistentes.
 *
 * Sobre el precio: `inventory_stock.price` es UN precio de catálogo por unidad
 * de medida. El sistema lo usa en dos sitios y no en otros: el precio
 * congelado de la línea consumida (sugerencia de cobro = cantidad × precio) y,
 * si la empresa lo activó, el costo del gasto automático de la entrada. No es
 * un costo total ni entra en ninguna valoración de inventario.
 */
class MaterialCatalogEntryFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $technician;
    private User $customer;
    private InventoryBranch $bodega;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => Role::create(['name' => 'Admin', 'code' => 'admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id])->id,
        ]);
        $this->technician = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => Role::create([
                'name' => 'Técnico', 'code' => 'technician', 'tenant_id' => $this->tenant->id,
                'permissions' => ['view_support', 'ticket_view', 'ticket_intervene', 'ticket_equipment'],
            ])->id,
        ]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create(['user_id' => $this->customer->id, 'name' => 'Ana', 'last_name' => 'Ruiz', 'status' => true]);

        Sanctum::actingAs($this->admin);

        $this->bodega = InventoryBranch::findOrFail($this->postJson('/api/inventory-branches', [
            'name' => 'Bodega Principal', 'dir' => 'Calle 1', 'numero' => '1',
        ])->assertCreated()->json('id'));
    }

    /** Lo que manda la pantalla «Agregar material» de Stock. */
    private function createFibraDrop(): InventoryStock
    {
        $id = $this->postJson('/api/inventory-stock', [
            'brand' => 'GENÉRICO', 'model' => 'Fibra drop', 'price' => 1500,
            'is_serialized' => false, 'unit' => 'metro', 'quantity_decimals' => 2,
        ])->assertCreated()->json('id');

        return InventoryStock::findOrFail($id);
    }

    /** Lo que manda «Entrada de material» en Entregas y traspasos. */
    private function enter(InventoryStock $stock, float $qty): void
    {
        $this->postJson('/api/inventory/transfers', [
            'to_type' => 'branch', 'to_id' => $this->bodega->id,
            'materials' => [['stock_id' => $stock->id, 'quantity' => $qty]],
            'notes' => 'Entrada de material',
        ])->assertCreated();
    }

    private function available(InventoryStock $stock): array
    {
        return collect($this->getJson('/api/inventory-stock')->assertOk()->json())->firstWhere('id', $stock->id);
    }

    private function balance(InventoryStock $stock, string $type, int $id): float
    {
        return (float) InventoryBalance::withoutTenantScope()
            ->where('stock_id', $stock->id)->where('holder_type', $type)->where('holder_id', $id)->value('quantity');
    }

    // ── Alta y entrada ───────────────────────────────────────────────────

    #[Test]
    public function a_material_is_created_without_serial_and_has_no_existence_until_its_entry(): void
    {
        $fibra = $this->createFibraDrop();

        $this->assertFalse($fibra->is_serialized);
        $this->assertSame('metro', $fibra->unit);
        $this->assertSame(2, $fibra->quantity_decimals);
        $this->assertSame(0, InventoryDevice::withoutTenantScope()->count(), 'Un material no crea filas con serial.');

        // Crear el producto no le da existencia: es el paso que faltaba en el reporte.
        $this->assertEquals(0, $this->available($fibra)['available']);

        $this->enter($fibra, 100);

        $row = $this->available($fibra);
        $this->assertEquals(100, $row['available']);
        $this->assertSame(0, $row['serial_rows']);
        $this->assertSame(100.0, $this->balance($fibra, 'branch', $this->bodega->id));

        $entrada = InventoryMovement::withoutTenantScope()->where('stock_id', $fibra->id)->sole();
        $this->assertSame(InventoryMovement::TYPE_ENTRADA, $entrada->type);
        $this->assertEquals(100, $entrada->quantity);
    }

    #[Test]
    public function the_price_is_per_unit_of_measure_and_is_used_only_where_the_system_uses_it(): void
    {
        $fibra = $this->createFibraDrop();
        $this->assertEquals(1500, $fibra->price, 'Se guarda tal cual: precio por metro, no total.');

        // Con «Gasto automático al ingresar inventario» encendido, el gasto de la
        // entrada es precio por metro × metros que entran. (Apagado —el valor
        // por defecto— no hay gasto: lo fija InventoryEntryExpenseTest.)
        $this->tenant->forceFill(['inventory_entry_creates_expense' => true])->save();
        $this->enter($fibra, 100);
        $this->assertEquals(150000, (float) Expense::withoutTenantScope()->sole()->amount);

        // En el consumo, la línea congela el precio por metro; el cobro sugerido
        // es cantidad × precio y lo decide quien cobra (no se cobra solo).
        $installation = $this->installation();
        $line = $this->postJson("/api/installations/{$installation->id}/equipment", [
            'stock_id' => $fibra->id, 'quantity' => 12.5, 'source_type' => 'branch', 'source_id' => $this->bodega->id,
        ])->assertCreated()->json('item');

        $this->assertEquals(1500, $line['unit_price']);
        $this->assertEquals(12.5, $line['quantity']);
        $this->assertNull($installation->fresh()->invoice_id, 'Consumir no factura.');
    }

    // ── Entrega y consumo en los dos módulos ─────────────────────────────

    #[Test]
    public function the_entered_material_is_delivered_and_consumed_in_an_installation_and_a_ticket(): void
    {
        $fibra = $this->createFibraDrop();
        $this->enter($fibra, 100);

        // Entrega bodega → técnico (Entregas y traspasos).
        $this->postJson('/api/inventory/transfers', [
            'to_type' => 'user', 'to_id' => $this->technician->id,
            'materials' => [['stock_id' => $fibra->id, 'quantity' => 30, 'source_type' => 'branch', 'source_id' => $this->bodega->id]],
        ])->assertCreated();

        $installation = $this->installation();
        $ticket       = $this->ticket();
        Sanctum::actingAs($this->technician);

        // Sólo lo suyo: 30 m en «Mis equipos», nunca la bodega.
        $offer = collect($this->getJson("/api/installations/{$installation->id}/equipment/available")->json('materials'));
        $this->assertSame([['user', 30.0]], $offer->map(fn ($m) => [$m['source_type'], (float) $m['quantity']])->all());

        $this->postJson("/api/installations/{$installation->id}/equipment", [
            'stock_id' => $fibra->id, 'quantity' => 12.5, 'source_type' => 'user', 'source_id' => $this->technician->id,
        ])->assertCreated();
        $this->postJson("/api/support/{$ticket->id}/equipment", [
            'stock_id' => $fibra->id, 'quantity' => 7.5, 'source_type' => 'user', 'source_id' => $this->technician->id,
        ])->assertCreated();

        $this->assertSame(10.0, $this->balance($fibra, 'user', $this->technician->id));
        $this->assertSame(70.0, $this->balance($fibra, 'branch', $this->bodega->id));

        $tipos = InventoryMovement::withoutTenantScope()->where('stock_id', $fibra->id)->orderBy('id')
            ->get(['type', 'quantity', 'installation_id', 'support_ticket_id']);
        $this->assertSame(['entrada', 'traspaso', 'instalacion', 'instalacion'], $tipos->pluck('type')->all());
        $this->assertSame($installation->id, (int) $tipos[2]->installation_id);
        $this->assertSame($ticket->id, (int) $tipos[3]->support_ticket_id);
        $this->assertSame(1, TicketEquipment::withoutTenantScope()->where('ticket_id', $ticket->id)->count());

        Sanctum::actingAs($this->admin);
        $this->assertEquals(80, $this->available($fibra)['available'], '70 en bodega + 10 con el técnico.');
    }

    // ── El camino equivocado ya no deja datos inconsistentes ─────────────

    #[Test]
    public function the_serial_device_form_rejects_a_material(): void
    {
        $fibra = $this->createFibraDrop();

        $this->postJson('/api/inventory', ['stock_id' => $fibra->id, 'serial' => 'FIBRA-ROLLO-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock_id');

        $this->assertSame(0, InventoryDevice::withoutTenantScope()->count());

        // Un equipo con serial no puede pasarse a un modelo de material.
        $onu = InventoryStock::create(['brand' => 'HUAWEI', 'model' => 'ONU', 'is_serialized' => true]);
        $deviceId = $this->postJson('/api/inventory', ['stock_id' => $onu->id, 'serial' => 'ONU-1'])->assertCreated()->json('device.id');
        $this->putJson("/api/inventory/{$deviceId}", ['stock_id' => $fibra->id, 'serial' => 'ONU-1'])
            ->assertStatus(422)->assertJsonValidationErrors('stock_id');
    }

    #[Test]
    public function an_old_serial_row_on_a_material_stays_editable_but_does_not_count_or_get_offered(): void
    {
        $fibra = $this->createFibraDrop();
        $this->enter($fibra, 100);

        // Fila creada antes de la regla (como pasaba con el formulario de equipos).
        $legacy = InventoryDevice::create([
            'stock_id' => $fibra->id, 'serial' => 'FIBRA-ROLLO-1', 'user_id' => $this->technician->id,
            'status' => InventoryDevice::STATUS_ASSIGNED,
        ]);

        $row = $this->available($fibra);
        $this->assertEquals(100, $row['available'], 'Antes sumaba 101.');
        $this->assertSame(1, $row['serial_rows']);

        // Se puede abrir y corregir sin que la regla nueva la bloquee.
        $this->putJson("/api/inventory/{$legacy->id}", ['stock_id' => $fibra->id, 'serial' => 'FIBRA-ROLLO-1', 'mac' => null])
            ->assertOk();

        // Y no aparece como unidad entregable en la orden.
        $installation = $this->installation();
        Sanctum::actingAs($this->technician);
        $devices = collect($this->getJson("/api/installations/{$installation->id}/equipment/available")->json('devices'));
        $this->assertNull($devices->firstWhere('serial', 'FIBRA-ROLLO-1'));
    }

    #[Test]
    public function the_bulk_import_rejects_serial_rows_for_a_material(): void
    {
        $this->createFibraDrop();

        $import = new InventoryImport($this->tenant->id);
        $import->collection(collect([
            ['marca' => 'GENÉRICO', 'modelo' => 'Fibra drop', 'serial' => 'FD-1'],
            ['marca' => 'HUAWEI', 'modelo' => 'ONU', 'serial' => 'ONU-9'],
        ]));

        $this->assertSame(1, $import->imported);
        $this->assertCount(1, $import->errors);
        $this->assertStringContainsString('material por cantidad', $import->errors[0]['error']);
        $this->assertSame(['ONU-9'], InventoryDevice::withoutTenantScope()->pluck('serial')->all());
    }

    // ── Pantallas (no hay navegador en la suite: se fija la fuente) ──────

    #[Test]
    public function the_screens_lead_to_the_material_flow(): void
    {
        $sidebar = file_get_contents(resource_path('js/components/Sidebar.vue'));
        $this->assertStringContainsString("name: 'Agregar material', to: '/inventory/stocks?nuevo=material'", $sidebar);
        $this->assertStringContainsString("name: 'Agregar equipo con serial'", $sidebar);

        $form = file_get_contents(resource_path('js/pages/InventoryForm.vue'));
        $this->assertStringContainsString('v-for="stock in serialStocks"', $form);
        $this->assertStringContainsString('data-testid="material-hint"', $form);

        $stock = file_get_contents(resource_path('js/pages/StockList.vue'));
        $this->assertStringContainsString('data-testid="add-material"', $stock);
        $this->assertStringContainsString('data-testid="price-help"', $stock);
        $this->assertStringContainsString("query: { entrada: item.id }", $stock);

        $transfers = file_get_contents(resource_path('js/pages/InventoryTransfers.vue'));
        $this->assertStringContainsString('id="entrada"', $transfers);
        $this->assertStringContainsString('route.query.entrada', $transfers);
    }

    private function installation(): CustomerInstallation
    {
        return CustomerInstallation::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'technician_id' => $this->technician->id, 'scheduled_date' => now()->toDateString(), 'status' => 'pendiente',
        ]);
    }

    private function ticket(): SupportTicket
    {
        return SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->customer->id, 'staff_id' => $this->technician->id,
            'subject' => 'Cambio de drop', 'status' => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);
    }
}
