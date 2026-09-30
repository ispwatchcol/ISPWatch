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
use App\Models\Tenant;
use App\Models\TicketEquipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unidades con serial y consumibles, juntos, en la orden y en el ticket.
 *
 * Nace de un reporte de cliente («veo la cantidad del modelo, pero necesito
 * asignar una LDF concreta por serial»). Al reproducirlo no había regresión:
 * el uso ya guardaba la unidad exacta. Estas pruebas dejan fijado ese
 * comportamiento para que no se pierda:
 *
 *  - Un modelo por serial se registra por UNIDAD: se guarda el device_id que
 *    se eligió —no «uno cualquiera» del modelo ni una salida por cantidad— y
 *    el kardex lleva ese mismo serial.
 *  - Amarres (por unidades) y cable (por metros) se registran por cantidad
 *    con su unidad, en la MISMA orden o ticket que la unidad con serial.
 *  - La lista de lo registrable sólo trae las fuentes que el usuario puede
 *    tomar, con serial y MAC por unidad.
 *  - Cobrar lo usado no vuelve a descontar.
 */
class SerializedUnitsAndConsumablesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $technician;
    private User $customer;
    private InventoryBranch $branch;
    private InventoryStock $ldf;
    private InventoryStock $amarres;
    private InventoryStock $cable;

    /** @var array<string, InventoryDevice> por serial */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        $adminRole = Role::create([
            'name' => 'Admin', 'code' => 'admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);
        // Técnico de campo: llena la hoja y mueve equipo en la visita, pero no
        // administra inventario, así que no toma de la bodega.
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

        $this->ldf = InventoryStock::create([
            'brand' => 'HUAWEI', 'model' => 'LDF HG8145', 'price' => 120000, 'is_serialized' => true,
        ]);
        $this->amarres = InventoryStock::create([
            'brand' => 'GENÉRICO', 'model' => 'AMARRE PLÁSTICO', 'price' => 100, 'is_serialized' => false, 'unit' => 'und',
        ]);
        $this->cable = InventoryStock::create([
            'brand' => 'GENÉRICO', 'model' => 'CABLE DROP', 'price' => 900, 'is_serialized' => false, 'unit' => 'm',
        ]);

        // Varias LDF del MISMO modelo: dos con el técnico (una sin MAC) y dos
        // en bodega. Sólo el serial y la MAC las distinguen.
        $this->unit('LDF-T-01', 'AA:BB:CC:00:00:01', $this->technician);
        $this->unit('LDF-T-02', null, $this->technician);
        $this->unit('LDF-T-03', 'AA:BB:CC:00:00:03', $this->technician);
        $this->unit('LDF-B-01', 'AA:BB:CC:00:00:11', null);
        $this->unit('LDF-B-02', 'AA:BB:CC:00:00:12', null);

        foreach ([[$this->amarres, 100], [$this->cable, 300]] as [$stock, $qty]) {
            $this->balance($stock, 'branch', $this->branch->id, $qty);
            $this->balance($stock, 'user', $this->technician->id, $qty / 2);
        }
    }

    // ── Ayudas ───────────────────────────────────────────────────────────

    private function unit(string $serial, ?string $mac, ?User $holder): InventoryDevice
    {
        return $this->units[$serial] = InventoryDevice::create([
            'stock_id'  => $this->ldf->id,
            'serial'    => $serial,
            'mac'       => $mac,
            'user_id'   => $holder?->id,
            'branch_id' => $holder ? null : $this->branch->id,
            'status'    => $holder ? InventoryDevice::STATUS_ASSIGNED : InventoryDevice::STATUS_STOCK,
        ]);
    }

    private function balance(InventoryStock $stock, string $type, int $id, float $qty): void
    {
        InventoryBalance::create([
            'stock_id' => $stock->id, 'holder_type' => $type, 'holder_id' => $id, 'quantity' => $qty,
        ]);
    }

    private function balanceOf(InventoryStock $stock, string $type, int $id): float
    {
        return (float) InventoryBalance::withoutTenantScope()
            ->where('stock_id', $stock->id)->where('holder_type', $type)->where('holder_id', $id)
            ->value('quantity');
    }

    private function movements(): int
    {
        return InventoryMovement::withoutTenantScope()->count();
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
            'subject'   => 'Cambio de LDF',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    private function useMaterial(string $url, InventoryStock $stock, float $qty)
    {
        return $this->postJson($url, [
            'stock_id' => $stock->id, 'quantity' => $qty, 'source_type' => 'user', 'source_id' => $this->technician->id,
        ]);
    }

    // ── Lo que se ofrece para registrar ──────────────────────────────────

    #[Test]
    public function the_installation_offers_each_reachable_unit_with_its_serial_and_mac(): void
    {
        $installation = $this->installation();
        Sanctum::actingAs($this->technician);

        $devices = collect($this->getJson("/api/installations/{$installation->id}/equipment/available")
            ->assertOk()->json('devices'));

        // Una fila por UNIDAD, no una por modelo con cantidad.
        $this->assertEqualsCanonicalizing(['LDF-T-01', 'LDF-T-02', 'LDF-T-03'], $devices->pluck('serial')->all());
        $this->assertSame([$this->ldf->id], $devices->pluck('stock_id')->unique()->values()->all());
        $this->assertSame('AA:BB:CC:00:00:01', $devices->firstWhere('serial', 'LDF-T-01')['mac']);
        $this->assertNull($devices->firstWhere('serial', 'LDF-T-02')['mac'], 'La MAC que falta llega vacía, no inventada.');

        // Sin administrar inventario, las unidades de bodega no se listan.
        $this->assertNull($devices->firstWhere('serial', 'LDF-B-01'));
    }

    #[Test]
    public function the_ticket_offers_each_reachable_unit_with_its_serial_and_mac(): void
    {
        $ticket = $this->ticket();
        Sanctum::actingAs($this->technician);

        $data = $this->getJson("/api/support/{$ticket->id}/equipment/available")->assertOk()->json();

        $this->assertEqualsCanonicalizing(
            ['LDF-T-01', 'LDF-T-02', 'LDF-T-03'],
            collect($data['devices'])->pluck('serial')->all()
        );
        $this->assertSame('AA:BB:CC:00:00:03', collect($data['devices'])->firstWhere('serial', 'LDF-T-03')['mac']);
        $this->assertEqualsCanonicalizing(['und', 'm'], collect($data['materials'])->pluck('unit')->all());
    }

    // ── Unidad exacta ────────────────────────────────────────────────────

    #[Test]
    public function the_installation_keeps_the_exact_unit_that_was_chosen(): void
    {
        $installation = $this->installation();
        $chosen       = $this->units['LDF-T-02'];
        Sanctum::actingAs($this->technician);

        $response = $this->postJson("/api/installations/{$installation->id}/equipment", ['device_id' => $chosen->id])
            ->assertCreated()
            ->assertJsonPath('item.device_id', $chosen->id)
            ->assertJsonPath('item.serial', 'LDF-T-02')
            ->assertJsonPath('item.mac', null)
            ->assertJsonPath('item.is_device', true);

        $this->assertEquals(1, $response->json('item.quantity'));

        $line = InstallationEquipment::withoutTenantScope()->sole();
        $this->assertSame($chosen->id, $line->device_id);
        $this->assertSame($this->ldf->id, $line->stock_id);

        // Sale ESA unidad y ninguna otra del modelo.
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $chosen->fresh()->status);
        $this->assertSame($this->customer->id, $chosen->fresh()->customer_id);
        foreach (['LDF-T-01', 'LDF-T-03'] as $serial) {
            $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $this->units[$serial]->fresh()->status);
        }

        $movement = InventoryMovement::withoutTenantScope()->sole();
        $this->assertSame($chosen->id, $movement->device_id);
        $this->assertSame('LDF-T-02', $movement->device_serial);
        $this->assertSame($installation->id, $movement->installation_id);
        $this->assertEquals(1, (float) $movement->quantity);
    }

    #[Test]
    public function the_ticket_keeps_the_exact_unit_that_was_delivered(): void
    {
        $ticket = $this->ticket();
        $chosen = $this->units['LDF-T-03'];
        Sanctum::actingAs($this->technician);

        $this->postJson("/api/support/{$ticket->id}/equipment", ['device_id' => $chosen->id])
            ->assertCreated()
            ->assertJsonPath('item.device_id', $chosen->id)
            ->assertJsonPath('item.serial', 'LDF-T-03')
            ->assertJsonPath('item.mac', 'AA:BB:CC:00:00:03')
            ->assertJsonPath('item.direction', 'out');

        $this->assertSame($chosen->id, TicketEquipment::withoutTenantScope()->sole()->device_id);
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $chosen->fresh()->status);
        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $this->units['LDF-T-01']->fresh()->status);

        $movement = InventoryMovement::withoutTenantScope()->sole();
        $this->assertSame($chosen->id, $movement->device_id);
        $this->assertSame('LDF-T-03', $movement->device_serial);
        $this->assertSame($ticket->id, $movement->support_ticket_id);
    }

    #[Test]
    public function a_serialized_model_is_never_taken_as_a_generic_quantity(): void
    {
        $installation = $this->installation();
        $ticket       = $this->ticket();
        Sanctum::actingAs($this->technician);

        $this->useMaterial("/api/installations/{$installation->id}/equipment", $this->ldf, 1)
            ->assertStatus(422)->assertJsonValidationErrors('stock_id');
        $this->useMaterial("/api/support/{$ticket->id}/equipment", $this->ldf, 1)
            ->assertStatus(422)->assertJsonValidationErrors('stock_id');

        $this->assertSame(0, $this->movements());
        $this->assertSame(3, InventoryDevice::where('status', InventoryDevice::STATUS_ASSIGNED)->count());
    }

    // ── Serial + consumibles en la misma orden / ticket ──────────────────

    #[Test]
    public function one_installation_takes_a_serial_unit_ties_by_units_and_cable_by_meters(): void
    {
        $installation = $this->installation();
        $url          = "/api/installations/{$installation->id}/equipment";
        $chosen       = $this->units['LDF-T-01'];
        Sanctum::actingAs($this->technician);

        $this->postJson($url, ['device_id' => $chosen->id])->assertCreated();
        $this->useMaterial($url, $this->amarres, 12)->assertCreated()
            ->assertJsonPath('item.unit', 'und')->assertJsonPath('item.is_device', false);
        $this->useMaterial($url, $this->cable, 37.5)->assertCreated()
            ->assertJsonPath('item.unit', 'm');

        $lines = collect($this->getJson($url)->assertOk()->json());
        $this->assertCount(3, $lines);
        $this->assertSame($chosen->id, $lines->firstWhere('is_device', true)['device_id']);
        $this->assertSame('LDF-T-01', $lines->firstWhere('is_device', true)['serial']);
        $this->assertEquals(12, $lines->firstWhere('stock_id', $this->amarres->id)['quantity']);
        $this->assertEquals(37.5, $lines->firstWhere('stock_id', $this->cable->id)['quantity']);

        // Cada cosa descuenta una vez y de quien la aportó.
        $this->assertSame(3, $this->movements());
        $this->assertEquals(38, $this->balanceOf($this->amarres, 'user', $this->technician->id));
        $this->assertEquals(112.5, $this->balanceOf($this->cable, 'user', $this->technician->id));
        $this->assertEquals(300, $this->balanceOf($this->cable, 'branch', $this->branch->id));

        // Cobrar las tres líneas no vuelve a descontar.
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/installations/{$installation->id}/billing", [
            'installation_cost' => 80000,
            'additional_items'  => [
                ['description' => 'HUAWEI LDF HG8145 · S/N LDF-T-01', 'amount' => 120000],
                ['description' => '12 und · GENÉRICO AMARRE PLÁSTICO', 'amount' => 1200],
                ['description' => '37,5 m · GENÉRICO CABLE DROP', 'amount' => 33750],
            ],
        ])->assertOk();

        $this->assertSame(3, $this->movements());
        $this->assertEquals(112.5, $this->balanceOf($this->cable, 'user', $this->technician->id));
    }

    #[Test]
    public function one_ticket_takes_a_serial_unit_ties_by_units_and_cable_by_meters(): void
    {
        $ticket = $this->ticket();
        $url    = "/api/support/{$ticket->id}/equipment";
        $chosen = $this->units['LDF-T-02'];
        Sanctum::actingAs($this->technician);

        $this->postJson($url, ['device_id' => $chosen->id])->assertCreated();
        $this->useMaterial($url, $this->amarres, 4)->assertCreated()->assertJsonPath('item.unit', 'und');
        $this->useMaterial($url, $this->cable, 12)->assertCreated()->assertJsonPath('item.unit', 'm');

        $lines = collect($this->getJson($url)->assertOk()->json());
        $this->assertCount(3, $lines);
        $this->assertSame($chosen->id, $lines->firstWhere('is_device', true)['device_id']);
        $this->assertSame('LDF-T-02', $lines->firstWhere('is_device', true)['serial']);

        $this->assertSame(3, $this->movements());
        $this->assertEquals(46, $this->balanceOf($this->amarres, 'user', $this->technician->id));
        $this->assertEquals(138, $this->balanceOf($this->cable, 'user', $this->technician->id));

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/support/{$ticket->id}/charge", [
            'items' => [
                ['description' => 'HUAWEI LDF HG8145 · S/N LDF-T-02', 'quantity' => 1, 'unit' => 'Unidad', 'unit_price' => 120000],
                ['description' => 'GENÉRICO AMARRE PLÁSTICO', 'quantity' => 4, 'unit' => 'und', 'unit_price' => 100],
                ['description' => 'GENÉRICO CABLE DROP', 'quantity' => 12, 'unit' => 'm', 'unit_price' => 900],
            ],
        ])->assertCreated();

        $this->assertSame(3, $this->movements(), 'Cobrar no descuenta inventario otra vez.');
        $this->assertEquals(138, $this->balanceOf($this->cable, 'user', $this->technician->id));
    }

    #[Test]
    public function a_bodega_unit_is_registered_exactly_by_whoever_manages_inventory(): void
    {
        $installation = $this->installation();
        $chosen       = $this->units['LDF-B-02'];

        // El técnico no puede tomarla: está en bodega.
        Sanctum::actingAs($this->technician);
        $this->postJson("/api/installations/{$installation->id}/equipment", ['device_id' => $chosen->id])
            ->assertStatus(422);
        $this->assertSame(InventoryDevice::STATUS_STOCK, $chosen->fresh()->status);

        // Quien administra inventario sí, y queda esa misma unidad.
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/installations/{$installation->id}/equipment", ['device_id' => $chosen->id])
            ->assertCreated()
            ->assertJsonPath('item.device_id', $chosen->id)
            ->assertJsonPath('item.serial', 'LDF-B-02')
            ->assertJsonPath('item.source_type', 'branch')
            ->assertJsonPath('item.source_id', $this->branch->id);

        $this->assertSame(InventoryDevice::STATUS_STOCK, $this->units['LDF-B-01']->fresh()->status);
    }
}
