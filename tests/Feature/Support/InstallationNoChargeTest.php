<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerInstallation;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La orden de trabajo que NO se le cobra al cliente.
 *
 * EL CASO QUE LA PIDE. Se le quema el router al cliente y el técnico va y se lo
 * cambia. El equipo sale de la bodega —eso no cambia, y sigue siendo gasto de
 * la empresa— pero el cliente no paga nada. Hasta ahora eso dependía de que
 * nadie escribiera un valor en la cartera, ni ese día ni después: no había
 * ninguna marca que dijera «esta visita es gratis».
 *
 * LAS CUATRO REGLAS QUE SE FIJAN AQUÍ
 *
 *  1. La marca se pone al CREAR la orden, que es cuando se sabe si va de
 *     garantía, y sin exigirle permisos de facturación a quien agenda.
 *  2. Con la marca puesta, guardar la cartera NO emite factura. Antes cualquier
 *     guardado creaba una —aunque fuera de $0— y el cliente terminaba con un
 *     documento de cobro por una visita regalada.
 *  3. Una orden sin cobro no puede llevar cifras encima, y las que traiga no se
 *     ponen en cero en silencio: se rechazan con un mensaje.
 *  4. CAMBIAR la marca después sí es una decisión de dinero y exige
 *     `edit_discount`; y no se puede marcar una orden que ya facturó, porque
 *     aquí no se borran facturas — se anulan en Facturación.
 */
class InstallationNoChargeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $cliente;
    private User $cartera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        // `CheckPermission` deja pasar SIEMPRE a `role_id == 1`. Con
        // `RefreshDatabase` el primer rol creado se lleva ese id, así que se
        // quema uno: sin esto los casos negativos serían falsos positivos.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);

        $this->cliente = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->cartera = $this->usuarioCon([
            Permissions::VIEW_SUPPORT,
            Permissions::VIEW_CLIENTS,
            Permissions::ADD_CLIENTS,
            Permissions::EDIT_DISCOUNT,
        ], 'staff');
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(array $permisos, string $code = 'technician'): User
    {
        $rol = Role::create([
            'name' => 'Rol ' . uniqid(), 'code' => $code,
            'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $rol->id,
        ]);
    }

    private function orden(array $extra = []): CustomerInstallation
    {
        return CustomerInstallation::create($extra + [
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => $this->cliente->id,
            'scheduled_date' => now()->toDateString(),
            'address'        => 'Calle 1',
            'status'         => 'pendiente',
        ]);
    }

    #[Test]
    public function la_orden_puede_nacer_marcada_sin_cobro(): void
    {
        $respuesta = $this->actingAs($this->cartera)
            ->postJson("/api/customers/{$this->cliente->id}/installations", [
                'scheduled_date'   => now()->toDateString(),
                'equipment'        => 'Router de reemplazo',
                'no_charge'        => true,
                'no_charge_reason' => 'Garantía: daño por rayo',
            ])
            ->assertCreated();

        $respuesta->assertJsonPath('installation.no_charge', true);
        $respuesta->assertJsonPath('installation.no_charge_reason', 'Garantía: daño por rayo');

        $this->assertTrue(
            (bool) CustomerInstallation::latest('id')->first()->no_charge,
            'La marca tiene que quedar guardada, no sólo devuelta en la respuesta.'
        );
    }

    #[Test]
    public function quien_agenda_no_necesita_permisos_de_cartera_para_marcarla(): void
    {
        // El que abre la orden de mantenimiento es el que atiende el teléfono,
        // no el de facturación. Exigirle `edit_discount` al crear dejaría el
        // botón inservible justo para quien lo va a usar.
        $agenda = $this->usuarioCon([
            Permissions::VIEW_SUPPORT,
            Permissions::VIEW_CLIENTS,
            Permissions::ADD_CLIENTS,
        ], 'staff-sin-cartera');

        $this->actingAs($agenda)
            ->postJson("/api/customers/{$this->cliente->id}/installations", [
                'scheduled_date' => now()->toDateString(),
                'no_charge'      => true,
            ])
            ->assertCreated()
            ->assertJsonPath('installation.no_charge', true);
    }

    #[Test]
    public function una_orden_sin_cobro_no_genera_factura(): void
    {
        $orden = $this->orden(['no_charge' => true]);

        $respuesta = $this->actingAs($this->cartera)
            ->putJson("/api/installations/{$orden->id}/billing", [
                'payment_agreement' => true,
                'payment_notes'     => 'Cambio de router bajo garantía',
            ])
            ->assertOk();

        $respuesta->assertJsonPath('invoice', null);
        $this->assertStringContainsString('sin cobro', (string) $respuesta->json('invoice_warning'));

        $this->assertDatabaseCount('invoices', 0);

        // Lo que NO es dinero sí se guarda: el acuerdo, las notas, la retención.
        $this->assertTrue((bool) $orden->fresh()->payment_agreement);
    }

    #[Test]
    public function una_orden_sin_cobro_no_admite_cifras(): void
    {
        $orden = $this->orden(['no_charge' => true]);

        $this->actingAs($this->cartera)
            ->putJson("/api/installations/{$orden->id}/billing", [
                'installation_cost' => 150000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('no_charge');

        $this->assertNull(
            $orden->fresh()->installation_cost,
            'Un guardado rechazado no puede dejar a medias el valor de la instalación.'
        );
        $this->assertDatabaseCount('invoices', 0);
    }

    #[Test]
    public function el_abono_recibido_tambien_bloquea_la_marca(): void
    {
        // Es el caso delicado: si el cliente YA entregó plata, marcar la visita
        // como gratis no puede hacerla desaparecer de la pantalla. Alguien
        // tiene que decidir qué se hace con ese dinero.
        $orden = $this->orden(['payment_received' => 30000]);

        $this->actingAs($this->cartera)
            ->putJson("/api/installations/{$orden->id}/billing", [
                'no_charge' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('no_charge');

        $this->assertFalse((bool) $orden->fresh()->no_charge);
    }

    #[Test]
    public function no_se_puede_marcar_sin_cobro_una_orden_que_ya_facturo(): void
    {
        $orden = $this->orden();

        $this->actingAs($this->cartera)
            ->putJson("/api/installations/{$orden->id}/billing", [
                'installation_cost' => 120000,
            ])
            ->assertOk();

        $factura = Invoice::where('installation_id', $orden->id)->first();
        $this->assertNotNull($factura, 'La orden cobrable sí tiene que facturar.');

        $this->actingAs($this->cartera)
            ->putJson("/api/installations/{$orden->id}/billing", [
                'no_charge'         => true,
                'installation_cost' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('no_charge');

        $this->assertFalse((bool) $orden->fresh()->no_charge);
        $this->assertDatabaseHas('invoices', ['id' => $factura->id]);
    }

    #[Test]
    public function cambiar_la_marca_de_una_orden_existente_exige_el_permiso_de_cartera(): void
    {
        $orden = $this->orden();

        // `view_support` basta para editar la orden —fecha, técnico, dirección—
        // pero no para decidir que deja de cobrarse.
        $tecnico = $this->usuarioCon([Permissions::VIEW_SUPPORT]);

        $this->actingAs($tecnico)
            ->putJson("/api/customers/installations/{$orden->id}", [
                'scheduled_date' => now()->toDateString(),
                'no_charge'      => true,
            ])
            ->assertForbidden();

        $this->assertFalse((bool) $orden->fresh()->no_charge);
    }

    #[Test]
    public function editar_la_orden_sin_tocar_la_marca_sigue_siendo_posible(): void
    {
        $orden = $this->orden(['no_charge' => true, 'no_charge_reason' => 'Garantía']);
        $tecnico = $this->usuarioCon([Permissions::VIEW_SUPPORT]);

        // El formulario reenvía el objeto entero, marca incluida. Mientras no
        // CAMBIE, el guardado no puede convertirse en un 403 sorpresa.
        $this->actingAs($tecnico)
            ->putJson("/api/customers/installations/{$orden->id}", [
                'scheduled_date'   => now()->toDateString(),
                'address'          => 'Calle 2 corregida',
                'no_charge'        => true,
                'no_charge_reason' => 'Garantía',
            ])
            ->assertOk();

        $this->assertSame('Calle 2 corregida', $orden->fresh()->address);
    }

    #[Test]
    public function el_tecnico_sin_permisos_de_cartera_igual_ve_que_no_se_cobra(): void
    {
        $orden = $this->orden([
            'no_charge'        => true,
            'no_charge_reason' => 'Mantenimiento preventivo',
        ]);

        $tecnico = $this->usuarioCon([Permissions::VIEW_SUPPORT]);

        // Es EL dato que necesita quien está en la casa del cliente: las cifras
        // se le ocultan, pero «no le cobres» tiene que llegarle.
        $this->actingAs($tecnico)
            ->getJson("/api/installations/{$orden->id}")
            ->assertOk()
            ->assertJsonPath('can_view_billing', false)
            ->assertJsonMissingPath('installation_cost')
            ->assertJsonPath('no_charge', true)
            ->assertJsonPath('no_charge_reason', 'Mantenimiento preventivo');
    }
}
