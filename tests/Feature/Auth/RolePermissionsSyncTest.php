<?php

namespace Tests\Feature\Auth;

use App\Constants\Permissions;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coherencia entre el catálogo de permisos del código y los roles de la base.
 *
 * La autorización lee `role.permissions` (columna JSON sembrada), mientras que
 * `Permissions::getPermissionsByRole()` declara en el código qué le toca a cada
 * rol. Nada reconciliaba ambas fuentes, así que cada permiso nuevo exigía
 * acordarse de escribir una migración de backfill. Olvidarlo no produce ningún
 * error: sólo desaparece una pestaña para todos los administradores.
 */
class RolePermissionsSyncTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function el_catalogo_no_tiene_permisos_duplicados_entre_grupos(): void
    {
        $todos = [];
        foreach (Permissions::getAllPermissions() as $grupo => $permisos) {
            foreach (array_keys($permisos) as $clave) {
                $todos[] = $clave;
            }
        }

        $this->assertSame(
            count($todos),
            count(array_unique($todos)),
            'Hay permisos repetidos en más de un grupo de Permissions::getAllPermissions().'
        );
    }

    #[Test]
    public function el_rol_admin_incluye_todos_los_permisos_del_catalogo(): void
    {
        $catalogo = [];
        foreach (Permissions::getAllPermissions() as $permisos) {
            $catalogo = array_merge($catalogo, array_keys($permisos));
        }

        $faltantes = array_diff($catalogo, Permissions::getPermissionsByRole('admin'));

        $this->assertSame(
            [],
            array_values($faltantes),
            'El rol admin debe incluir todo el catálogo. Faltan: ' . implode(', ', $faltantes)
        );
    }

    #[Test]
    public function todo_permiso_asignado_a_un_rol_existe_en_el_catalogo(): void
    {
        $catalogo = [];
        foreach (Permissions::getAllPermissions() as $permisos) {
            $catalogo = array_merge($catalogo, array_keys($permisos));
        }

        foreach (['admin', 'staff', 'technician', 'accounting', 'client'] as $rol) {
            $desconocidos = array_diff(Permissions::getPermissionsByRole($rol), $catalogo);

            $this->assertSame(
                [],
                array_values($desconocidos),
                "El rol '{$rol}' declara permisos que no existen en el catálogo: "
                    . implode(', ', $desconocidos)
            );
        }
    }

    #[Test]
    public function el_comando_sync_anade_los_permisos_que_falten(): void
    {
        $role = Role::create([
            'name'        => 'Administrador',
            'code'        => 'admin',
            'tenant_id'   => null,
            // Le falta todo salvo uno: simula el escenario de un permiso nuevo
            // que nunca llegó a los roles ya sembrados.
            'permissions' => ['view_clients'],
        ]);

        $this->artisan('permissions:sync')->assertSuccessful();

        $esperados = Permissions::getPermissionsByRole('admin');
        $actuales  = $role->fresh()->permissions;

        foreach ($esperados as $permiso) {
            $this->assertContains($permiso, $actuales, "El sync debió añadir '{$permiso}'.");
        }
    }

    #[Test]
    public function el_comando_sync_nunca_quita_permisos(): void
    {
        // Un tenant puede haber concedido a mano un permiso extra a su rol. Una
        // sincronización no debe deshacer ese trabajo.
        $role = Role::create([
            'name'        => 'Tecnico',
            'code'        => 'technician',
            'tenant_id'   => null,
            'permissions' => array_merge(
                Permissions::getPermissionsByRole('technician'),
                ['view_billing']
            ),
        ]);

        $this->artisan('permissions:sync')->assertSuccessful();

        $this->assertContains(
            'view_billing',
            $role->fresh()->permissions,
            'El sync no debe quitar un permiso concedido a mano.'
        );
    }

    #[Test]
    public function el_comando_sync_no_toca_los_roles_personalizados(): void
    {
        $personalizado = Role::create([
            'name'        => 'Cobrador externo',
            'code'        => null,
            'tenant_id'   => null,
            'permissions' => ['view_billing'],
        ]);

        $this->artisan('permissions:sync')->assertSuccessful();

        $this->assertSame(
            ['view_billing'],
            $personalizado->fresh()->permissions,
            'Un rol sin code canónico es una decisión del tenant: no se toca.'
        );
    }

    #[Test]
    public function el_dry_run_no_escribe_nada(): void
    {
        $role = Role::create([
            'name'        => 'Administrador',
            'code'        => 'admin',
            'tenant_id'   => null,
            'permissions' => ['view_clients'],
        ]);

        $this->artisan('permissions:sync --dry-run')->assertSuccessful();

        $this->assertSame(['view_clients'], $role->fresh()->permissions);
    }
}
