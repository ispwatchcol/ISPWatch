<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Material que quedó sin custodio.
 *
 * Borrar una sucursal o un usuario NO borra sus saldos, y es a propósito: hacer
 * desaparecer existencias en silencio sería peor que dejarlas sin dueño. Pero
 * hasta ahora esas filas sólo se veían consultando la tabla a mano, así que en
 * la práctica el material estaba perdido: no lo contaba nadie y no había forma
 * de recuperarlo desde la aplicación.
 *
 * Listarlo es media solución. La otra mitad es poder MOVERLO: el traspaso
 * validaba que el custodio de origen existiera, así que un saldo huérfano
 * quedaba visible y atrapado. Estas pruebas cubren las dos mitades, porque por
 * separado no sirven de nada. P-19 · KAN-77.
 */
class InventoryOrphanBalancesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create([
            'name'        => 'Admin',
            'permissions' => ['*'],
            'tenant_id'   => $this->tenant->id,
        ]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        Sanctum::actingAs($this->admin);
    }

    private function material(string $modelo = 'RJ45'): InventoryStock
    {
        return InventoryStock::create([
            'brand'         => 'GENERICO',
            'model'         => $modelo,
            'is_serialized' => false,
            'unit'          => 'und',
        ]);
    }

    private function saldo(InventoryStock $stock, string $tipo, int $holderId, float $cantidad): InventoryBalance
    {
        return InventoryBalance::create([
            'stock_id'    => $stock->id,
            'holder_type' => $tipo,
            'holder_id'   => $holderId,
            'quantity'    => $cantidad,
        ]);
    }

    #[Test]
    public function lista_el_saldo_de_una_sucursal_borrada(): void
    {
        $stock = $this->material();
        $sucursal = InventoryBranch::create(['name' => 'Bodega Vieja']);

        $this->saldo($stock, InventoryBalance::HOLDER_BRANCH, $sucursal->id, 40);

        // Se borra la sucursal: el saldo sobrevive, a propósito.
        $idBorrado = $sucursal->id;
        $sucursal->delete();

        $this->getJson('/api/inventory/orphan-balances')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.quantity', 40)
            ->assertJsonPath('0.holder_id', $idBorrado)
            ->assertJsonPath('0.holder_type', 'branch');
    }

    #[Test]
    public function el_saldo_de_un_custodio_vivo_no_aparece(): void
    {
        // Si apareciera, la pantalla se llenaría de ruido y dejaría de servir.
        $stock = $this->material();
        $sucursal = InventoryBranch::create(['name' => 'Bodega Central']);

        $this->saldo($stock, InventoryBalance::HOLDER_BRANCH, $sucursal->id, 25);

        $this->getJson('/api/inventory/orphan-balances')
            ->assertOk()
            ->assertJsonCount(0);
    }

    #[Test]
    public function un_saldo_en_cero_no_es_material_perdido(): void
    {
        // Un huérfano sin existencias no hay que rescatarlo: es sólo un rastro.
        $stock = $this->material();
        $sucursal = InventoryBranch::create(['name' => 'Bodega Vacia']);
        $this->saldo($stock, InventoryBalance::HOLDER_BRANCH, $sucursal->id, 0);
        $sucursal->delete();

        $this->getJson('/api/inventory/orphan-balances')
            ->assertOk()
            ->assertJsonCount(0);
    }

    #[Test]
    public function el_saldo_de_un_usuario_borrado_tambien_aparece(): void
    {
        $stock = $this->material('Cable UTP');
        $tecnico = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->saldo($stock, InventoryBalance::HOLDER_USER, $tecnico->id, 15);

        $idBorrado = $tecnico->id;
        $tecnico->delete();

        $this->getJson('/api/inventory/orphan-balances')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.holder_type', 'user')
            ->assertJsonPath('0.holder_id', $idBorrado);
    }

    #[Test]
    public function el_saldo_huerfano_se_puede_traspasar_a_un_custodio_vivo(): void
    {
        // La mitad que importa: listarlo sin poder moverlo no rescata nada.
        $stock = $this->material();
        $vieja = InventoryBranch::create(['name' => 'Bodega Vieja']);
        $nueva = InventoryBranch::create(['name' => 'Bodega Nueva']);

        $this->saldo($stock, InventoryBalance::HOLDER_BRANCH, $vieja->id, 40);
        $idHuerfano = $vieja->id;
        $vieja->delete();

        $this->postJson('/api/inventory/transfers', [
            'to_type'   => 'branch',
            'to_id'     => $nueva->id,
            'materials' => [[
                'stock_id'    => $stock->id,
                'quantity'    => 40,
                'source_type' => 'branch',
                'source_id'   => $idHuerfano,
            ]],
            'notes' => 'Rescate de material sin custodio',
        ])->assertCreated();

        // El material quedó contado en la sucursal viva y el huérfano en cero.
        $this->assertEquals(40, (float) InventoryBalance::heldBy('branch', $nueva->id)
            ->where('stock_id', $stock->id)->value('quantity'));

        $this->assertEquals(0, (float) InventoryBalance::heldBy('branch', $idHuerfano)
            ->where('stock_id', $stock->id)->value('quantity'));

        // Y ya no figura como perdido.
        $this->getJson('/api/inventory/orphan-balances')->assertJsonCount(0);
    }

    #[Test]
    public function no_se_puede_inventar_un_origen_para_sacar_material_de_la_nada(): void
    {
        // Aceptar orígenes huérfanos no puede volverse un agujero: sólo vale si
        // hay una fila de saldo de verdad detrás.
        $stock = $this->material();
        $nueva = InventoryBranch::create(['name' => 'Bodega Nueva']);

        $this->postJson('/api/inventory/transfers', [
            'to_type'   => 'branch',
            'to_id'     => $nueva->id,
            'materials' => [[
                'stock_id'    => $stock->id,
                'quantity'    => 999,
                'source_type' => 'branch',
                'source_id'   => 99999,
            ]],
        ])->assertStatus(422);
    }

    #[Test]
    public function el_destino_si_tiene_que_existir(): void
    {
        // La flexibilidad es sólo para el origen: mandar material a un custodio
        // inventado lo haría desaparecer otra vez.
        $stock = $this->material();

        $this->postJson('/api/inventory/transfers', [
            'to_type'   => 'branch',
            'to_id'     => 88888,
            'materials' => [[
                'stock_id' => $stock->id,
                'quantity' => 5,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('to_id');
    }
}
