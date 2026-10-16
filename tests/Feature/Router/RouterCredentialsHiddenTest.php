<?php

namespace Tests\Feature\Router;

use App\Models\Role;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P-2 / KAN-45: las credenciales del router no salen en ninguna respuesta de la
 * API, y editar un router sin escribir contraseña conserva la guardada.
 */
class RouterCredentialsHiddenTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETS = ['clave-ssh-del-rb', 'clave-vpn-del-rb', 'wg-privada-del-rb'];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Administrador', 'permissions' => ['*']]); // ocupa el id 1
        $this->tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'Admin routers', 'permissions' => ['manage_routers', 'view_plans']]);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]));
    }

    private function router(): Router
    {
        return Router::create([
            'name'             => 'RB Centro',
            'tenant_id'        => $this->tenant->id,
            'status'           => 'active',
            'ip'               => '10.10.10.1',
            'user_rb'          => 'admin',
            'password_rb'      => 'clave-ssh-del-rb',
            'vpn_username'     => 'vpn-rb',
            'vpn_password'     => 'clave-vpn-del-rb',
            'wg_private_key'   => 'wg-privada-del-rb',
            'firmware_version' => '7.14.3',
            'pppoe'            => true,
        ]);
    }

    private function assertNoSecrets(string $json): void
    {
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $json, "La respuesta filtra «{$secret}».");
        }
        foreach (['password_rb', 'vpn_password', 'wg_private_key'] as $field) {
            $this->assertStringNotContainsString("\"{$field}\"", $json, "La respuesta trae la clave {$field}.");
        }
    }

    #[Test]
    public function el_detalle_no_trae_credenciales_pero_dice_si_hay_contrasena(): void
    {
        $router = $this->router();

        $response = $this->getJson("/api/routers/{$router->id}")->assertOk();

        $this->assertNoSecrets($response->getContent());
        $this->assertTrue($response->json('has_password_rb') ?? $response->json('data.has_password_rb'));
        // El usuario no es secreto y el formulario lo sigue mostrando.
        $this->assertStringContainsString('admin', $response->getContent());
    }

    #[Test]
    public function el_listado_tampoco(): void
    {
        $this->router();

        $this->assertNoSecrets($this->getJson('/api/routers')->assertOk()->getContent());
    }

    #[Test]
    public function la_respuesta_de_alta_y_de_edicion_tampoco(): void
    {
        $alta = $this->postJson('/api/routers', [
            'name' => 'RB Nuevo', 'status' => 'active', 'pppoe' => true,
            'ip' => '10.10.10.2', 'user_rb' => 'admin', 'password_rb' => 'clave-ssh-del-rb',
            'firmware_version' => '7.14.3',
        ])->assertCreated();
        $this->assertNoSecrets($alta->getContent());

        $router = $this->router();
        $edicion = $this->putJson("/api/routers/{$router->id}", ['name' => 'RB Renombrado'])->assertOk();
        $this->assertNoSecrets($edicion->getContent());
    }

    #[Test]
    public function editar_sin_escribir_contrasena_conserva_la_guardada(): void
    {
        // Lo que manda RouterEdit.vue cuando el campo queda en blanco: nada.
        $router = $this->router();

        $this->putJson("/api/routers/{$router->id}", [
            'name' => 'RB Renombrado', 'user_rb' => 'admin', 'ip' => '10.10.10.1',
        ])->assertOk();

        $router->refresh();
        $this->assertSame('RB Renombrado', $router->name);
        $this->assertSame('clave-ssh-del-rb', $router->password_rb);
        $this->assertSame('clave-vpn-del-rb', $router->vpn_password);
    }

    #[Test]
    public function escribir_una_contrasena_nueva_la_reemplaza(): void
    {
        $router = $this->router();

        $this->putJson("/api/routers/{$router->id}", ['password_rb' => 'otra-clave'])->assertOk();

        $this->assertSame('otra-clave', $router->fresh()->password_rb);
    }

    #[Test]
    public function el_servidor_sigue_leyendo_las_credenciales(): void
    {
        // $hidden sólo afecta a la serialización: el aprovisionamiento y el
        // script VPN siguen necesitando el valor real.
        $router = $this->router()->fresh();

        $this->assertSame('clave-ssh-del-rb', $router->password_rb);
        $this->assertSame('wg-privada-del-rb', $router->wg_private_key);
        $this->assertFalse(Router::make([])->has_password_rb);
    }
}
