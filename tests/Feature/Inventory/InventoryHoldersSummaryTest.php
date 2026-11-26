<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «En Entregas y traspasos deben estar también los materiales» (Chaguaní,
 * 2026-11-26).
 *
 * La pantalla abría siempre en «Bodega (sin sucursal)», que nunca tiene
 * materiales —un saldo siempre tiene dueño—, así que la sección de materiales no
 * salía. El resumen dice qué tiene cada bodega o persona para abrir donde hay
 * algo; tiene que contar EXACTAMENTE lo que holdings() listaría al elegirlo.
 */
class InventoryHoldersSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        Sanctum::actingAs(User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => Role::create(['name' => 'Admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id])->id,
        ]));
    }

    private function equipo(InventoryStock $stock, string $status, array $extra = []): InventoryDevice
    {
        return InventoryDevice::create(['stock_id' => $stock->id, 'status' => $status, 'serial' => 'SN-' . uniqid()] + $extra);
    }

    private function saldo(InventoryStock $stock, string $tipo, int $id, float $cantidad): void
    {
        InventoryBalance::create(['stock_id' => $stock->id, 'holder_type' => $tipo, 'holder_id' => $id, 'quantity' => $cantidad]);
    }

    /** @return array<string, array{type: string, id: int|null, devices: int, materials: int}> */
    private function resumen(): array
    {
        return collect($this->getJson('/api/inventory/holders-summary')->assertOk()->json())
            ->keyBy(fn ($h) => $h['type'] . ':' . ($h['id'] ?? ''))
            ->all();
    }

    #[Test]
    public function cuenta_equipos_y_materiales_por_custodio(): void
    {
        $chaguani = InventoryBranch::create(['name' => 'CHAGUANI']);
        $tecnico  = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $router   = InventoryStock::create(['brand' => 'RUIJIE', 'model' => 'RG-EW300 PRO', 'is_serialized' => true]);
        $cable    = InventoryStock::create(['brand' => 'GENERICO', 'model' => 'UTP', 'is_serialized' => false, 'unit' => 'metro']);
        $rj45     = InventoryStock::create(['brand' => 'GENERICO', 'model' => 'RJ45', 'is_serialized' => false, 'unit' => 'und']);
        $grapas   = InventoryStock::create(['brand' => 'GENERICO', 'model' => 'Grapas', 'is_serialized' => false, 'unit' => 'und']);

        $this->equipo($router, 'stock', ['branch_id' => $chaguani->id]);
        $this->equipo($router, 'stock', ['branch_id' => $chaguani->id]);
        $this->equipo($router, 'stock');                                       // sin sucursal
        $this->equipo($router, 'assigned', ['user_id' => $tecnico->id]);
        // Instalado o de baja ya no está en ninguna bodega ni mochila.
        $this->equipo($router, 'installed', ['branch_id' => $chaguani->id]);
        $this->equipo($router, 'retired', ['branch_id' => $chaguani->id]);

        $this->saldo($cable, 'branch', $chaguani->id, 300);
        $this->saldo($rj45, 'branch', $chaguani->id, 50);
        $this->saldo($grapas, 'branch', $chaguani->id, 0);   // agotado: no es existencia
        $this->saldo($cable, 'user', $tecnico->id, 20);

        $resumen = $this->resumen();

        $this->assertSame(['devices' => 2, 'materials' => 2], array_intersect_key($resumen["branch:{$chaguani->id}"], ['devices' => 0, 'materials' => 0]));
        $this->assertSame(1, $resumen['branch:']['devices']);
        $this->assertSame(0, $resumen['branch:']['materials'], '«Sin sucursal» nunca tiene materiales.');
        $this->assertSame(1, $resumen["user:{$tecnico->id}"]['devices']);
        $this->assertSame(1, $resumen["user:{$tecnico->id}"]['materials']);
        $this->assertCount(3, $resumen, 'Sólo aparecen los custodios que tienen algo.');

        // Lo mismo que listaría holdings() al elegir CHAGUANI como origen.
        $this->getJson("/api/inventory/holdings?holder_type=branch&holder_id={$chaguani->id}")
            ->assertOk()
            ->assertJsonCount(2, 'devices')
            ->assertJsonCount(2, 'materials');
    }

    #[Test]
    public function no_mezcla_existencias_de_otra_empresa(): void
    {
        $otra = Tenant::factory()->create();

        $bodega = new InventoryBranch(['name' => 'Ajena']);
        $bodega->tenant_id = $otra->id;
        $bodega->save();

        $stock = new InventoryStock(['brand' => 'X', 'model' => 'Y', 'is_serialized' => false, 'unit' => 'und']);
        $stock->tenant_id = $otra->id;
        $stock->save();

        $saldo = new InventoryBalance(['stock_id' => $stock->id, 'holder_type' => 'branch', 'holder_id' => $bodega->id, 'quantity' => 10]);
        $saldo->tenant_id = $otra->id;
        $saldo->save();

        $this->getJson('/api/inventory/holders-summary')->assertOk()->assertExactJson([]);
    }

    #[Test]
    public function sin_permiso_de_inventario_no_se_consulta(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => Role::create(['name' => 'Soporte', 'permissions' => ['view_support'], 'tenant_id' => $this->tenant->id])->id,
        ]));

        $this->getJson('/api/inventory/holders-summary')->assertForbidden();
    }
}
