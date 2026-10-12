<?php

namespace Tests\Feature\Support;

use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryStock;
use App\Models\Prospect;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Templates\PlaceholderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La hoja de instalación ya no lleva campos de equipo a mano (§ 114).
 *
 * Marca, modelo y MAC del módem, serial ONU, antena y «otros insumos no
 * inventariados» repetían a mano lo que ya dice la línea del equipo
 * descargado del inventario —y podían contradecirla—. Decisión de operación:
 * todo lo que se instala va anclado al inventario. Señal y observaciones no son
 * inventario y se siguen guardando.
 *
 * LO QUE SE FIJA AQUÍ
 *
 *  1. El servidor ignora esos campos si llegan (la pantalla vieja en caché, un
 *     cliente de la API) y guarda señal y observaciones.
 *  2. Lo que una orden anterior tenía guardado no se borra: es histórico y la
 *     hoja en PDF lo sigue imprimiendo.
 *  3. La MAC que el alta del cliente toma al convertir el prospecto sale del
 *     equipo instalado, no de la casilla retirada.
 *  4. El acta (`{{instalacion.equipos}}`) lleva la MAC del equipo, que era lo
 *     único que la casilla manual aportaba.
 */
class InstallationSheetInventoryOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $customer;
    private InventoryBranch $bodega;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $rol = Role::create([
            'name' => 'Admin', 'code' => 'admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rol->id]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $this->customer->id, 'name' => 'Ana', 'last_name' => 'Ruiz', 'status' => true,
        ]);

        Sanctum::actingAs($this->admin);

        $this->bodega = InventoryBranch::create(['name' => 'Bodega Principal']);
    }

    private function installation(array $extra = []): CustomerInstallation
    {
        return CustomerInstallation::create($extra + [
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => $this->customer->id,
            'scheduled_date' => now()->toDateString(),
            'status'         => 'pendiente',
        ]);
    }

    /** Descarga a la orden una ONU con serial y MAC, por la API. */
    private function installDevice(CustomerInstallation $installation, string $serial, ?string $mac): InventoryDevice
    {
        $stock = InventoryStock::create(['brand' => 'HUAWEI', 'model' => 'HG8145', 'price' => 120000, 'is_serialized' => true]);
        $unit  = InventoryDevice::create([
            'stock_id' => $stock->id, 'serial' => $serial, 'mac' => $mac,
            'branch_id' => $this->bodega->id, 'status' => InventoryDevice::STATUS_STOCK,
        ]);

        $this->postJson("/api/installations/{$installation->id}/equipment", [
            'device_id' => $unit->id, 'client_request_id' => 'k-' . $serial,
        ])->assertCreated();

        return $unit;
    }

    #[Test]
    public function la_hoja_ignora_los_campos_de_equipo_y_guarda_senal_y_observaciones(): void
    {
        $installation = $this->installation();

        $this->putJson("/api/installations/{$installation->id}/sheet", [
            'sheet' => [
                'modem_brand'   => 'TP-Link',
                'modem_model'   => 'Archer',
                'modem_mac'     => '11:22:33:44:55:66',
                'onu_serial'    => 'ONU-MANUAL',
                'antenna_model' => 'LiteBeam',
                'materials'     => 'silicona',
                'signal_level'  => '-21 dBm',
                'observations'  => 'Poste a 25 m',
            ],
        ])->assertOk();

        $sheet = $installation->fresh()->sheet;

        foreach (['modem_brand', 'modem_model', 'modem_mac', 'onu_serial', 'antenna_model', 'materials'] as $campo) {
            $this->assertArrayNotHasKey($campo, $sheet, "«{$campo}» ya no se escribe: sale del inventario.");
        }

        $this->assertSame('-21 dBm', $sheet['signal_level']);
        $this->assertSame('Poste a 25 m', $sheet['observations']);
    }

    #[Test]
    public function lo_digitado_en_una_orden_anterior_se_conserva(): void
    {
        $installation = $this->installation();
        $installation->update(['sheet' => [
            'modem_brand' => 'TP-Link', 'modem_mac' => 'AA:AA:AA:AA:AA:AA', 'materials' => 'silicona',
        ]]);

        // La pantalla nueva ya no los manda; la vieja los manda distintos.
        $this->putJson("/api/installations/{$installation->id}/sheet", [
            'sheet' => ['signal_level' => '-20 dBm'],
        ])->assertOk();

        $this->putJson("/api/installations/{$installation->id}/sheet", [
            'sheet' => ['modem_mac' => 'BB:BB:BB:BB:BB:BB', 'signal_level' => '-19 dBm'],
        ])->assertOk();

        $sheet = $installation->fresh()->sheet;
        $this->assertSame('TP-Link', $sheet['modem_brand']);
        $this->assertSame('AA:AA:AA:AA:AA:AA', $sheet['modem_mac'], 'El histórico no se reescribe.');
        $this->assertSame('silicona', $sheet['materials']);
        $this->assertSame('-19 dBm', $sheet['signal_level']);
    }

    #[Test]
    public function la_vista_previa_tampoco_acepta_los_campos_retirados(): void
    {
        $installation = $this->installation();

        // Sin datos del borrador que pasar, el PDF sale con la hoja guardada.
        $this->postJson("/api/installations/{$installation->id}/sheet-preview", [
            'sheet' => ['modem_brand' => 'TP-Link', 'signal_level' => '-21 dBm'],
        ])->assertOk();

        $this->assertNull($installation->fresh()->sheet, 'La vista previa no persiste nada.');
    }

    #[Test]
    public function el_prospecto_trae_la_mac_del_equipo_instalado(): void
    {
        $prospect = Prospect::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Pedro', 'last_name' => 'Gómez', 'status' => 'agendado',
        ]);
        $installation = $this->installation(['customer_id' => null, 'prospect_id' => $prospect->id]);

        // Un equipo sin MAC primero: se toma el primero que SÍ la tenga.
        $this->installDevice($installation, 'SIN-MAC-01', null);
        $this->installDevice($installation, 'ONU-01', 'AC:DE:48:00:11:22');

        $this->getJson("/api/prospects/{$prospect->id}")
            ->assertOk()
            ->assertJsonPath('installations.0.installed_device_mac', 'AC:DE:48:00:11:22');
    }

    #[Test]
    public function la_hoja_en_pdf_imprime_la_mac_del_equipo_y_no_un_titulo_vacio(): void
    {
        $installation = $this->installation(['sheet' => ['router_id' => null, 'signal_level' => '']]);
        $this->installDevice($installation, 'ONU-01', 'AC:DE:48:00:11:22');

        $html = view('documents.installation_sheet_pdf', [
            'installation'         => $installation->fresh(['equipmentItems.stock', 'equipmentItems.device.stock']),
            'customer'             => $this->customer,
            'profile'              => $this->customer->customerProfile,
            'prospect'             => null,
            'tenant'               => $this->tenant,
            'technician'           => null,
            'sectorial'            => null,
            'router'               => null,
            'plan'                 => null,
            'customer_signature'   => '',
            'technician_signature' => null,
            'date'                 => now()->toDateString(),
        ])->render();

        $this->assertStringContainsString('S/N ONU-01 · MAC AC:DE:48:00:11:22', $html);
        // Sólo datos de red en la hoja: antes salía el título «Hoja Técnica»
        // con la tabla vacía.
        $this->assertStringNotContainsString('Hoja Técnica', $html);
    }

    #[Test]
    public function el_acta_lista_el_equipo_con_su_serial_y_su_mac(): void
    {
        $installation = $this->installation();
        $this->installDevice($installation, 'ONU-01', 'AC:DE:48:00:11:22');

        $valores = app(PlaceholderResolver::class)->forInstallation(
            $installation->fresh(),
            $this->customer,
            $this->customer->customerProfile,
            null,
            $this->tenant,
            null,
            now()->toDateString(),
        );

        $this->assertSame('HUAWEI HG8145 · S/N ONU-01 · MAC AC:DE:48:00:11:22', $valores['instalacion.equipos']);
    }
}
