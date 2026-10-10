<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryDevice;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KAN-100 · P-44 — `SN-001` y `sn-001` son el mismo equipo por los dos caminos.
 *
 * Antes no lo eran, y la incoherencia era silenciosa: el formulario usaba la
 * regla `unique`, que en PostgreSQL compara con `=` y distingue mayúsculas, así
 * que dejaba entrar el segundo. La carga masiva comparaba en minúsculas y lo
 * rechazaba. Un inventario cargado uno por uno terminaba con el mismo equipo
 * dos veces, y esas dos filas bloqueaban después el archivo entero de una carga
 * masiva sin que nadie entendiera por qué: la primera vez «había funcionado».
 *
 * Se fijan las tres capas, porque arreglar sólo una deja el agujero abierto por
 * las otras: la validación del formulario, el recorte de espacios y el índice
 * único de la base de datos.
 */
class InventoryIdentifierCaseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private InventoryStock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'Admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id]);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        Sanctum::actingAs($this->admin);

        $this->stock = InventoryStock::create([
            'brand' => 'MIKROTIK', 'model' => 'LDF', 'price' => 150000, 'is_serialized' => true,
        ]);
    }

    #[Test]
    public function no_deja_registrar_el_mismo_serial_en_otras_mayusculas(): void
    {
        $this->postJson('/api/inventory', [
            'stock_id' => $this->stock->id,
            'serial'   => 'SN-001',
        ])->assertCreated();

        $this->postJson('/api/inventory', [
            'stock_id' => $this->stock->id,
            'serial'   => 'sn-001',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('serial');

        $this->assertSame(1, InventoryDevice::count());
    }

    #[Test]
    public function no_deja_registrar_la_misma_mac_en_otras_mayusculas(): void
    {
        $this->postJson('/api/inventory', [
            'stock_id' => $this->stock->id,
            'mac'      => 'AA:BB:CC:DD:EE:01',
        ])->assertCreated();

        $this->postJson('/api/inventory', [
            'stock_id' => $this->stock->id,
            'mac'      => 'aa:bb:cc:dd:ee:01',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mac');
    }

    /**
     * El espacio de más es la otra forma de colar el mismo equipo dos veces, y
     * es más fácil de producir: basta pegar el serial desde una hoja de cálculo.
     */
    #[Test]
    public function recorta_los_espacios_antes_de_guardar_y_de_comparar(): void
    {
        $this->postJson('/api/inventory', [
            'stock_id' => $this->stock->id,
            'serial'   => '  SN-077  ',
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_device', ['serial' => 'SN-077']);

        $this->postJson('/api/inventory', [
            'stock_id' => $this->stock->id,
            'serial'   => 'sn-077',
        ])->assertStatus(422);
    }

    /** El serial se guarda como lo escribió el operador: es lo que dice la etiqueta. */
    #[Test]
    public function el_serial_se_guarda_tal_como_se_escribio(): void
    {
        $this->postJson('/api/inventory', [
            'stock_id' => $this->stock->id,
            'serial'   => 'Sn-MiXtO-9',
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_device', ['serial' => 'Sn-MiXtO-9']);
    }

    #[Test]
    public function editar_un_equipo_cambiandole_las_mayusculas_a_su_propio_serial_se_permite(): void
    {
        $device = InventoryDevice::create([
            'stock_id' => $this->stock->id,
            'serial'   => 'SN-500',
        ]);

        $this->putJson("/api/inventory/{$device->id}", [
            'stock_id' => $this->stock->id,
            'serial'   => 'sn-500',
        ])->assertOk();

        $this->assertDatabaseHas('inventory_device', ['id' => $device->id, 'serial' => 'sn-500']);
    }

    /**
     * La unicidad es POR TENANT: dos empresas distintas pueden tener equipos
     * con el mismo serial, y ya hubo un «ya está en uso» imposible de explicar
     * porque el equipo en conflicto era de otra empresa.
     */
    #[Test]
    public function otro_tenant_puede_usar_el_mismo_serial(): void
    {
        $otro = Tenant::factory()->create();
        $ajeno = new InventoryDevice(['serial' => 'SN-900']);
        $ajeno->tenant_id = $otro->id;
        $ajeno->save();

        $this->postJson('/api/inventory', [
            'stock_id' => $this->stock->id,
            'serial'   => 'sn-900',
        ])->assertCreated();
    }

    /**
     * La red de seguridad: aunque alguien escriba saltándose el controlador
     * —un script, un importador futuro, una condición de carrera entre dos
     * peticiones simultáneas—, la base de datos ya no acepta el duplicado.
     */
    #[Test]
    public function el_indice_unico_bloquea_el_duplicado_escrito_por_debajo(): void
    {
        InventoryDevice::create(['stock_id' => $this->stock->id, 'serial' => 'SN-DB-1']);

        $this->expectException(QueryException::class);

        InventoryDevice::create(['stock_id' => $this->stock->id, 'serial' => 'sn-db-1']);
    }

    /** Varias filas sin serial siguen conviviendo: un rollo de cable no tiene. */
    #[Test]
    public function varios_equipos_sin_serial_conviven(): void
    {
        InventoryDevice::create(['stock_id' => $this->stock->id, 'serial' => null, 'mac' => null]);
        InventoryDevice::create(['stock_id' => $this->stock->id, 'serial' => null, 'mac' => null]);

        $this->assertSame(2, InventoryDevice::count());
    }

    /** El informe previo a la migración: sin duplicados, sale limpio. */
    #[Test]
    public function el_comando_de_duplicados_sale_limpio_cuando_no_los_hay(): void
    {
        InventoryDevice::create(['stock_id' => $this->stock->id, 'serial' => 'SN-UNICO']);

        $this->artisan('inventory:duplicate-identifiers')
            ->expectsOutputToContain('Sin duplicados de serial.')
            ->assertExitCode(0);
    }
}
