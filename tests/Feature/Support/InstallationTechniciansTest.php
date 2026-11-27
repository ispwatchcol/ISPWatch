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
 * «Si voy a asignar un técnico solo me lista los de rol técnico y no también los
 * administradores» (2026-11-26).
 *
 * El técnico de una instalación salía sólo del código de rol `technician`. Ahora
 * usa la misma regla que el técnico de un ticket: quien tenga «Tickets ·
 * registrar intervenciones técnicas» (`ticket_intervene`, configurable en
 * Roles; los administradores lo tienen), más los de código `technician` de
 * siempre, para que nadie desaparezca de la lista. La lista y el guardado
 * aplican la misma regla.
 */
class InstallationTechniciansTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $secretaria;
    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        // `CheckPermission` deja pasar SIEMPRE a `role_id == 1`: se quema ese id.
        Role::create(['name' => 'Superadmin global', 'code' => 'superadmin', 'permissions' => ['*'], 'tenant_id' => null]);

        $this->secretaria = $this->persona('Sara', 'Secretaria', 'staff', [Permissions::VIEW_SUPPORT, Permissions::ADD_CLIENTS]);

        $this->cliente = User::factory()->create(['tenant_id' => $this->tenant->id, 'status' => true]);
        CustomerProfile::create(['user_id' => $this->cliente->id, 'name' => 'Cliente', 'last_name' => 'Final', 'status' => true]);
    }

    /** @param array<int, string> $permisos */
    private function persona(string $nombre, string $apellido, ?string $code, array $permisos, ?Tenant $tenant = null, bool $activo = true): User
    {
        $tenant ??= $this->tenant;

        $rol = Role::create([
            'name' => "Rol {$nombre}", 'code' => $code,
            'permissions' => $permisos, 'tenant_id' => $tenant->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $tenant->id, 'role_id' => $rol->id, 'status' => $activo,
            'user_name' => $nombre, 'user_lastname' => $apellido,
        ]);
    }

    /** @return array<int, string> */
    private function listados(): array
    {
        return collect($this->actingAs($this->secretaria)->getJson('/api/installations/technicians')->assertOk()->json())
            ->pluck('name')->sort()->values()->all();
    }

    #[Test]
    public function lista_tecnicos_administradores_y_a_quien_tenga_el_permiso(): void
    {
        $this->persona('Tomás', 'Técnico', 'technician', []);                       // de siempre, aun sin el permiso
        $this->persona('Ana', 'Admin', 'admin', [Permissions::VIEW_SUPPORT, Permissions::TICKET_INTERVENE]);
        $this->persona('Sergio', 'Supervisor', null, [Permissions::TICKET_INTERVENE]); // rol propio con la casilla
        $this->persona('Carla', 'Contable', 'accounting', [Permissions::VIEW_BILLING]);
        $this->persona('Iván', 'Inactivo', 'admin', [Permissions::TICKET_INTERVENE], activo: false);
        $this->persona('Otro', 'Ajeno', 'technician', [], Tenant::factory()->create());

        // Un abonado no es personal, aunque su rol diga técnico.
        $abonado = $this->persona('Abonado', 'Técnico', 'technician', []);
        CustomerProfile::create(['user_id' => $abonado->id, 'name' => 'Abonado', 'last_name' => 'X', 'status' => true]);

        $this->assertSame(['Ana Admin', 'Sergio Supervisor', 'Tomás Técnico'], $this->listados());
    }

    #[Test]
    public function la_lista_dice_el_rol_de_cada_uno(): void
    {
        $this->persona('Ana', 'Admin', 'admin', [Permissions::TICKET_INTERVENE]);

        $this->actingAs($this->secretaria)->getJson('/api/installations/technicians')
            ->assertOk()
            ->assertJsonPath('0.role_name', 'Rol Ana');
    }

    #[Test]
    public function se_puede_asignar_un_administrador_y_no_a_quien_no_hace_trabajo_tecnico(): void
    {
        $admin    = $this->persona('Ana', 'Admin', 'admin', [Permissions::VIEW_SUPPORT, Permissions::TICKET_INTERVENE]);
        $contable = $this->persona('Carla', 'Contable', 'accounting', [Permissions::VIEW_BILLING]);
        $ajeno    = $this->persona('Otro', 'Ajeno', 'technician', [], Tenant::factory()->create());

        $orden = fn (int $tecnico) => $this->actingAs($this->secretaria)
            ->postJson("/api/customers/{$this->cliente->id}/installations", [
                'scheduled_date' => now()->toDateString(),
                'technician_id'  => $tecnico,
            ]);

        $orden($admin->id)->assertCreated()->assertJsonPath('installation.technician_id', $admin->id);
        $orden($contable->id)->assertStatus(422);
        $orden($ajeno->id)->assertStatus(422);
    }
}
