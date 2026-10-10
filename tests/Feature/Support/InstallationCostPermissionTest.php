<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerInstallation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ver el costo de una instalación es un permiso propio, y es sólo de lectura.
 *
 * QUÉ SE ARREGLA
 *
 * El bloque «Información de Cartera» del detalle de la orden —valor, adicionales,
 * descuento, abono y saldo— estaba gobernado por `edit_discount`, etiquetado en
 * la pantalla de roles como «Editar Descuento». Nadie podía adivinar que esa
 * casilla era la que mostraba el valor de la instalación, y el rol Técnico, que
 * no la trae, no veía el apartado ni tenía casilla que marcar para verlo.
 *
 * LO QUE ESTA SUITE FIJA
 *
 * Que `view_installation_cost` abra la lectura y NO la escritura. Un técnico de
 * campo necesita saber cuánto cobrar; cambiar el precio, aplicar un descuento o
 * dar por recibido un dinero es otra potestad, porque guardar la cartera emite
 * o recalcula la factura de instalación.
 */
class InstallationCostPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $cliente;
    private CustomerInstallation $instalacion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        // `CheckPermission` deja pasar SIEMPRE a `role_id == 1`. Con
        // `RefreshDatabase` el primer rol creado se queda ese id, así que se
        // quema uno de entrada: si no, cualquier rol de prueba pasaría el
        // middleware y los tests de permiso serían falsos positivos.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);

        $this->cliente = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->instalacion = CustomerInstallation::create([
            'tenant_id'         => $this->tenant->id,
            'customer_id'       => $this->cliente->id,
            'scheduled_date'    => now(),
            'address'           => 'Calle 1',
            'status'            => 'pendiente',
            'installation_cost' => 150000,
            'payment_received'  => 50000,
        ]);
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(array $permisos, string $code = 'technician'): User
    {
        $rol = Role::create([
            'name' => 'Rol ' . $code, 'code' => $code,
            'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $rol->id,
        ]);
    }

    #[Test]
    public function el_tecnico_sin_el_permiso_no_recibe_el_valor_de_la_instalacion(): void
    {
        $tecnico = $this->usuarioCon([Permissions::VIEW_CLIENTS]);

        $respuesta = $this->actingAs($tecnico)
            ->getJson("/api/installations/{$this->instalacion->id}")
            ->assertOk();

        $respuesta->assertJsonPath('can_view_billing', false);
        $respuesta->assertJsonPath('can_edit_billing', false);
        $respuesta->assertJsonMissingPath('installation_cost');
        $respuesta->assertJsonMissingPath('payment_received');
    }

    #[Test]
    public function con_view_installation_cost_ve_el_valor_de_la_instalacion(): void
    {
        $tecnico = $this->usuarioCon([
            Permissions::VIEW_CLIENTS,
            Permissions::VIEW_INSTALLATION_COST,
        ]);

        $respuesta = $this->actingAs($tecnico)
            ->getJson("/api/installations/{$this->instalacion->id}")
            ->assertOk();

        $respuesta->assertJsonPath('can_view_billing', true);
        $respuesta->assertJsonPath('installation_cost', '150000.00');
        $respuesta->assertJsonPath('payment_received', '50000.00');
    }

    #[Test]
    public function ver_el_costo_no_autoriza_a_cambiarlo(): void
    {
        $tecnico = $this->usuarioCon([
            Permissions::VIEW_CLIENTS,
            Permissions::VIEW_INSTALLATION_COST,
        ]);

        // El frontend ya no le pinta el formulario, pero la puerta que cuenta
        // es ésta: quien llame al endpoint a mano tampoco pasa.
        $this->actingAs($tecnico)
            ->putJson("/api/installations/{$this->instalacion->id}/billing", [
                'installation_cost' => 1,
                'payment_received'  => 999999,
            ])
            ->assertForbidden();

        $this->assertSame(
            '150000.00',
            (string) $this->instalacion->fresh()->installation_cost,
            'Un permiso de lectura no debe poder mover el valor de la instalación.'
        );
    }

    #[Test]
    public function el_permiso_de_lectura_se_anuncia_como_no_editable(): void
    {
        $tecnico = $this->usuarioCon([
            Permissions::VIEW_CLIENTS,
            Permissions::VIEW_INSTALLATION_COST,
        ]);

        // `can_edit_billing` es lo que el detalle usa para decidir si dibuja el
        // formulario y el botón Guardar. Si viniera en true, el técnico vería
        // campos editables que el servidor va a rechazar.
        $this->actingAs($tecnico)
            ->getJson("/api/installations/{$this->instalacion->id}")
            ->assertOk()
            ->assertJsonPath('can_edit_billing', false);
    }

    #[Test]
    public function quien_ya_tenia_edit_discount_sigue_viendo_y_editando(): void
    {
        $staff = $this->usuarioCon([
            Permissions::VIEW_CLIENTS,
            Permissions::EDIT_DISCOUNT,
        ], 'staff');

        $this->actingAs($staff)
            ->getJson("/api/installations/{$this->instalacion->id}")
            ->assertOk()
            ->assertJsonPath('can_view_billing', true)
            ->assertJsonPath('can_edit_billing', true)
            ->assertJsonPath('installation_cost', '150000.00');
    }

    #[Test]
    public function el_listado_del_cliente_reparte_la_cartera_igual_que_el_detalle(): void
    {
        $sinPermiso = $this->usuarioCon([Permissions::VIEW_CLIENTS]);
        $conPermiso = $this->usuarioCon([
            Permissions::VIEW_CLIENTS,
            Permissions::VIEW_INSTALLATION_COST,
        ], 'technician-cartera');

        $this->actingAs($sinPermiso)
            ->getJson("/api/customers/{$this->cliente->id}/installations")
            ->assertOk()
            ->assertJsonPath('0.can_view_billing', false)
            ->assertJsonMissingPath('0.installation_cost');

        $this->actingAs($conPermiso)
            ->getJson("/api/customers/{$this->cliente->id}/installations")
            ->assertOk()
            ->assertJsonPath('0.can_view_billing', true)
            ->assertJsonPath('0.can_edit_billing', false)
            ->assertJsonPath('0.installation_cost', '150000.00');
    }
}
