<?php

namespace Tests\Feature\Inventory;

use App\Constants\Permissions;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryProvider;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Borrar del inventario exige permiso propio. KAN-99.
 *
 * QUÉ SE CIERRA
 *
 * Los cuatro `destroy` del grupo de inventario —equipos, stock, proveedores y
 * sucursales— estaban protegidos con `view_inventory`, un permiso de LECTURA.
 * Cualquier rol que pudiera abrir la pantalla podía vaciarla.
 *
 * El defecto era preexistente y hasta KAN-98 inalcanzable: ninguna pantalla
 * exponía el borrado de equipos. Ese PR añadió el botón Eliminar en la tarjeta
 * de equipo, y con él borrar pasó a estar a un clic de cualquiera con
 * `view_inventory` — el rol `Staff` lo trae de fábrica.
 *
 * Es el mismo patrón corregido ya dos veces en este producto: tickets
 * (2026-08-27) y clientes (2026-08-31, `delete_customers`).
 *
 * POR QUÉ SE CREAN LAS FILAS DE VERDAD
 *
 * Las cuatro rutas usan vinculación implícita de modelo. En el grupo `api`,
 * `SubstituteBindings` corre ANTES del middleware de permiso, así que con un id
 * inexistente la respuesta sería 404 y no se llegaría a comprobar nada. Un test
 * que pasara con 404 sería un falso positivo.
 */
class InventoryDeletionPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        // `CheckPermission` deja pasar SIEMPRE a `role_id == 1` (superadmin
        // global). Con `RefreshDatabase` el primer rol creado se lleva ese id,
        // así que se quema uno de entrada: si no, cualquier rol de prueba
        // pasaría el middleware y estos tests serían falsos positivos.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(array $permisos, string $code = 'staff'): User
    {
        $rol = Role::create([
            'name' => 'Rol ' . $code, 'code' => $code,
            'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $rol->id,
        ]);
    }

    /** Quien sí puede borrar: rol administrativo con el permiso dedicado. */
    private function administrador(): User
    {
        return $this->usuarioCon([
            Permissions::VIEW_INVENTORY,
            Permissions::DELETE_INVENTORY,
        ], 'admin');
    }

    /**
     * Crea una fila de cada tipo y devuelve la ruta de borrado de cada una.
     *
     * Se crean con el tenant puesto a mano porque fuera de una sesión el trait
     * `BelongsToTenant` no tiene de dónde deducirlo.
     *
     * @return array<string, string>
     */
    private function rutas(): array
    {
        $stock = new InventoryStock([
            'brand' => 'MIKROTIK', 'model' => 'LDF', 'price' => 150000, 'is_serialized' => true,
        ]);
        $stock->tenant_id = $this->tenant->id;
        $stock->save();

        $proveedor = new InventoryProvider(['name' => 'Proveedor de prueba']);
        $proveedor->tenant_id = $this->tenant->id;
        $proveedor->save();

        $sucursal = new InventoryBranch(['name' => 'Sucursal centro', 'numero' => '3001234567']);
        $sucursal->tenant_id = $this->tenant->id;
        $sucursal->save();

        $equipo = new InventoryDevice([
            'stock_id' => $stock->id, 'serial' => 'SN-KAN99', 'mac' => 'AA:BB:CC:DD:EE:99',
        ]);
        $equipo->tenant_id = $this->tenant->id;
        $equipo->save();

        return [
            'equipo'    => "/api/inventory/{$equipo->id}",
            'stock'     => "/api/inventory-stock/{$stock->id}",
            'proveedor' => "/api/inventory-providers/{$proveedor->id}",
            'sucursal'  => "/api/inventory-branches/{$sucursal->id}",
        ];
    }

    /** @return array<string, array{string}> */
    public static function recursos(): array
    {
        return [
            'equipo'    => ['equipo'],
            'stock'     => ['stock'],
            'proveedor' => ['proveedor'],
            'sucursal'  => ['sucursal'],
        ];
    }

    // ── Quién ya no puede ────────────────────────────────────────────────

    #[Test]
    #[DataProvider('recursos')]
    public function un_rol_con_view_inventory_ya_no_puede_borrar(string $recurso): void
    {
        $ruta = $this->rutas()[$recurso];

        $this->actingAs($this->usuarioCon([Permissions::VIEW_INVENTORY]))
            ->deleteJson($ruta)
            ->assertForbidden();
    }

    #[Test]
    #[DataProvider('recursos')]
    public function un_rol_sin_permisos_tampoco(string $recurso): void
    {
        $ruta = $this->rutas()[$recurso];

        $this->actingAs($this->usuarioCon([]))
            ->deleteJson($ruta)
            ->assertForbidden();
    }

    #[Test]
    #[DataProvider('recursos')]
    public function sin_sesion_tampoco(string $recurso): void
    {
        $this->deleteJson($this->rutas()[$recurso])->assertUnauthorized();
    }

    /**
     * El 403 no debe llegar después de haber borrado.
     *
     * Es la prueba que importa: un middleware mal colocado puede rechazar la
     * respuesta con la fila ya destruida.
     */
    #[Test]
    public function el_equipo_sigue_ahi_despues_del_403(): void
    {
        $ruta = $this->rutas()['equipo'];

        $this->actingAs($this->usuarioCon([Permissions::VIEW_INVENTORY]))
            ->deleteJson($ruta)
            ->assertForbidden();

        $this->assertSame(
            1,
            InventoryDevice::withoutGlobalScopes()->where('serial', 'SN-KAN99')->count(),
            'El equipo no puede haberse borrado en una petición rechazada.',
        );
    }

    // ── Quién sí puede ───────────────────────────────────────────────────

    #[Test]
    #[DataProvider('recursos')]
    public function el_administrador_con_el_permiso_si_puede(string $recurso): void
    {
        $ruta = $this->rutas()[$recurso];

        $this->actingAs($this->administrador())
            ->deleteJson($ruta)
            ->assertSuccessful();
    }

    // ── Que no se haya roto lo que sí debía seguir abierto ───────────────

    /**
     * `view_inventory` conserva ver, crear y editar.
     *
     * Este PR retira UNA capacidad. Si de paso hubiera cerrado el alta o la
     * edición, el inventario quedaría inservible para el personal de campo.
     */
    #[Test]
    public function view_inventory_sigue_permitiendo_ver_crear_y_editar(): void
    {
        $usuario = $this->usuarioCon([Permissions::VIEW_INVENTORY]);

        $this->actingAs($usuario)->getJson('/api/inventory')->assertOk();
        $this->actingAs($usuario)->getJson('/api/inventory-stock')->assertOk();
        $this->actingAs($usuario)->getJson('/api/inventory-providers')->assertOk();
        $this->actingAs($usuario)->getJson('/api/inventory-branches')->assertOk();

        $this->actingAs($usuario)
            ->postJson('/api/inventory-branches', ['name' => 'Sucursal norte', 'numero' => '3009876543'])
            ->assertSuccessful();
    }

    // ── Relleno de roles existentes ──────────────────────────────────────

    /**
     * Un permiso nuevo no llega solo a los roles ya sembrados: el frontend lee
     * `role.permissions` de la base y no hay bypass de superadministrador. Sin
     * este relleno los administradores se quedarían sin el botón. Ya pasó con
     * `manage_document_templates`.
     */
    #[Test]
    public function la_migracion_concede_el_permiso_solo_a_roles_admin(): void
    {
        $codigos = ['admin', 'staff', 'technician', 'accounting'];
        $roles = [];

        foreach ($codigos as $code) {
            $roles[$code] = Role::create([
                'name' => "Rol {$code}", 'code' => $code,
                'permissions' => [Permissions::VIEW_INVENTORY],
                'tenant_id' => $this->tenant->id,
            ]);
        }

        $migracion = require database_path(
            'migrations/2026_09_11_000003_grant_delete_inventory_to_admin_roles.php'
        );
        $migracion->up();

        foreach ($codigos as $code) {
            $permisos = Role::withoutGlobalScopes()->find($roles[$code]->id)->permissions;
            $tiene = in_array(Permissions::DELETE_INVENTORY, $permisos, true);

            $code === 'admin'
                ? $this->assertTrue($tiene, 'El rol admin debe recibir el permiso.')
                : $this->assertFalse($tiene, "El rol {$code} NO debe recibirlo.");
        }
    }

    /** Revertir deja los roles como estaban, sin arrastrar `view_inventory`. */
    #[Test]
    public function revertir_la_migracion_retira_solo_lo_que_agrego(): void
    {
        $rol = Role::create([
            'name' => 'Rol admin', 'code' => 'admin',
            'permissions' => [Permissions::VIEW_INVENTORY],
            'tenant_id' => $this->tenant->id,
        ]);

        $migracion = require database_path(
            'migrations/2026_09_11_000003_grant_delete_inventory_to_admin_roles.php'
        );
        $migracion->up();
        $migracion->down();

        $permisos = Role::withoutGlobalScopes()->find($rol->id)->permissions;

        $this->assertNotContains(Permissions::DELETE_INVENTORY, $permisos);
        $this->assertContains(Permissions::VIEW_INVENTORY, $permisos);
    }

    /** Un rol con comodín ya lo tiene todo: la migración no debe ensuciarlo. */
    #[Test]
    public function un_rol_con_comodin_se_deja_intacto(): void
    {
        $rol = Role::create([
            'name' => 'Rol admin', 'code' => 'admin',
            'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);

        $migracion = require database_path(
            'migrations/2026_09_11_000003_grant_delete_inventory_to_admin_roles.php'
        );
        $migracion->up();

        $this->assertSame(
            ['*'],
            Role::withoutGlobalScopes()->find($rol->id)->permissions,
            'El comodín ya autoriza el borrado; añadir el permiso sería ruido.',
        );
    }

    // ── Interfaz ─────────────────────────────────────────────────────────

    /**
     * Los ocho botones de borrado —tabla y tarjeta móvil de las cuatro
     * pantallas— deben pedir el permiso nuevo. Si uno se queda con
     * `view_inventory`, el usuario ve un botón que la API le va a rechazar.
     */
    #[Test]
    public function las_cuatro_pantallas_esconden_el_boton_sin_el_permiso(): void
    {
        $pantallas = [
            'Inventory.vue'    => 'deleteDevice(device)',
            'StockList.vue'    => 'confirmDelete(item)',
            'ProviderList.vue' => 'confirmDelete(item)',
            'BranchList.vue'   => 'confirmDelete(item)',
        ];

        foreach ($pantallas as $archivo => $handler) {
            $fuente = file_get_contents(resource_path("js/pages/{$archivo}"));

            $this->assertSame(
                2,
                substr_count($fuente, "can('delete_inventory')"),
                "{$archivo}: la tabla y la tarjeta móvil deben usar el permiso dedicado.",
            );
            $this->assertSame(
                substr_count($fuente, $handler),
                substr_count($fuente, "can('delete_inventory')"),
                "{$archivo}: no puede quedar ninguna llamada a {$handler} sin ese permiso.",
            );
        }
    }
}
