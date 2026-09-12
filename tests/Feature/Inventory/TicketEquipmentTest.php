<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\TicketEquipment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Equipos cargados en una visita de soporte (KAN-92).
 *
 * Lo que de verdad hay que cubrir acá no es «agregar un equipo» sino el
 * REEMPLAZO, que es lo que pasa en campo: al cliente se le dañó el router y el
 * técnico lo cambia. Son dos movimientos, no uno — sale el viejo, entra el
 * nuevo—. Si sólo funcionara «agregar», el equipo dañado se quedaría asignado
 * al cliente para siempre y el inventario mentiría.
 *
 * La otra mitad de las pruebas es la regla de custodia, que se hereda de
 * Instalaciones y no puede aflojarse por el camino: un técnico no descarga lo
 * que no tiene encima, porque si no respondería por un equipo que desapareció
 * de su lista sin que él lo entregara.
 */
class TicketEquipmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $tecnico;
    private User $cliente;
    private SupportTicket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create([
            'name'        => 'Soporte',
            'permissions' => ['view_support', 'view_clients'],
            'tenant_id'   => $this->tenant->id,
        ]);

        $this->tecnico = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        $this->cliente = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->ticket = SupportTicket::create([
            'user_id'     => $this->cliente->id,
            'staff_id'    => $this->tecnico->id,
            'tenant_id'   => $this->tenant->id,
            'subject'     => 'No tiene internet',
            'description' => 'El router no enciende',
            'status'      => 'open',
            'priority'    => 'media',
        ]);

        Sanctum::actingAs($this->tecnico);
    }

    private function stock(bool $serializado = true, ?float $precio = 120000): InventoryStock
    {
        $stock = new InventoryStock([
            'brand'         => 'MIKROTIK',
            'model'         => $serializado ? 'LDF' : 'RJ45',
            'price'         => $precio,
            'is_serialized' => $serializado,
            'unit'          => $serializado ? null : 'und',
        ]);
        $stock->tenant_id = $this->tenant->id;
        $stock->save();

        return $stock;
    }

    /** Un equipo en la mochila del técnico. */
    private function equipoDelTecnico(InventoryStock $stock, string $serial, ?User $duenio = null): InventoryDevice
    {
        $device = new InventoryDevice([
            'stock_id' => $stock->id,
            'serial'   => $serial,
            'status'   => InventoryDevice::STATUS_ASSIGNED,
            'user_id'  => ($duenio ?? $this->tecnico)->id,
        ]);
        $device->tenant_id = $this->tenant->id;
        $device->save();

        return $device;
    }

    /** Un equipo ya instalado en casa del cliente. */
    private function equipoDelCliente(InventoryStock $stock, string $serial): InventoryDevice
    {
        $device = new InventoryDevice([
            'stock_id'    => $stock->id,
            'serial'      => $serial,
            'status'      => InventoryDevice::STATUS_INSTALLED,
            'customer_id' => $this->cliente->id,
        ]);
        $device->tenant_id = $this->tenant->id;
        $device->save();

        return $device;
    }

    // ── Cargar ──────────────────────────────────────────────────────────────

    #[Test]
    public function carga_un_equipo_serializado_y_lo_deja_en_el_cliente(): void
    {
        $device = $this->equipoDelTecnico($this->stock(), 'SN-NUEVO');

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'device_id' => $device->id,
        ])->assertCreated();

        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $device->status);
        $this->assertSame($this->cliente->id, (int) $device->customer_id);

        $this->assertSame(1, TicketEquipment::withoutTenantScope()->count());
    }

    #[Test]
    public function el_kardex_dice_de_que_ticket_salio(): void
    {
        // Sin esta marca el kardex diría "se lo llevó el cliente X" sin poder
        // decir por qué, y una entrega de soporte sería indistinguible de una
        // instalación.
        $device = $this->equipoDelTecnico($this->stock(), 'SN-NUEVO');

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated();

        $mov = InventoryMovement::withoutTenantScope()
            ->where('device_id', $device->id)
            ->where('type', InventoryMovement::TYPE_INSTALACION)
            ->first();

        $this->assertNotNull($mov);
        $this->assertSame($this->ticket->id, (int) $mov->ticket_id);
        $this->assertSame($this->cliente->id, (int) $mov->customer_id);
    }

    #[Test]
    public function carga_material_por_cantidad_y_descuenta_el_saldo(): void
    {
        $stock  = $this->stock(serializado: false, precio: 1500);
        $bodega = InventoryBranch::create(['name' => 'Bodega', 'tenant_id' => $this->tenant->id]);

        $saldo = new InventoryBalance([
            'stock_id'    => $stock->id,
            'holder_type' => 'user',
            'holder_id'   => $this->tecnico->id,
            'quantity'    => 50,
        ]);
        $saldo->tenant_id = $this->tenant->id;
        $saldo->save();

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'stock_id'    => $stock->id,
            'quantity'    => 12,
            'source_type' => 'user',
            'source_id'   => $this->tecnico->id,
        ])->assertCreated();

        $this->assertEquals(38, (float) $saldo->fresh()->quantity);
    }

    // ── La regla de custodia, heredada de Instalaciones ─────────────────────

    #[Test]
    public function un_tecnico_no_puede_descargar_lo_que_no_tiene_encima(): void
    {
        // Si se pudiera, un técnico respondería por un equipo que desapareció de
        // su lista sin que él lo entregara.
        $otro   = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $device = $this->equipoDelTecnico($this->stock(), 'SN-AJENO', $otro);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertStatus(422);

        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->fresh()->status);
    }

    #[Test]
    public function quien_captura_en_oficina_si_puede_descargar_lo_del_tecnico_asignado(): void
    {
        // La excepción que el trabajo real exige: la hoja la llena la secretaria
        // pero el equipo lo puso el técnico de la visita. Sin esto habría que
        // traspasar antes los equipos a nombre de quien digita, que es una
        // mentira en el kardex.
        $secretaria = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $this->tecnico->role_id,
        ]);
        $device = $this->equipoDelTecnico($this->stock(), 'SN-DEL-TECNICO');

        Sanctum::actingAs($secretaria);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated();

        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $device->fresh()->status);
    }

    // ── El reemplazo: lo que de verdad pasa en una visita ───────────────────

    #[Test]
    public function retira_el_equipo_danado_del_cliente_y_lo_da_de_baja(): void
    {
        $viejo = $this->equipoDelCliente($this->stock(), 'SN-VIEJO');

        $this->postJson("/api/support/{$this->ticket->id}/equipment/retrieve", [
            'device_id'   => $viejo->id,
            'disposition' => 'baja',
        ])->assertOk();

        $viejo->refresh();
        $this->assertSame(InventoryDevice::STATUS_RETIRED, $viejo->status);
        $this->assertNull($viejo->customer_id, 'Deja de estar asignado al cliente.');

        $this->assertDatabaseHas('inventory_movements', [
            'device_id' => $viejo->id,
            'type'      => InventoryMovement::TYPE_BAJA,
            'ticket_id' => $this->ticket->id,
        ]);
    }

    #[Test]
    public function retira_el_equipo_y_se_lo_lleva_el_tecnico_si_no_es_baja(): void
    {
        // Lo que pasa de verdad en campo: el equipo se va en la mochila, no
        // aparece solo en la bodega.
        $viejo = $this->equipoDelCliente($this->stock(), 'SN-VIEJO');

        $this->postJson("/api/support/{$this->ticket->id}/equipment/retrieve", [
            'device_id'   => $viejo->id,
            'disposition' => 'devolucion',
        ])->assertOk();

        $viejo->refresh();
        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $viejo->status);
        $this->assertSame($this->tecnico->id, (int) $viejo->user_id);
        $this->assertNull($viejo->customer_id);

        $this->assertDatabaseHas('inventory_movements', [
            'device_id' => $viejo->id,
            'type'      => InventoryMovement::TYPE_DEVOLUCION,
            'ticket_id' => $this->ticket->id,
        ]);
    }

    #[Test]
    public function el_reemplazo_completo_deja_el_inventario_cuadrado(): void
    {
        // El escenario entero, que es el que justifica la tarjeta.
        $stock = $this->stock();
        $viejo = $this->equipoDelCliente($stock, 'SN-VIEJO');
        $nuevo = $this->equipoDelTecnico($stock, 'SN-NUEVO');

        $this->postJson("/api/support/{$this->ticket->id}/equipment/retrieve", [
            'device_id'   => $viejo->id,
            'disposition' => 'baja',
        ])->assertOk();

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'device_id' => $nuevo->id,
        ])->assertCreated();

        // El cliente se queda con UNO solo, y es el nuevo.
        $delCliente = InventoryDevice::withoutTenantScope()
            ->where('customer_id', $this->cliente->id)
            ->where('status', InventoryDevice::STATUS_INSTALLED)
            ->pluck('serial');

        $this->assertSame(['SN-NUEVO'], $delCliente->all());
        $this->assertSame(InventoryDevice::STATUS_RETIRED, $viejo->fresh()->status);
    }

    #[Test]
    public function no_se_puede_retirar_un_equipo_que_no_es_del_cliente_del_ticket(): void
    {
        $otroCliente = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $ajeno = new InventoryDevice([
            'stock_id'    => $this->stock()->id,
            'serial'      => 'SN-DE-OTRO',
            'status'      => InventoryDevice::STATUS_INSTALLED,
            'customer_id' => $otroCliente->id,
        ]);
        $ajeno->tenant_id = $this->tenant->id;
        $ajeno->save();

        $this->postJson("/api/support/{$this->ticket->id}/equipment/retrieve", [
            'device_id'   => $ajeno->id,
            'disposition' => 'baja',
        ])->assertStatus(422);

        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $ajeno->fresh()->status);
    }

    // ── Quitar una línea ────────────────────────────────────────────────────

    #[Test]
    public function quitar_una_linea_devuelve_el_equipo_a_su_custodio(): void
    {
        // Es "me equivoqué al capturar", no "el cliente devolvió el equipo".
        $device = $this->equipoDelTecnico($this->stock(), 'SN-NUEVO');

        $respuesta = $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'device_id' => $device->id,
        ])->assertCreated();

        $itemId = $respuesta->json('item.id');

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/{$itemId}")->assertOk();

        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->status);
        $this->assertSame($this->tecnico->id, (int) $device->user_id);
        $this->assertSame(0, TicketEquipment::withoutTenantScope()->count());
    }

    #[Test]
    public function el_catalogo_muestra_lo_que_el_cliente_ya_tiene(): void
    {
        // Sin esta lista, el reemplazo obligaría a saberse el serial de memoria.
        $this->equipoDelCliente($this->stock(), 'SN-VIEJO');

        $this->getJson("/api/support/{$this->ticket->id}/equipment/available")
            ->assertOk()
            ->assertJsonPath('customerDevices.0.serial', 'SN-VIEJO');
    }
}
