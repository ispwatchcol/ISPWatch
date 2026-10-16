<?php

namespace Tests\Feature\Inventory;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La migración que crea `quantity_decimals` no puede empezar a rechazar lo que
 * ayer se aceptaba: deja 2 decimales por default y sólo pasa a enteros los
 * productos que se declaran piezas y nunca se movieron con fracciones.
 */
class QuantityDecimalsBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function migration()
    {
        return require database_path('migrations/2026_10_10_000001_add_quantity_decimals_to_inventory_stock.php');
    }

    private function stock(int $tenantId, string $model, ?string $unit, bool $serialized = false): int
    {
        return DB::table('inventory_stock')->insertGetId([
            'tenant_id' => $tenantId, 'brand' => 'X', 'model' => $model,
            'is_serialized' => $serialized, 'unit' => $unit,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function pieces_become_whole_numbers_and_everything_else_keeps_two_decimals(): void
    {
        $migration = $this->migration();
        $migration->down();

        $tenant = Tenant::factory()->create()->id;

        $conector  = $this->stock($tenant, 'CONECTOR', 'unidad');
        $sinUnidad = $this->stock($tenant, 'AMARRE', null);
        $cable     = $this->stock($tenant, 'CABLE', 'metro');
        $raro      = $this->stock($tenant, 'CINTA', 'und');  // pieza, pero ya se movió con fracción
        $ldf       = $this->stock($tenant, 'LDF', null, true);

        DB::table('inventory_balances')->insert([
            'tenant_id' => $tenant, 'stock_id' => $raro, 'holder_type' => 'branch', 'holder_id' => 1,
            'quantity' => 2.5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_movements')->insert([
            'tenant_id' => $tenant, 'stock_id' => $conector, 'type' => 'entrada',
            'quantity' => 10, 'created_at' => now(),
        ]);

        $migration->up();

        $decimals = DB::table('inventory_stock')->pluck('quantity_decimals', 'id');

        $this->assertSame(0, (int) $decimals[$conector]);
        $this->assertSame(0, (int) $decimals[$sinUnidad]);
        $this->assertSame(2, (int) $decimals[$cable]);
        $this->assertSame(2, (int) $decimals[$raro], 'Con fracciones ya registradas no se le cambia la precisión.');
        $this->assertSame(2, (int) $decimals[$ldf], 'Los serializados no se tocan (se cuentan por unidad).');
    }
}
