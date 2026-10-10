<?php

namespace Tests\Feature\Support;

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
use App\Models\SupportTicketHistory;
use App\Models\Tenant;
use App\Models\TicketEquipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Equipos movidos en una visita de soporte.
 *
 * El caso que motivó el módulo es el cambio de router: el técnico llega, deja
 * uno nuevo y se lleva el viejo. Antes de esto el sistema sólo sabía sacar
 * equipos por una orden de instalación, así que la entrega se cobraba a mano y
 * el aparato seguía figurando disponible en la bodega para siempre — y el que
 * se retiraba no salía nunca de la casa del cliente.
 */
class TicketEquipmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $technician;
    private User $miron;
    private User $customer;
    private InventoryBranch $branch;
    private SupportTicket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $adminRole = Role::create([
            'name' => 'Admin', 'code' => 'admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);
        // El técnico de campo: ve soporte, registra la visita y mueve el equipo
        // de esa visita, pero NO administra inventario y —esto es lo que
        // importa— **no tiene `ticket_edit`**. La matriz de la § 3 se lo niega,
        // y la § 18 le da igualmente «materiales, equipos». Si la seccion
        // dependiera de `ticket_edit`, existiria para todos menos para el.
        $techRole = Role::create([
            'name'        => 'Técnico',
            'code'        => 'technician',
            'permissions' => ['view_support', 'ticket_view', 'ticket_intervene', 'ticket_equipment'],
            'tenant_id'   => $this->tenant->id,
        ]);

        // Mirar el modulo de soporte no autoriza a sacar aparatos de la bodega.
        $mironRole = Role::create([
            'name'        => 'Consulta',
            'code'        => 'viewer',
            'permissions' => ['view_support', 'ticket_view'],
            'tenant_id'   => $this->tenant->id,
        ]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $adminRole->id,
        ]);
        $this->technician = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $techRole->id,
        ]);
        $this->miron = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $mironRole->id,
        ]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $this->customer->id, 'name' => 'Ana', 'last_name' => 'Ruiz', 'status' => true,
        ]);

        Sanctum::actingAs($this->admin);

        $this->branch = InventoryBranch::create(['name' => 'Bodega Principal']);

        $this->ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->customer->id,
            'staff_id'  => $this->technician->id,
            'subject'   => 'Se quemó el router con el rayo del domingo',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    private function serializedStock(string $model = 'LDF'): InventoryStock
    {
        return InventoryStock::create([
            'brand' => 'MIKROTIK', 'model' => $model, 'price' => 150000, 'is_serialized' => true,
        ]);
    }

    private function consumableStock(): InventoryStock
    {
        return InventoryStock::create([
            'brand' => 'GENÉRICO', 'model' => 'RJ45 CAT5E', 'price' => 500,
            'is_serialized' => false, 'unit' => 'unidad',
        ]);
    }

    private function deviceHeldBy(InventoryStock $stock, ?User $holder, string $serial): InventoryDevice
    {
        return InventoryDevice::create([
            'stock_id'  => $stock->id,
            'serial'    => $serial,
            'user_id'   => $holder?->id,
            'branch_id' => $holder ? null : $this->branch->id,
            'status'    => $holder ? InventoryDevice::STATUS_ASSIGNED : InventoryDevice::STATUS_STOCK,
        ]);
    }

    private function deviceInstalledAt(InventoryStock $stock, User $customer, string $serial): InventoryDevice
    {
        return InventoryDevice::create([
            'stock_id'    => $stock->id,
            'serial'      => $serial,
            'customer_id' => $customer->id,
            'status'      => InventoryDevice::STATUS_INSTALLED,
        ]);
    }

    #[Test]
    public function a_technician_delivers_a_device_from_the_ticket(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->technician, 'SN-NUEVO');

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'device_id' => $device->id,
        ])->assertCreated()
          ->assertJsonPath('item.is_return', false)
          // El precio del catálogo viaja en la respuesta: es lo que permite
          // precargar el cargo del ticket sin volver a teclearlo.
          ->assertJsonPath('item.unit_price', fn ($precio) => (float) $precio === 150000.0);

        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $device->status);
        $this->assertSame((int) $this->customer->id, (int) $device->customer_id);

        $movimiento = InventoryMovement::withoutTenantScope()
            ->where('type', InventoryMovement::TYPE_INSTALACION)
            ->firstOrFail();

        $this->assertSame((int) $this->ticket->id, (int) $movimiento->support_ticket_id);
        $this->assertSame((int) $this->customer->id, (int) $movimiento->customer_id);
        $this->assertSame(InventoryMovement::HOLDER_CUSTOMER, $movimiento->to_type);
    }

    #[Test]
    public function delivering_a_device_is_written_in_the_ticket_history(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-NUEVO');

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated();

        $evento = SupportTicketHistory::withoutTenantScope()
            ->where('event_type', SupportTicketHistory::EQUIPMENT_DELIVERED)
            ->firstOrFail();

        $this->assertSame('out', $evento->metadata['direction']);
        $this->assertStringContainsString('SN-NUEVO', $evento->metadata['label']);
    }

    #[Test]
    public function the_old_device_is_retrieved_from_the_customer(): void
    {
        $viejo = $this->deviceInstalledAt($this->serializedStock('RB941'), $this->customer, 'SN-VIEJO');

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $viejo->id,
            'source_type' => 'user',
            'source_id'   => $this->technician->id,
        ])->assertCreated()
          ->assertJsonPath('item.is_return', true)
          // Un retiro no se cobra: sin precio no hay nada que arrastrar al cargo.
          ->assertJsonPath('item.unit_price', null);

        $viejo->refresh();
        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $viejo->status);
        $this->assertSame((int) $this->technician->id, (int) $viejo->user_id);
        $this->assertNull($viejo->customer_id, 'El equipo retirado ya no está en casa del cliente.');

        $movimiento = InventoryMovement::withoutTenantScope()
            ->where('type', InventoryMovement::TYPE_DEVOLUCION)
            ->firstOrFail();

        $this->assertSame(InventoryMovement::HOLDER_CUSTOMER, $movimiento->from_type);
        $this->assertSame((int) $this->ticket->id, (int) $movimiento->support_ticket_id);
    }

    #[Test]
    public function a_device_installed_at_another_customer_cannot_be_retrieved(): void
    {
        $otro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $ajeno = $this->deviceInstalledAt($this->serializedStock(), $otro, 'SN-AJENO');

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $ajeno->id,
            'source_type' => 'branch',
            'source_id'   => $this->branch->id,
        ])->assertStatus(422);

        $ajeno->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $ajeno->status);
        $this->assertSame((int) $otro->id, (int) $ajeno->customer_id);
    }

    #[Test]
    public function the_available_list_offers_what_the_customer_already_has(): void
    {
        $instalado = $this->deviceInstalledAt($this->serializedStock('RB941'), $this->customer, 'SN-VIEJO');
        $otro      = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $ajeno     = $this->deviceInstalledAt($this->serializedStock(), $otro, 'SN-AJENO');

        $respuesta = $this->getJson("/api/support/{$this->ticket->id}/equipment/available")->assertOk();

        $ids = collect($respuesta->json('installed'))->pluck('id')->all();

        $this->assertContains($instalado->id, $ids);
        $this->assertNotContains($ajeno->id, $ids, 'Sólo se retiran equipos del cliente de este ticket.');
    }

    #[Test]
    public function a_technician_cannot_take_another_technicians_device(): void
    {
        $alguien = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $device  = $this->deviceHeldBy($this->serializedStock(), $alguien, 'SN-AJENA');

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertStatus(422);

        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->fresh()->status);
        $this->assertSame(0, TicketEquipment::withoutTenantScope()->count());
    }

    #[Test]
    public function a_technician_cannot_reach_the_warehouse_without_inventory_permission(): void
    {
        $enBodega = $this->deviceHeldBy($this->serializedStock(), null, 'SN-BODEGA');

        Sanctum::actingAs($this->technician);

        $respuesta = $this->getJson("/api/support/{$this->ticket->id}/equipment/available")->assertOk();
        $this->assertNotContains(
            $enBodega->id,
            collect($respuesta->json('devices'))->pluck('id')->all()
        );

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $enBodega->id])
            ->assertStatus(422);
    }

    #[Test]
    public function consumables_are_discounted_by_quantity(): void
    {
        $stock = $this->consumableStock();
        InventoryBalance::create([
            'tenant_id'   => $this->tenant->id,
            'stock_id'    => $stock->id,
            'holder_type' => InventoryMovement::HOLDER_USER,
            'holder_id'   => $this->technician->id,
            'quantity'    => 10,
        ]);

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'stock_id'    => $stock->id,
            'quantity'    => 4,
            'source_type' => 'user',
            'source_id'   => $this->technician->id,
        ])->assertCreated();

        $saldo = InventoryBalance::withoutTenantScope()
            ->where('stock_id', $stock->id)
            ->where('holder_id', $this->technician->id)
            ->firstOrFail();

        $this->assertEquals(6, (float) $saldo->quantity);
    }

    #[Test]
    public function undoing_a_delivery_returns_the_device_to_its_holder(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->technician, 'SN-NUEVO');

        Sanctum::actingAs($this->technician);

        $creado = $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated();

        $itemId = $creado->json('item.id');

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/{$itemId}", [
            'reason' => 'Cargue el router equivocado al ticket.',
        ])->assertOk();

        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->status);
        $this->assertSame((int) $this->technician->id, (int) $device->user_id);
        $this->assertNull($device->customer_id);

        // La linea NO desaparece: sigue en la hoja, marcada, con actor y motivo.
        $linea = TicketEquipment::withoutTenantScope()->findOrFail($itemId);
        $this->assertNotNull($linea->reversed_at);
        $this->assertSame((int) $this->technician->id, (int) $linea->reversed_by);
        $this->assertSame('Cargue el router equivocado al ticket.', $linea->reversal_reason);
        $this->assertSame(1, TicketEquipment::withoutTenantScope()->count());
    }

    #[Test]
    public function undoing_a_retrieval_puts_the_device_back_at_the_customer(): void
    {
        $viejo = $this->deviceInstalledAt($this->serializedStock('RB941'), $this->customer, 'SN-VIEJO');

        Sanctum::actingAs($this->technician);

        $creado = $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $viejo->id,
            'source_type' => 'user',
            'source_id'   => $this->technician->id,
        ])->assertCreated();

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/" . $creado->json('item.id'), [
            'reason' => 'Anote el movimiento en el ticket que no era.',
        ])->assertOk();

        $viejo->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $viejo->status);
        $this->assertSame((int) $this->customer->id, (int) $viejo->customer_id);
    }

    /**
     * El bug que hizo falta destapar para que el retiro sirviera de algo:
     * `installation_equipment.device_id` era ÚNICO, así que un equipo que
     * volvía de casa de un cliente no se podía instalar nunca más en otro. El
     * INSERT de la segunda instalación reventaba y nadie entendía por qué.
     */
    #[Test]
    public function a_retrieved_device_can_be_installed_at_another_customer(): void
    {
        $stock = $this->serializedStock();
        $viejo = $this->deviceInstalledAt($stock, $this->customer, 'SN-REUSO');

        // La instalación por la que llegó a casa del primer cliente.
        $primera = CustomerInstallation::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'completada',
        ]);
        InstallationEquipment::create([
            'tenant_id' => $this->tenant->id, 'installation_id' => $primera->id,
            'stock_id' => $stock->id, 'device_id' => $viejo->id, 'quantity' => 1,
        ]);

        // Se retira en el ticket y vuelve a la bodega.
        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $viejo->id,
            'source_type' => 'branch',
            'source_id'   => $this->branch->id,
        ])->assertCreated();

        // Y se instala en otro cliente, con su propia hoja.
        $otroCliente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $segunda = CustomerInstallation::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $otroCliente->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pendiente',
        ]);

        $this->postJson("/api/installations/{$segunda->id}/equipment", ['device_id' => $viejo->id])
            ->assertCreated();

        $viejo->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $viejo->status);
        $this->assertSame((int) $otroCliente->id, (int) $viejo->customer_id);

        // La hoja vieja sigue contando lo que pasó ese día: el retiro no borra
        // la historia de la visita en la que el equipo se entregó.
        $this->assertSame(
            2,
            InstallationEquipment::withoutTenantScope()->where('device_id', $viejo->id)->count()
        );
    }

    #[Test]
    public function an_archived_ticket_does_not_move_inventory(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-NUEVO');

        $this->ticket->delete();

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertStatus(422);

        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->fresh()->status);

        // Pero el expediente archivado sigue siendo consultable.
        $this->getJson("/api/support/{$this->ticket->id}/equipment")->assertOk();
    }

    /**
     * El router que vuelve quemado no es una devolución: si entrara a bodega
     * como disponible, alguien lo prometería en la siguiente instalación.
     * (Caso recogido de la rama KAN-92, que sí lo contemplaba.)
     */
    #[Test]
    public function a_dead_device_is_retrieved_and_scrapped(): void
    {
        $quemado = $this->deviceInstalledAt($this->serializedStock('RB941'), $this->customer, 'SN-QUEMADO');

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $quemado->id,
            'source_type' => 'scrap',
            'notes'       => 'Le entró el rayo por la línea.',
        ])->assertCreated()
          ->assertJsonPath('item.is_return', true)
          ->assertJsonPath('item.is_scrapped', true);

        $quemado->refresh();
        $this->assertSame(InventoryDevice::STATUS_RETIRED, $quemado->status);
        $this->assertNull($quemado->customer_id);
        $this->assertNull($quemado->user_id, 'Un equipo de baja no queda a nombre de nadie.');

        $movimiento = InventoryMovement::withoutTenantScope()
            ->where('type', InventoryMovement::TYPE_BAJA)
            ->firstOrFail();

        $this->assertSame(InventoryMovement::HOLDER_CUSTOMER, $movimiento->from_type);
        $this->assertSame((int) $this->ticket->id, (int) $movimiento->support_ticket_id);

        // Y deja de estar disponible para cualquier otra visita.
        $disponibles = $this->getJson("/api/support/{$this->ticket->id}/equipment/available")->assertOk();
        $this->assertNotContains(
            $quemado->id,
            collect($disponibles->json('devices'))->pluck('id')->all()
        );
    }

    #[Test]
    public function undoing_a_scrap_puts_the_device_back_at_the_customer(): void
    {
        $quemado = $this->deviceInstalledAt($this->serializedStock('RB941'), $this->customer, 'SN-QUEMADO');

        $creado = $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $quemado->id,
            'source_type' => 'scrap',
        ])->assertCreated();

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/" . $creado->json('item.id'), [
            'reason' => 'Anote el movimiento en el ticket que no era.',
        ])->assertOk();

        $quemado->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $quemado->status);
        $this->assertSame((int) $this->customer->id, (int) $quemado->customer_id);
    }

    #[Test]
    public function scrap_is_not_a_valid_source_for_a_delivery(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-NUEVO');

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'device_id'   => $device->id,
            'source_type' => 'scrap',
        ])->assertStatus(422);

        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->fresh()->status);
    }

    #[Test]
    public function a_device_already_installed_elsewhere_cannot_be_delivered(): void
    {
        $otro   = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $device = $this->deviceInstalledAt($this->serializedStock(), $otro, 'SN-OCUPADO');

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertStatus(422);

        $this->assertSame((int) $otro->id, (int) $device->fresh()->customer_id);
    }

    // ── Permisos ──────────────────────────────────────────────────────────
    //
    // `CheckPermission` tiene semántica OR: `permission:a,b` deja pasar a quien
    // tenga cualquiera de los dos. La primera versión de estas rutas usaba
    // `permission:view_support,ticket_edit`, con lo que `view_support` —un
    // permiso de LECTURA que tiene todo el módulo— bastaba para descontar
    // existencias y cambiar la custodia de un bien.

    #[Test]
    public function view_support_alone_cannot_move_inventory(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-NUEVO');

        Sanctum::actingAs($this->miron);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertForbidden();

        $this->assertSame(0, TicketEquipment::withoutTenantScope()->count());

        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->status);
    }

    #[Test]
    public function view_support_alone_cannot_even_read_the_sheet(): void
    {
        // Mismo permiso para leer y para escribir, a propósito: si la pantalla
        // se abriera con `view_support` mostraría una sección que la API va a
        // rechazar en cuanto el técnico pulse algo.
        Sanctum::actingAs($this->miron);

        $this->getJson("/api/support/{$this->ticket->id}/equipment")->assertForbidden();
        $this->getJson("/api/support/{$this->ticket->id}/equipment/available")->assertForbidden();
    }

    #[Test]
    public function view_support_alone_cannot_reverse_a_line(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-NUEVO');

        $itemId = $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated()->json('item.id');

        Sanctum::actingAs($this->miron);

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/{$itemId}", [
            'reason' => 'Intento deshacerlo sin tener permiso.',
        ])->assertForbidden();

        $this->assertNull(TicketEquipment::withoutTenantScope()->findOrFail($itemId)->reversed_at);
    }

    #[Test]
    public function a_technician_without_ticket_edit_can_still_move_equipment(): void
    {
        // La regla que motivó el permiso propio. El técnico de campo NO tiene
        // `ticket_edit` —la matriz se lo niega— y aun así la § 18 le da
        // «materiales, equipos». Con `ticket_equipment` entra.
        $this->assertFalse($this->technician->hasPermission('ticket_edit'));
        $this->assertTrue($this->technician->hasPermission('ticket_equipment'));

        $device = $this->deviceHeldBy($this->serializedStock(), $this->technician, 'SN-NUEVO');

        Sanctum::actingAs($this->technician);

        $this->getJson("/api/support/{$this->ticket->id}/equipment/available")->assertOk();

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated();
    }

    #[Test]
    public function the_backfill_grants_equipment_to_whoever_already_intervenes(): void
    {
        $conVisita = Role::create([
            'name' => 'Campo', 'code' => 'campo',
            'permissions' => ['view_support', 'ticket_intervene'], 'tenant_id' => $this->tenant->id,
        ]);
        $sinVisita = Role::create([
            'name' => 'Caja', 'code' => 'accounting',
            'permissions' => ['view_support', 'view_billing'], 'tenant_id' => $this->tenant->id,
        ]);
        $portal = Role::create([
            'name' => 'Cliente', 'code' => 'client',
            'permissions' => ['view_own_tickets'], 'tenant_id' => $this->tenant->id,
        ]);
        $comodin = Role::create([
            'name' => 'Dios', 'code' => 'root',
            'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);

        $migracion = require database_path('migrations/2026_09_24_000001_grant_ticket_equipment_to_intervene_roles.php');
        $migracion->up();

        $permisos = fn (Role $r) => Role::withoutGlobalScope('tenant')->findOrFail($r->id)->permissions;

        $this->assertContains('ticket_equipment', $permisos($conVisita));
        // Ni contabilidad ni el portal del cliente mueven aparatos.
        $this->assertNotContains('ticket_equipment', $permisos($sinVisita));
        $this->assertNotContains('ticket_equipment', $permisos($portal));
        // El comodín no se toca: ya lo tiene todo y añadirlo sería ruido.
        $this->assertSame(['*'], $permisos($comodin));

        // Idempotente: correrla dos veces no duplica ni reordena.
        $antes = $permisos($conVisita);
        $migracion->up();
        $this->assertSame($antes, $permisos($conVisita));

        // Y se puede revertir sin llevarse por delante lo que ya estaba.
        $migracion->down();
        $this->assertNotContains('ticket_equipment', $permisos($conVisita));
        $this->assertContains('ticket_intervene', $permisos($conVisita));
    }

    // ── Preservación ──────────────────────────────────────────────────────

    #[Test]
    public function a_ticket_equipment_line_cannot_be_deleted(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-NUEVO');

        $itemId = $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated()->json('item.id');

        $linea = TicketEquipment::withoutTenantScope()->findOrFail($itemId);

        $this->expectException(\RuntimeException::class);
        $linea->delete();
    }

    #[Test]
    public function reversing_requires_a_reason(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-NUEVO');

        $itemId = $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated()->json('item.id');

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/{$itemId}")
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/{$itemId}", ['reason' => 'corto'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        // El inventario no se movió en ninguno de los dos intentos.
        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $device->status);
    }

    #[Test]
    public function a_reversal_is_audited_in_the_ticket_history(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-NUEVO');

        $itemId = $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated()->json('item.id');

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/{$itemId}", [
            'reason' => 'El router que se dejo fue el de la caja de al lado.',
        ])->assertOk();

        $evento = SupportTicketHistory::withoutTenantScope()
            ->where('event_type', SupportTicketHistory::EQUIPMENT_REVERSED)
            ->firstOrFail();

        $this->assertSame('El router que se dejo fue el de la caja de al lado.', $evento->metadata['reason']);
        $this->assertSame(SupportTicketHistory::EQUIPMENT_DELIVERED, $evento->metadata['of_event']);

        // Y el evento original sigue ahí: el expediente cuenta las dos cosas.
        $this->assertTrue(
            SupportTicketHistory::withoutTenantScope()
                ->where('event_type', SupportTicketHistory::EQUIPMENT_DELIVERED)->exists()
        );
    }

    #[Test]
    public function a_reversed_line_is_still_listed(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-NUEVO');

        $itemId = $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated()->json('item.id');

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/{$itemId}", [
            'reason' => 'Se cargo al ticket que no era, se corrige.',
        ])->assertOk();

        // Sigue en la hoja. No hay SoftDeletes justamente para esto.
        $this->getJson("/api/support/{$this->ticket->id}/equipment")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $itemId)
            ->assertJsonPath('0.is_reversed', true)
            ->assertJsonPath('0.reversal_reason', 'Se cargo al ticket que no era, se corrige.');
    }

    #[Test]
    public function a_line_cannot_be_reversed_twice(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-NUEVO');

        $itemId = $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated()->json('item.id');

        $cuerpo = ['reason' => 'Primera correccion del movimiento.'];

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/{$itemId}", $cuerpo)->assertOk();
        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/{$itemId}", $cuerpo)->assertStatus(422);

        // Y el inventario no se movió dos veces.
        $this->assertSame(
            1,
            InventoryMovement::withoutTenantScope()
                ->where('type', InventoryMovement::TYPE_DEVOLUCION)->count()
        );
    }

    #[Test]
    public function an_archived_ticket_cannot_reverse_either(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-NUEVO');

        $itemId = $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated()->json('item.id');

        $this->ticket->delete();

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/{$itemId}", [
            'reason' => 'Intento corregirlo con el expediente archivado.',
        ])->assertStatus(422);

        $this->assertNull(TicketEquipment::withoutTenantScope()->findOrFail($itemId)->reversed_at);
    }

    #[Test]
    public function undoing_a_retrieval_is_refused_if_the_device_moved_on(): void
    {
        // El invariante manda también en el camino de vuelta: si mientras tanto
        // el aparato se instaló en otra casa, reponerlo aquí lo pondría en dos.
        $viejo = $this->deviceInstalledAt($this->serializedStock('RB941'), $this->customer, 'SN-VIEJO');

        $itemId = $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction' => 'in', 'device_id' => $viejo->id,
            'source_type' => 'user', 'source_id' => $this->admin->id,
        ])->assertCreated()->json('item.id');

        $otro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $viejo->refresh();
        $viejo->update(['status' => InventoryDevice::STATUS_INSTALLED, 'customer_id' => $otro->id]);

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/{$itemId}", [
            'reason' => 'Quiero deshacer el retiro aunque ya este en otra casa.',
        ])->assertStatus(422);

        $viejo->refresh();
        $this->assertSame((int) $otro->id, (int) $viejo->customer_id);
    }

    // ── Borrado de un equipo del inventario ───────────────────────────────

    #[Test]
    public function a_device_with_installation_history_can_be_deleted_once_back_in_stock(): void
    {
        // El hallazgo de la auditoría. `InventoryDeviceController::destroy`
        // rechazaba borrar CUALQUIER equipo que tuviera una línea de
        // instalación, y desde que el retiro por ticket conserva esa línea
        // —a propósito— el aparato quedaba imposible de eliminar para siempre,
        // con un mensaje además falso: «está instalado en casa de un cliente».
        $stock  = $this->serializedStock('RB941');
        $device = $this->deviceHeldBy($stock, null, 'SN-HISTORICO');

        $instalacion = CustomerInstallation::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'scheduled_date' => now()->subMonth(),
            // `customer_installations.status` es enum('pendiente','completada',
            // 'cancelada'). SQLite no lo hace cumplir y PostgreSQL si, con un
            // CHECK: un valor en ingles aqui pasa en local y revienta en el CI.
            'status' => 'completada',
        ]);
        InstallationEquipment::create([
            'tenant_id' => $this->tenant->id,
            'installation_id' => $instalacion->id,
            'device_id' => $device->id,
            'stock_id' => $stock->id,
            'quantity' => 1,
        ]);

        // Está en bodega: no hay nada instalado en casa de nadie.
        $this->assertSame(InventoryDevice::STATUS_STOCK, $device->fresh()->status);

        $this->deleteJson("/api/inventory/{$device->id}")->assertOk();

        $this->assertNull(InventoryDevice::withoutTenantScope()->find($device->id));
    }

    #[Test]
    public function a_device_referenced_by_a_ticket_cannot_be_deleted(): void
    {
        // La otra mitad del hallazgo: `ticket_equipment.device_id` es
        // `nullOnDelete`, así que borrar el equipo dejaría la hoja de la visita
        // sin serial y el expediente sin saber qué router se movió.
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-EN-TICKET');

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated();

        // Se retira a bodega: ya no está en casa de nadie, y aun así no se borra.
        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction' => 'in', 'device_id' => $device->id,
            'source_type' => 'branch', 'source_id' => $this->branch->id,
        ])->assertCreated();

        $this->assertSame(InventoryDevice::STATUS_STOCK, $device->fresh()->status);

        $this->deleteJson("/api/inventory/{$device->id}")
            ->assertStatus(422)->assertJsonValidationErrors('device');

        $this->assertNotNull(InventoryDevice::withoutTenantScope()->find($device->id));

        // Y las dos líneas del ticket conservan su serial.
        $this->assertSame(
            2,
            TicketEquipment::withoutTenantScope()->where('device_id', $device->id)->count()
        );
    }

    #[Test]
    public function a_device_installed_at_a_customer_still_cannot_be_deleted(): void
    {
        $device = $this->deviceInstalledAt($this->serializedStock(), $this->customer, 'SN-PUESTO');

        $this->deleteJson("/api/inventory/{$device->id}")
            ->assertStatus(422)->assertJsonValidationErrors('device');

        $this->assertNotNull(InventoryDevice::withoutTenantScope()->find($device->id));
    }

    // ── Aislamiento y contrato ────────────────────────────────────────────

    #[Test]
    public function the_ticket_of_another_tenant_is_not_reachable(): void
    {
        $otroTenant = Tenant::factory()->create();
        $otroRol = Role::create([
            'name' => 'Admin', 'code' => 'admin', 'permissions' => ['*'], 'tenant_id' => $otroTenant->id,
        ]);
        $ajeno = User::factory()->create(['tenant_id' => $otroTenant->id, 'role_id' => $otroRol->id]);

        Sanctum::actingAs($ajeno);

        $this->getJson("/api/support/{$this->ticket->id}/equipment")->assertNotFound();
        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => 1])->assertNotFound();
    }

    #[Test]
    public function a_device_from_another_tenant_cannot_be_delivered(): void
    {
        // Lo sostiene el scope global de `BelongsToTenant` sobre InventoryDevice,
        // pero conviene fijarlo: si alguien le quitara el trait, el `findOrFail`
        // del controlador pasaria a aceptar el id de cualquier empresa.
        $otroTenant = Tenant::factory()->create();

        $stockAjeno = new InventoryStock([
            'brand' => 'TP-LINK', 'model' => 'AJENO', 'price' => 90000, 'is_serialized' => true,
        ]);
        $stockAjeno->tenant_id = $otroTenant->id;
        $stockAjeno->save();

        // OJO: `tenant_id` no es fillable. Pasarlo por `create()` lo descarta en
        // silencio y el hook `creating` estampa el tenant AUTENTICADO, con lo
        // que el equipo acabaria siendo propio y la prueba no probaria nada.
        $deviceAjeno = new InventoryDevice([
            'stock_id'  => $stockAjeno->id,
            'serial'    => 'SN-DE-OTRA-EMPRESA',
            'status'    => InventoryDevice::STATUS_STOCK,
        ]);
        $deviceAjeno->tenant_id = $otroTenant->id;
        $deviceAjeno->save();

        $this->assertNotSame((int) $this->tenant->id, (int) $deviceAjeno->tenant_id);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'device_id' => $deviceAjeno->id,
        ])->assertNotFound();

        $this->assertSame(0, TicketEquipment::withoutTenantScope()->count());

        $deviceAjeno->refresh();
        $this->assertSame(InventoryDevice::STATUS_STOCK, $deviceAjeno->status);
        $this->assertNull($deviceAjeno->customer_id);
    }
}
