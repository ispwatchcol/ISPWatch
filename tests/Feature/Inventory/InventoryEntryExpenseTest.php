<?php

namespace Tests\Feature\Inventory;

use App\Imports\InventoryImport;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\InventoryExpenseRecorder;
use App\Services\Inventory\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gasto automático al ingresar inventario (KAN-91).
 *
 * La función nace APAGADA y eso es lo primero que se prueba: muchos ISP ya
 * registran a mano la factura del proveedor, y encenderla sin querer contaría
 * esa compra dos veces. Un balance que miente hacia abajo no lo reclama nadie,
 * así que el default tiene que estar blindado por una prueba.
 *
 * El resto cubre lo que el ticket pedía explícitamente: que la carga masiva
 * genere gastos IGUAL que el alta unitaria —es la trampa, porque la importación
 * no pasa por el ledger—, que reintentar no cobre dos veces, y que deshacer
 * anule en vez de borrar.
 */
class InventoryEntryExpenseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->actor  = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function encender(?int $categoriaId = null): void
    {
        $this->tenant->forceFill([
            'inventory_entry_creates_expense' => true,
            'inventory_expense_category_id'   => $categoriaId,
        ])->save();
    }

    private function stock(?float $precio = 120000, bool $serializado = true): InventoryStock
    {
        $stock = new InventoryStock([
            'brand'         => 'MIKROTIK',
            'model'         => 'LDF',
            'price'         => $precio,
            'is_serialized' => $serializado,
        ]);
        $stock->tenant_id = $this->tenant->id;
        $stock->save();

        return $stock;
    }

    private function equipo(InventoryStock $stock, string $serial = 'SN-1'): InventoryDevice
    {
        $device = new InventoryDevice([
            'stock_id' => $stock->id,
            'serial'   => $serial,
            'status'   => InventoryDevice::STATUS_STOCK,
        ]);
        $device->tenant_id = $this->tenant->id;
        $device->save();

        return $device;
    }

    // ── El interruptor ──────────────────────────────────────────────────────

    #[Test]
    public function apagado_por_defecto_no_crea_ningun_gasto(): void
    {
        // Lo más importante de todo el ticket: el comportamiento actual no puede
        // cambiar para quien no pidió nada.
        $this->assertFalse(
            (bool) $this->tenant->fresh()->inventory_entry_creates_expense,
            'El interruptor tiene que nacer apagado.'
        );

        $stock = $this->stock();
        app(InventoryLedger::class)->recordInitialEntry($this->equipo($stock), $this->actor);

        $this->assertSame(0, Expense::withoutTenantScope()->count());
    }

    #[Test]
    public function encendido_crea_el_gasto_por_precio_por_cantidad(): void
    {
        // tenant_id no es fillable (lo pone BelongsToTenant desde el usuario
        // autenticado, y acá no hay sesión): se asigna directo.
        $categoria = new ExpenseCategory(['name' => 'Equipos']);
        $categoria->tenant_id = $this->tenant->id;
        $categoria->save();
        $this->encender($categoria->id);

        $stock = $this->stock(120000);
        app(InventoryLedger::class)->recordInitialEntry($this->equipo($stock), $this->actor);

        $gasto = Expense::withoutTenantScope()->first();

        $this->assertNotNull($gasto, 'Con el interruptor encendido tiene que haber gasto.');
        $this->assertEquals(120000, (float) $gasto->amount);
        $this->assertSame($categoria->id, $gasto->expense_category_id);
        $this->assertSame($this->tenant->id, (int) $gasto->tenant_id);
        $this->assertNotNull($gasto->inventory_movement_id, 'Sin el enlace no se puede anular ni distinguir del manual.');
    }

    #[Test]
    public function el_material_por_cantidad_multiplica_por_la_cantidad(): void
    {
        $this->encender();

        $stock = $this->stock(1500, serializado: false);
        $bodega = InventoryBranch::create(['name' => 'Bodega', 'tenant_id' => $this->tenant->id]);

        // Sin origen = ENTRADA (compra), que es lo que dispara el gasto.
        app(InventoryLedger::class)->transferQuantity(
            $stock, null, null, 'branch', $bodega->id, 40, $this->actor
        );

        $this->assertEquals(60000, (float) Expense::withoutTenantScope()->first()->amount);
    }

    #[Test]
    public function un_traspaso_no_es_una_compra_y_no_genera_gasto(): void
    {
        // Mover material entre bodegas no es plata que salga: si generara gasto,
        // el balance bajaría cada vez que alguien reorganiza el almacén.
        $this->encender();

        $stock  = $this->stock(1500, serializado: false);
        $origen = InventoryBranch::create(['name' => 'Bodega A', 'tenant_id' => $this->tenant->id]);
        $destino = InventoryBranch::create(['name' => 'Bodega B', 'tenant_id' => $this->tenant->id]);

        $ledger = app(InventoryLedger::class);
        $ledger->transferQuantity($stock, null, null, 'branch', $origen->id, 40, $this->actor);
        $gastosTrasLaCompra = Expense::withoutTenantScope()->count();

        $ledger->transferQuantity($stock, 'branch', $origen->id, 'branch', $destino->id, 10, $this->actor);

        $this->assertSame(
            $gastosTrasLaCompra,
            Expense::withoutTenantScope()->count(),
            'El traspaso entre custodios no debe crear gasto.'
        );
    }

    // ── Idempotencia y deshacer ─────────────────────────────────────────────

    #[Test]
    public function reintentar_la_misma_entrada_no_cobra_dos_veces(): void
    {
        $this->encender();

        $stock = $this->stock(120000);
        $ledger = app(InventoryLedger::class);
        $device = $this->equipo($stock);

        $movimiento = $ledger->recordInitialEntry($device, $this->actor);

        // Reintento explícito sobre el MISMO movimiento.
        app(InventoryExpenseRecorder::class)->forMovement($movimiento);
        app(InventoryExpenseRecorder::class)->forMovement($movimiento);

        $this->assertSame(1, Expense::withoutTenantScope()->count());
    }

    #[Test]
    public function deshacer_la_entrada_anula_el_gasto_pero_no_lo_borra(): void
    {
        // Precedente firme del proyecto: los registros de dinero no se destruyen.
        $this->encender();

        $stock = $this->stock(120000);
        $movimiento = app(InventoryLedger::class)->recordInitialEntry($this->equipo($stock), $this->actor);

        app(InventoryExpenseRecorder::class)->voidForMovement($movimiento->id);

        $gasto = Expense::withoutTenantScope()->first();

        $this->assertNotNull($gasto, 'El gasto no se borra.');
        $this->assertSame(Expense::STATUS_VOID, $gasto->status);
    }

    // ── Sin precio de catálogo ──────────────────────────────────────────────

    #[Test]
    public function sin_precio_no_crea_gasto_y_devuelve_aviso(): void
    {
        // Decisión tomada: ni gasto en 0 (un cero se lee como "salió gratis") ni
        // silencio (el balance mentiría sin que nadie se entere).
        $this->encender();

        $stock = $this->stock(null);
        $movimiento = app(InventoryLedger::class)->recordInitialEntry($this->equipo($stock), $this->actor);

        $aviso = app(InventoryExpenseRecorder::class)->forMovement($movimiento);

        $this->assertSame(0, Expense::withoutTenantScope()->count());
        $this->assertNotNull($aviso);
        $this->assertStringContainsString('no tiene precio', $aviso);
    }

    // ── La trampa del ticket: la carga masiva ───────────────────────────────

    #[Test]
    public function la_carga_masiva_genera_los_gastos_igual_que_el_alta_unitaria(): void
    {
        // El punto que el ticket marcaba como trampa: la importación NO pasa por
        // el ledger. Si sólo se enganchara ahí, importar 200 equipos no crearía
        // ni un gasto y nadie se enteraría.
        $this->encender();

        $import = new InventoryImport($this->tenant->id);
        $import->collection(collect([
            ['marca' => 'TP-Link', 'modelo' => 'Archer C6', 'precio' => '100000', 'serial' => 'SN-A', 'proveedor' => 'Prov', 'sucursal' => 'Bodega'],
            ['marca' => 'TP-Link', 'modelo' => 'Archer C6', 'precio' => '100000', 'serial' => 'SN-B', 'proveedor' => 'Prov', 'sucursal' => 'Bodega'],
        ]));

        $this->assertSame(2, $import->imported);
        $this->assertSame(2, Expense::withoutTenantScope()->count(), 'Un gasto por equipo importado.');
        $this->assertEquals(200000, (float) Expense::withoutTenantScope()->sum('amount'));
    }

    #[Test]
    public function la_carga_masiva_apagada_no_crea_gastos(): void
    {
        $import = new InventoryImport($this->tenant->id);
        $import->collection(collect([
            ['marca' => 'TP-Link', 'modelo' => 'Archer C6', 'precio' => '100000', 'serial' => 'SN-A', 'proveedor' => 'Prov', 'sucursal' => 'Bodega'],
        ]));

        $this->assertSame(1, $import->imported);
        $this->assertSame(0, Expense::withoutTenantScope()->count());
    }

    #[Test]
    public function la_carga_masiva_agrupa_el_aviso_por_modelo(): void
    {
        // 200 equipos del mismo modelo sin precio no pueden dar 200 avisos
        // idénticos: sólo esconderían los demás.
        $this->encender();

        $import = new InventoryImport($this->tenant->id);
        $import->collection(collect([
            ['marca' => 'Generico', 'modelo' => 'Sin Precio', 'serial' => 'SN-1', 'proveedor' => 'Prov', 'sucursal' => 'Bodega'],
            ['marca' => 'Generico', 'modelo' => 'Sin Precio', 'serial' => 'SN-2', 'proveedor' => 'Prov', 'sucursal' => 'Bodega'],
            ['marca' => 'Generico', 'modelo' => 'Sin Precio', 'serial' => 'SN-3', 'proveedor' => 'Prov', 'sucursal' => 'Bodega'],
        ]));

        $this->assertSame(3, $import->imported, 'Los equipos SÍ entran: lo que falta es el gasto.');
        $this->assertSame(0, Expense::withoutTenantScope()->count());
        $this->assertCount(1, $import->warnings, 'Un aviso por modelo, no uno por equipo.');
        $this->assertStringContainsString('3 entradas', $import->warnings[0]);
        $this->assertEmpty($import->errors, 'Falta el gasto, pero la importación no falló.');
    }

    #[Test]
    public function el_gasto_guarda_el_precio_del_momento_y_no_sigue_al_catalogo(): void
    {
        // expenses.amount es columna propia: cambiar el catálogo después no puede
        // reescribir la historia contable.
        $this->encender();

        $stock = $this->stock(120000);
        app(InventoryLedger::class)->recordInitialEntry($this->equipo($stock), $this->actor);

        $stock->update(['price' => 999999]);

        $this->assertEquals(120000, (float) Expense::withoutTenantScope()->first()->amount);
    }
}
