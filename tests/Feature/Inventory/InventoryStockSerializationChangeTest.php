<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBalance;
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
 * Cambiar cómo se cuenta un modelo que ya tiene existencias.
 *
 * `is_serialized` decide de dónde salen las cantidades: si es serializado, de
 * las filas de `inventory_device` (una por aparato); si no, de los saldos por
 * custodio en `inventory_balances`. Al cambiarlo, lo registrado bajo la forma
 * anterior deja de mirarse — no se borra, se vuelve invisible, que en
 * contabilidad es peor: nadie se entera de que faltan.
 *
 * La pantalla ya desactivaba el control cuando el modelo tenía movimiento, y de
 * eso se fiaba el backend. Pero una interfaz no es una restricción: la API
 * quedaba abierta y un formulario con estado viejo bastaba para colarlo. Estas
 * pruebas existen para que la validación no se caiga junto con un refactor y
 * volvamos a depender de que el botón esté gris. P-19 · KAN-77.
 */
class InventoryStockSerializationChangeTest extends TestCase
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

    private function stock(bool $serializado): InventoryStock
    {
        return InventoryStock::create([
            'brand'         => 'MIKROTIK',
            'model'         => 'LDF',
            'is_serialized' => $serializado,
            'tenant_id'     => $this->tenant->id,
        ]);
    }

    #[Test]
    public function no_deja_pasar_a_cantidad_un_modelo_con_equipos_por_serial(): void
    {
        $stock = $this->stock(true);

        InventoryDevice::create([
            'stock_id'  => $stock->id,
            'serial'    => 'ABC123',
            'status'    => InventoryDevice::STATUS_STOCK,
            'tenant_id' => $this->tenant->id,
        ]);

        $this->putJson("/api/inventory-stock/{$stock->id}", [
            'brand'         => 'MIKROTIK',
            'model'         => 'LDF',
            'is_serialized' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('is_serialized');

        // Lo que de verdad importa: el modelo NO cambió, así que el equipo
        // sigue contándose.
        $this->assertTrue($stock->fresh()->is_serialized);
    }

    #[Test]
    public function no_deja_pasar_a_serial_un_modelo_con_saldos_por_cantidad(): void
    {
        $stock = $this->stock(false);

        InventoryBalance::create([
            'stock_id'    => $stock->id,
            'holder_type' => 'branch',
            'holder_id'   => 1,
            'quantity'    => 25,
            'tenant_id'   => $this->tenant->id,
        ]);

        $this->putJson("/api/inventory-stock/{$stock->id}", [
            'brand'         => 'MIKROTIK',
            'model'         => 'LDF',
            'is_serialized' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('is_serialized');

        $this->assertFalse($stock->fresh()->is_serialized);
    }

    #[Test]
    public function el_mensaje_dice_cuantas_existencias_estorban(): void
    {
        // Un "no se puede" a secas obliga a adivinar qué hay que mover.
        $stock = $this->stock(true);

        foreach (['S1', 'S2', 'S3'] as $serial) {
            InventoryDevice::create([
                'stock_id'  => $stock->id,
                'serial'    => $serial,
                'status'    => InventoryDevice::STATUS_STOCK,
                'tenant_id' => $this->tenant->id,
            ]);
        }

        $respuesta = $this->putJson("/api/inventory-stock/{$stock->id}", [
            'is_serialized' => false,
        ])->assertStatus(422);

        $this->assertStringContainsString('3 equipo', $respuesta->json('errors.is_serialized.0'));
    }

    #[Test]
    public function un_modelo_sin_existencias_si_puede_cambiar(): void
    {
        // El caso legítimo: alguien creó el modelo con la opción equivocada y lo
        // corrige antes de cargar nada. Bloquear esto sería un estorbo.
        $stock = $this->stock(true);

        $this->putJson("/api/inventory-stock/{$stock->id}", [
            'brand'         => 'MIKROTIK',
            'model'         => 'LDF',
            'is_serialized' => false,
        ])->assertOk();

        $this->assertFalse($stock->fresh()->is_serialized);
    }

    #[Test]
    public function editar_otros_campos_no_se_bloquea_por_tener_existencias(): void
    {
        // La restricción es sobre CAMBIAR la forma de contar, no sobre editar el
        // modelo: corregir el precio de algo que ya está en bodega es corriente.
        $stock = $this->stock(true);

        InventoryDevice::create([
            'stock_id'  => $stock->id,
            'serial'    => 'ABC123',
            'status'    => InventoryDevice::STATUS_STOCK,
            'tenant_id' => $this->tenant->id,
        ]);

        $this->putJson("/api/inventory-stock/{$stock->id}", [
            'brand'         => 'MIKROTIK',
            'model'         => 'LDF XL',
            'price'         => 150000,
            'is_serialized' => true,
        ])->assertOk();

        $this->assertSame('LDF XL', $stock->fresh()->model);
    }
}
