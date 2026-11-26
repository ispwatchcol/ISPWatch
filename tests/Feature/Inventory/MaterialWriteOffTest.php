<?php

namespace Tests\Feature\Inventory;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Mi usuario ingresó mal la cantidad pero no puede quitarlos» (Chaguaní,
 * 2026-11-26).
 *
 * La única salida de un material era entregarlo o gastarlo en una orden: un
 * ingreso de 500 m en vez de 50 inflaba la existencia para siempre. Ahora se
 * descuenta desde Entregas y traspasos, con motivo, y queda en el kardex. Un
 * error de digitación es AJUSTE, no BAJA: esos metros nunca existieron y no
 * pueden contarse como material perdido.
 */
class MaterialWriteOffTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private InventoryBranch $bodega;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->admin  = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => Role::create(['name' => 'Admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id])->id,
        ]);
        Sanctum::actingAs($this->admin);

        $this->bodega = InventoryBranch::create(['name' => 'CHAGUANI']);
    }

    private function material(string $modelo = 'UTP Cat6', int $decimales = 2, ?float $precio = null): InventoryStock
    {
        return InventoryStock::create([
            'brand'             => 'GENERICO',
            'model'             => $modelo,
            'price'             => $precio,
            'is_serialized'     => false,
            'unit'              => 'metro',
            'quantity_decimals' => $decimales,
        ]);
    }

    /** Entrada de material (compra): un traspaso sin origen. */
    private function ingresar(InventoryStock $stock, float $cantidad, ?InventoryBranch $bodega = null): void
    {
        $this->postJson('/api/inventory/transfers', [
            'to_type'   => 'branch',
            'to_id'     => ($bodega ?? $this->bodega)->id,
            'materials' => [['stock_id' => $stock->id, 'quantity' => $cantidad]],
        ])->assertCreated();
    }

    private function saldo(InventoryStock $stock, ?InventoryBranch $bodega = null): float
    {
        return (float) InventoryBalance::withoutTenantScope()
            ->where('stock_id', $stock->id)
            ->where('holder_type', 'branch')
            ->where('holder_id', ($bodega ?? $this->bodega)->id)
            ->value('quantity');
    }

    private function descontar(InventoryStock $stock, float $cantidad, string $motivo, array $extra = [])
    {
        return $this->postJson('/api/inventory/write-offs', [
            'stock_id'    => $stock->id,
            'holder_type' => 'branch',
            'holder_id'   => $this->bodega->id,
            'quantity'    => $cantidad,
            'reason'      => $motivo,
        ] + $extra);
    }

    #[Test]
    public function un_ingreso_digitado_de_mas_se_corrige_y_queda_como_ajuste(): void
    {
        $cable = $this->material();
        $this->ingresar($cable, 500);

        $this->descontar($cable, 450, 'ingreso_de_mas', ['notes' => 'se digitó 500 en vez de 50'])
            ->assertCreated()
            ->assertJsonPath('type', 'ajuste')
            ->assertJsonPath('remaining', 50)
            ->assertJsonPath('warnings', []);

        $this->assertEquals(50, $this->saldo($cable));

        $ajuste = InventoryMovement::withoutTenantScope()->where('type', InventoryMovement::TYPE_AJUSTE)->sole();
        $this->assertEquals(450, (float) $ajuste->quantity);
        $this->assertSame('branch', $ajuste->from_type);
        $this->assertSame($this->bodega->id, (int) $ajuste->from_id);
        $this->assertSame('scrap', $ajuste->to_type, 'Sale del inventario: deja de contarse.');
        $this->assertSame($this->admin->id, (int) $ajuste->created_by, 'Queda quién lo corrigió.');
        $this->assertSame('Corrección: se ingresó de más · se digitó 500 en vez de 50', $ajuste->notes);

        // Nada se borra: la entrada original sigue en el kardex con sus 500.
        $this->assertEquals(500, (float) InventoryMovement::withoutTenantScope()
            ->where('type', InventoryMovement::TYPE_ENTRADA)->sole()->quantity);
    }

    #[Test]
    public function lo_danado_o_perdido_es_baja_y_no_ajuste(): void
    {
        $cable = $this->material();
        $this->ingresar($cable, 300);

        $this->descontar($cable, 20, 'danado')->assertCreated()->assertJsonPath('type', 'baja');
        $this->descontar($cable, 5, 'perdido')->assertCreated()->assertJsonPath('type', 'baja');
        $this->descontar($cable, 15, 'devuelto')->assertCreated()->assertJsonPath('remaining', 260);

        $this->assertEquals(260, $this->saldo($cable));
        $this->assertSame(3, InventoryMovement::withoutTenantScope()->where('type', 'baja')->count());
        $this->assertSame(0, InventoryMovement::withoutTenantScope()->where('type', 'ajuste')->count());
        $this->assertSame(['Dañado', 'Perdido o faltante', 'Devuelto al proveedor'], InventoryMovement::withoutTenantScope()
            ->where('type', 'baja')->orderBy('id')->pluck('notes')->all());
    }

    #[Test]
    public function no_se_descuenta_mas_de_lo_que_hay(): void
    {
        $cable = $this->material();
        $this->ingresar($cable, 50);

        $this->descontar($cable, 80, 'ingreso_de_mas')
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', fn ($m) => str_contains($m, 'disponible 50'));

        $this->assertEquals(50, $this->saldo($cable), 'Un saldo negativo sería existencia inventada.');
        $this->assertSame(0, InventoryMovement::withoutTenantScope()->where('type', 'ajuste')->count());
    }

    #[Test]
    public function el_motivo_es_obligatorio(): void
    {
        $cable = $this->material();
        $this->ingresar($cable, 50);

        $this->postJson('/api/inventory/write-offs', [
            'stock_id' => $cable->id, 'holder_type' => 'branch', 'holder_id' => $this->bodega->id, 'quantity' => 10,
        ])->assertStatus(422)->assertJsonPath('errors.reason.0', 'Elige el motivo del descuento.');

        $this->descontar($cable, 10, 'porque_si')->assertStatus(422);
        $this->assertEquals(50, $this->saldo($cable));
    }

    #[Test]
    public function respeta_la_precision_del_producto(): void
    {
        // Un conector se cuenta entero: descontar 1,5 dejaría un saldo imposible de mover.
        $rj45 = $this->material('RJ45', decimales: 0);
        $this->ingresar($rj45, 100);

        $this->descontar($rj45, 1.5, 'danado')->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->assertEquals(100, $this->saldo($rj45));
    }

    #[Test]
    public function un_equipo_con_serial_no_se_descuenta_por_cantidad(): void
    {
        $router = InventoryStock::create(['brand' => 'RUIJIE', 'model' => 'RG-EW300 PRO', 'is_serialized' => true]);

        $this->descontar($router, 1, 'danado')
            ->assertStatus(422)
            ->assertJsonPath('errors.stock_id.0', fn ($m) => str_contains($m, 'equipo por equipo'));
    }

    #[Test]
    public function no_toca_el_inventario_de_otra_empresa(): void
    {
        $otra = Tenant::factory()->create();

        $bodegaAjena = new InventoryBranch(['name' => 'Bodega ajena']);
        $bodegaAjena->tenant_id = $otra->id;
        $bodegaAjena->save();

        $stockAjeno = new InventoryStock(['brand' => 'X', 'model' => 'Fibra', 'is_serialized' => false, 'unit' => 'metro']);
        $stockAjeno->tenant_id = $otra->id;
        $stockAjeno->save();

        $saldoAjeno = new InventoryBalance([
            'stock_id' => $stockAjeno->id, 'holder_type' => 'branch', 'holder_id' => $bodegaAjena->id, 'quantity' => 900,
        ]);
        $saldoAjeno->tenant_id = $otra->id;
        $saldoAjeno->save();

        // El producto de otra empresa no existe para mí.
        $this->postJson('/api/inventory/write-offs', [
            'stock_id' => $stockAjeno->id, 'holder_type' => 'branch', 'holder_id' => $bodegaAjena->id,
            'quantity' => 100, 'reason' => 'perdido',
        ])->assertNotFound();

        // Y con un producto mío, la bodega ajena no tiene saldo mío que descontar.
        $propio = $this->material();
        $this->postJson('/api/inventory/write-offs', [
            'stock_id' => $propio->id, 'holder_type' => 'branch', 'holder_id' => $bodegaAjena->id,
            'quantity' => 1, 'reason' => 'perdido',
        ])->assertStatus(422);

        $this->assertEquals(900, (float) $saldoAjeno->fresh()->quantity);
        $this->assertSame(0, InventoryMovement::withoutTenantScope()->whereIn('type', ['baja', 'ajuste'])->count());
    }

    #[Test]
    public function sin_permiso_de_inventario_no_se_descuenta(): void
    {
        $cable = $this->material();
        $this->ingresar($cable, 50);

        Sanctum::actingAs(User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => Role::create(['name' => 'Soporte', 'permissions' => ['view_support'], 'tenant_id' => $this->tenant->id])->id,
        ]));

        $this->descontar($cable, 10, 'danado')->assertForbidden();
        $this->assertEquals(50, $this->saldo($cable));
    }

    #[Test]
    public function el_saldo_de_un_custodio_borrado_tambien_se_puede_descontar(): void
    {
        // Igual que el traspaso (P-19): un saldo huérfano no puede quedar atrapado.
        $cable  = $this->material();
        $vieja  = InventoryBranch::create(['name' => 'Bodega vieja']);
        $this->ingresar($cable, 40, $vieja);
        $vieja->delete();

        $this->postJson('/api/inventory/write-offs', [
            'stock_id' => $cable->id, 'holder_type' => 'branch', 'holder_id' => $vieja->id,
            'quantity' => 40, 'reason' => 'perdido',
        ])->assertCreated()->assertJsonPath('remaining', 0);
    }

    #[Test]
    public function con_gasto_automatico_avisa_que_el_gasto_no_cambia(): void
    {
        $categoria = ExpenseCategory::create(['name' => 'Inventario']);
        $this->tenant->forceFill([
            'inventory_entry_creates_expense' => true,
            'inventory_expense_category_id'   => $categoria->id,
        ])->save();

        $cable = $this->material(precio: 1000);
        $this->ingresar($cable, 500);
        $gasto = Expense::withoutTenantScope()->sole();
        $this->assertEquals(500000, (float) $gasto->amount);

        // La corrección no adivina qué entrada fue la equivocada: no mueve dinero, lo avisa.
        $this->descontar($cable, 450, 'ingreso_de_mas')
            ->assertCreated()
            ->assertJsonCount(1, 'warnings')
            ->assertJsonPath('warnings.0', fn ($m) => str_contains($m, 'Finanzas → Gastos'));

        $this->assertEquals(500000, (float) $gasto->fresh()->amount);
        $this->assertSame(Expense::STATUS_ACTIVE, $gasto->fresh()->status);
        $this->assertSame(1, Expense::withoutTenantScope()->count(), 'Un descuento no es una compra: no crea gasto.');

        // Una baja por daño no corrige ninguna entrada: no hay nada que avisar.
        $this->descontar($cable, 10, 'danado')->assertCreated()->assertJsonPath('warnings', []);
    }

    #[Test]
    public function el_kardex_muestra_y_filtra_el_ajuste(): void
    {
        $cable = $this->material();
        $this->ingresar($cable, 500);
        $this->descontar($cable, 450, 'ingreso_de_mas')->assertCreated();

        $this->getJson('/api/inventory/movements?type=ajuste')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'ajuste')
            ->assertJsonPath('data.0.from', 'CHAGUANI')
            // «CHAGUANI → Baja» haría pensar que el material se perdió.
            ->assertJsonPath('data.0.to', 'Corrección de inventario');
    }
}
