<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Autorización por endpoint.
 *
 * Contexto: hasta 2026-07-30 la mayoría de los apiResource sólo exigían
 * `auth:sanctum`. El control de acceso real lo imponía la guarda de vue-router,
 * que es cosmética: cualquier usuario autenticado —incluido un rol "Cliente" con
 * la lista de permisos vacía— podía crear, modificar o borrar clientes, routers
 * y planes llamando la API directamente.
 *
 * Estos tests fijan ese contrato para que no se pierda en una refactorización:
 * un usuario SIN permisos recibe 403 en cada endpoint protegido, y un usuario
 * CON el permiso correspondiente pasa el control (deja de recibir 403).
 */
class ApiAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Role $superadminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'ISP Test', 'domain' => 'isp-test']);

        // Se crea PRIMERO para que ocupe el id 1, que es el que CheckPermission
        // trata como superadministrador. Así cualquier rol que creen los tests
        // después tiene id >= 2 y no hereda el bypass sin querer.
        $this->superadminRole = Role::create([
            'name'        => 'Administrador',
            'code'        => 'admin',
            'tenant_id'   => null,
            'permissions' => [],
        ]);
    }

    /**
     * Crea un usuario con un rol propio y la lista de permisos indicada.
     *
     * El rol NUNCA es el id 1: ese tiene bypass de superadministrador en
     * CheckPermission y haría pasar cualquier comprobación, ocultando el fallo
     * que estos tests buscan.
     */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create([
            'name'        => 'Rol ' . uniqid(),
            'code'        => 'custom',
            'tenant_id'   => $this->tenant->id,
            'permissions' => $permissions,
        ]);

        $this->assertNotSame(1, (int) $role->id, 'El rol de prueba no debe ser el superadministrador.');

        return User::create([
            'name'              => 'Test User',
            'user_name'         => 'Test',
            'user_lastname'     => 'User',
            'email'             => 'user' . uniqid() . '@example.com',
            'email_tenant'      => 'user' . uniqid() . '@isp-test',
            'password'          => bcrypt('secret123'),
            'role_id'           => $role->id,
            'tenant_id'         => $this->tenant->id,
            'status'            => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Endpoints protegidos: [método, ruta, permiso que los abre].
     *
     * Se evitan a propósito las rutas cuyo controlador usa vinculación implícita
     * de modelo (p. ej. RouterController::destroy(Router $router)): en el grupo
     * `api`, SubstituteBindings corre ANTES del middleware de la ruta, así que
     * un id inexistente devuelve 404 y nunca se llega a comprobar el permiso.
     * Ese caso se cubre aparte, creando el registro de verdad.
     */
    public static function protectedEndpoints(): array
    {
        return [
            'listar clientes'      => ['get',    '/api/customers',   'view_clients'],
            'crear cliente'        => ['post',   '/api/customers',   'add_clients'],
            'borrar cliente'       => ['delete', '/api/customers/1', 'edit_internet_service'],
            'listar routers'       => ['get',    '/api/routers',     'manage_routers'],
            'crear router'         => ['post',   '/api/routers',     'manage_routers'],
            'listar planes'        => ['get',    '/api/plans',       'view_plans'],
            'crear plan'           => ['post',   '/api/plans',       'view_plans'],
            'listar sectoriales'   => ['get',    '/api/sectorials',  'view_sectorials'],
            'crear sectorial'      => ['post',   '/api/sectorials',  'view_sectorials'],
            'listar inventario'    => ['get',    '/api/inventory',   'view_inventory'],
            'crear equipo'         => ['post',   '/api/inventory',   'view_inventory'],
            'listar tickets'       => ['get',    '/api/support',     'view_support'],
            'listar instalaciones' => ['get',    '/api/installations', 'view_support'],
            'listar prospectos'    => ['get',    '/api/prospects',   'view_support'],
            'listar facturas'      => ['get',    '/api/billing/invoices', 'view_billing'],
            'listar gastos'        => ['get',    '/api/expenses',    'view_expenses'],
            'listar personal'      => ['get',    '/api/staff',       'view_staff'],
        ];
    }

    /**
     * Endpoints que además del permiso de ruta exigen `users.is_superadmin` por
     * una comprobación DENTRO del controlador. Sólo se verifica la dirección
     * "sin permisos → 403": tener el permiso de ruta no basta para abrirlos.
     */
    public static function superadminOnlyEndpoints(): array
    {
        return [
            'crear artículo de ayuda'  => ['post', '/api/help-center/articles'],
            'crear categoría de ayuda' => ['post', '/api/help-center/categories'],
        ];
    }

    #[Test]
    #[DataProvider('protectedEndpoints')]
    public function un_usuario_sin_permisos_recibe_403(string $method, string $uri, string $permission): void
    {
        $user = $this->userWithPermissions([]);

        $this->actingAs($user)
            ->{$method}($uri, [])
            ->assertStatus(403);
    }

    #[Test]
    #[DataProvider('protectedEndpoints')]
    public function el_permiso_correspondiente_abre_el_endpoint(string $method, string $uri, string $permission): void
    {
        $user = $this->userWithPermissions([$permission]);

        $response = $this->actingAs($user)->{$method}($uri, []);

        // No se comprueba 200: muchos de estos endpoints fallarán por validación
        // (422) o por no encontrar el recurso (404) con un cuerpo vacío. Lo que
        // se está fijando es que la AUTORIZACIÓN deja pasar.
        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            "El permiso '{$permission}' debería abrir {$method} {$uri}."
        );
    }

    #[Test]
    #[DataProvider('superadminOnlyEndpoints')]
    public function el_centro_de_ayuda_exige_superadministrador(string $method, string $uri): void
    {
        // HelpCenterController::requireSuperadmin() comprueba users.is_superadmin
        // por su cuenta. El middleware view_settings que se añadió en la ruta es
        // una segunda capa, no la única: tener view_settings NO abre el endpoint.
        $conPermisoDeRuta = $this->userWithPermissions(['view_settings']);

        $this->actingAs($conPermisoDeRuta)->{$method}($uri, [])->assertStatus(403);

        $sinNada = $this->userWithPermissions([]);
        $this->actingAs($sinNada)->{$method}($uri, [])->assertStatus(403);
    }

    #[Test]
    public function borrar_un_router_existente_sin_permiso_devuelve_403(): void
    {
        // Ruta con vinculación implícita de modelo: hace falta que el router
        // exista para que la petición llegue al middleware de permisos.
        $router = \App\Models\Router::create([
            'name'      => 'Router de prueba',
            'tenant_id' => $this->tenant->id,
            'status'    => 'active',
        ]);

        $sinPermiso = $this->userWithPermissions(['view_clients']);

        $this->actingAs($sinPermiso)
            ->delete("/api/routers/{$router->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('router', ['id' => $router->id]);
    }

    #[Test]
    public function el_superadministrador_pasa_sin_permisos_declarados(): void
    {
        // role_id == 1 es el bypass de superadministrador de CheckPermission:
        // pasa aunque su lista de permisos esté vacía.
        $role = $this->superadminRole;
        $this->assertSame(1, (int) $role->id, 'El rol de superadministrador debe tener id 1.');

        $admin = User::create([
            'name'              => 'Super Admin',
            'user_name'         => 'Super',
            'user_lastname'     => 'Admin',
            'email'             => 'admin@example.com',
            'email_tenant'      => 'admin@isp-test',
            'password'          => bcrypt('secret123'),
            'role_id'           => $role->id,
            'tenant_id'         => $this->tenant->id,
            'status'            => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)->get('/api/customers')->assertStatus(200);
    }

    #[Test]
    public function los_permisos_propios_del_usuario_se_suman_a_los_del_rol(): void
    {
        // users.permissions existía y la interfaz permitía asignarlos, pero nada
        // los leía: la autorización sólo miraba role.permissions. Conceder un
        // permiso individual no tenía ningún efecto NI ningún aviso.
        $user = $this->userWithPermissions([]);
        $user->update(['permissions' => ['view_clients']]);

        $this->actingAs($user->fresh())->get('/api/customers')->assertStatus(200);
    }

    #[Test]
    public function la_semantica_or_deja_pasar_con_cualquiera_de_los_permisos(): void
    {
        // El formulario de alta de cliente carga planes, sectoriales y routers.
        // El rol Técnico tiene add_clients pero NO view_plans / view_sectorials /
        // manage_routers: sin la semántica OR, el formulario quedaría con los
        // desplegables vacíos.
        $tecnico = $this->userWithPermissions(['add_clients']);

        $this->actingAs($tecnico)->get('/api/plans')->assertStatus(200);
        $this->actingAs($tecnico)->get('/api/sectorials')->assertStatus(200);
        $this->actingAs($tecnico)->get('/api/routers')->assertStatus(200);
    }

    #[Test]
    public function la_escritura_sigue_exigiendo_el_permiso_dueno_del_modulo(): void
    {
        // El mismo técnico del test anterior NO debe poder crear ni borrar
        // infraestructura: la apertura por OR es sólo de lectura.
        $tecnico = $this->userWithPermissions(['add_clients']);

        $this->actingAs($tecnico)->post('/api/plans', [])->assertStatus(403);
        $this->actingAs($tecnico)->post('/api/sectorials', [])->assertStatus(403);
        $this->actingAs($tecnico)->post('/api/routers', [])->assertStatus(403);
    }

    #[Test]
    public function sin_sesion_la_api_responde_401(): void
    {
        $this->getJson('/api/customers')->assertStatus(401);
    }
}
