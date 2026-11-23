<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /support/staff-options`: el personal para los desplegables de soporte.
 *
 * EL CASO QUE LO ORIGINÓ (2026-11-20)
 *
 * Un técnico abría «Registrar intervención» y el desplegable de técnico salía
 * vacío. La lista venía de `/staff`, que exige `view_staff`; el 403 se
 * silenciaba y el técnico (obligatorio) no se podía elegir, así que tampoco
 * podía dejar constancia de qué se hizo. En Crear/Editar, además, sólo
 * aparecían roles llamados EXACTAMENTE «Técnico» (P-56).
 */
class SupportStaffOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        // El primer rol creado se queda con id 1, y `CheckPermission` deja
        // pasar siempre a `role_id == 1`. Se quema aquí para que los casos
        // negativos no entren por el bypass.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(
        array $permisos,
        string $rol = 'Técnico de campo',
        ?Tenant $tenant = null,
        array $extra = [],
    ): User {
        $tenant ??= $this->tenant;

        $role = Role::create([
            'name'        => $rol . ' ' . uniqid(),
            'code'        => 'staff',
            'permissions' => $permisos,
            'tenant_id'   => $tenant->id,
        ]);

        return User::factory()->create($extra + [
            'tenant_id'     => $tenant->id,
            'role_id'       => $role->id,
            'user_name'     => 'Edwin',
            'user_lastname' => 'Rojas',
            'status'        => true,
        ]);
    }

    private function idsDe($response): array
    {
        return collect($response->json('data'))->pluck('id')->sort()->values()->all();
    }

    #[Test]
    public function un_tecnico_sin_view_staff_recibe_la_lista(): void
    {
        $tecnico = $this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_INTERVENE]);

        // Lo que fallaba: la ruta de administración de personal le dice que no.
        $this->actingAs($tecnico)->getJson('/api/staff')->assertForbidden();

        $this->actingAs($tecnico)
            ->getJson('/api/support/staff-options')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tecnico->id)
            ->assertJsonPath('data.0.user_name', 'Edwin')
            ->assertJsonPath('data.0.can_intervene', true);
    }

    #[Test]
    public function tecnico_se_decide_por_capacidad_y_no_por_el_nombre_del_rol(): void
    {
        $quienMira   = $this->usuarioCon([Permissions::TICKET_VIEW], 'Recepción');
        $deCampo     = $this->usuarioCon([Permissions::TICKET_INTERVENE], 'Técnico de campo');
        $cajera      = $this->usuarioCon([Permissions::VIEW_BILLING], 'Técnico');
        // Permiso individual: los permisos efectivos son rol ∪ usuario.
        $porUsuario  = $this->usuarioCon([], 'Auxiliar', null, ['permissions' => [Permissions::TICKET_INTERVENE]]);

        $filas = collect(
            $this->actingAs($quienMira)->getJson('/api/support/staff-options')->assertOk()->json('data')
        )->keyBy('id');

        $this->assertTrue($filas[$deCampo->id]['can_intervene'], 'Un rol «Técnico de campo» con ticket_intervene es técnico.');
        $this->assertFalse($filas[$cajera->id]['can_intervene'], 'Llamarse «Técnico» sin la capacidad no basta.');
        $this->assertTrue($filas[$porUsuario->id]['can_intervene'], 'El permiso individual también cuenta.');
        $this->assertFalse($filas[$quienMira->id]['can_intervene']);
    }

    #[Test]
    public function no_salen_abonados_inactivos_ni_personal_de_otro_isp(): void
    {
        $quienMira = $this->usuarioCon([Permissions::TICKET_VIEW]);

        $abonado = User::factory()->create(['tenant_id' => $this->tenant->id, 'status' => true]);
        CustomerProfile::create([
            'user_id' => $abonado->id, 'name' => 'Cliente', 'last_name' => 'Final', 'status' => true,
        ]);

        $inactivo = $this->usuarioCon([Permissions::TICKET_INTERVENE], 'Técnico', null, ['status' => false]);
        $ajeno    = $this->usuarioCon([Permissions::TICKET_INTERVENE], 'Técnico', Tenant::factory()->create());

        $ids = $this->idsDe($this->actingAs($quienMira)->getJson('/api/support/staff-options')->assertOk());

        $this->assertSame([$quienMira->id], $ids);
        $this->assertNotContains($abonado->id, $ids);
        $this->assertNotContains($inactivo->id, $ids);
        $this->assertNotContains($ajeno->id, $ids);
    }

    #[Test]
    public function no_expone_correo_telefono_ni_permisos(): void
    {
        $quienMira = $this->usuarioCon([Permissions::TICKET_VIEW]);

        $fila = $this->actingAs($quienMira)->getJson('/api/support/staff-options')->assertOk()->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'user_name', 'user_lastname', 'role_name', 'can_intervene'],
            array_keys($fila),
        );
    }

    #[Test]
    public function sin_ticket_view_no_hay_lista(): void
    {
        $sinSoporte = $this->usuarioCon([Permissions::VIEW_BILLING]);

        $this->actingAs($sinSoporte)->getJson('/api/support/staff-options')->assertForbidden();
    }
}
