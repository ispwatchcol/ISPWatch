<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Flujo de acceso REAL del sistema: POST /api/login.
 *
 * Sustituye a los tests de andamiaje de Laravel Breeze (AuthenticationTest,
 * RegistrationTest, PasswordResetTest, ProfileTest…), que probaban componentes
 * Volt y rutas web (/login, /profile) que este proyecto nunca llegó a montar:
 * `routes/auth.php` no estaba registrado en bootstrap/app.php y las vistas
 * `resources/views/pages/auth` no existen. Eran 19 fallos permanentes que
 * llevaban años en rojo y que tapaban los fallos reales de la suite.
 *
 * La autenticación de verdad es la SPA contra la API, y el identificador de
 * acceso es `email_tenant`, no `email`.
 */
class ApiLoginTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'ISP Test', 'domain' => 'isp-test']);

        // El límite de intentos es por IP + email y persiste entre tests dentro
        // del mismo proceso si no se limpia.
        RateLimiter::clear('login_attempt:127.0.0.1:juan.perez@isp-test');
    }

    /**
     * @param  array{email_verified_at?: mixed}  $overrides
     */
    private function makeUser(array $overrides = []): User
    {
        $role = Role::create([
            'name'        => 'Staff',
            'code'        => 'staff',
            'tenant_id'   => $this->tenant->id,
            'permissions' => ['view_clients'],
        ]);

        // OJO: `email_verified_at` NO está en $fillable de User, así que
        // User::create() lo descarta en silencio y el usuario nace sin verificar
        // — con lo que el login devuelve 403 en lugar de 200. Hay que marcarlo
        // aparte con forceFill().
        $verificadoEn = array_key_exists('email_verified_at', $overrides)
            ? $overrides['email_verified_at']
            : now();
        unset($overrides['email_verified_at']);

        $user = User::create(array_merge([
            'name'          => 'Juan Pérez',
            'user_name'     => 'Juan',
            'user_lastname' => 'Pérez',
            'email'         => 'juan.perez@gmail.com',
            'email_tenant'  => 'juan.perez@isp-test',
            'password'      => bcrypt('secreto123'),
            'role_id'       => $role->id,
            'tenant_id'     => $this->tenant->id,
            'status'        => true,
        ], $overrides));

        $user->forceFill(['email_verified_at' => $verificadoEn])->save();

        return $user;
    }

    #[Test]
    public function un_usuario_verificado_inicia_sesion_y_recibe_sus_permisos(): void
    {
        $this->makeUser();

        $this->postJson('/api/login', [
            'email_tenant' => 'juan.perez@isp-test',
            'password'     => 'secreto123',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email_tenant', 'juan.perez@isp-test')
            ->assertJsonPath('data.role_code', 'staff')
            ->assertJsonPath('data.permissions', ['view_clients']);
    }

    #[Test]
    public function el_identificador_de_acceso_es_email_tenant_no_el_correo_personal(): void
    {
        $this->makeUser();

        // Confusión habitual del usuario final: intentar entrar con su correo
        // personal en lugar del de acceso.
        $this->postJson('/api/login', [
            'email_tenant' => 'juan.perez@gmail.com',
            'password'     => 'secreto123',
        ])->assertStatus(401);
    }

    #[Test]
    public function una_contrasena_incorrecta_devuelve_401_sin_revelar_si_el_usuario_existe(): void
    {
        $this->makeUser();

        $existente = $this->postJson('/api/login', [
            'email_tenant' => 'juan.perez@isp-test',
            'password'     => 'incorrecta',
        ])->assertStatus(401);

        $inexistente = $this->postJson('/api/login', [
            'email_tenant' => 'nadie@isp-test',
            'password'     => 'incorrecta',
        ])->assertStatus(401);

        // Mismo mensaje en ambos casos: no se puede enumerar usuarios.
        $this->assertSame(
            $existente->json('message'),
            $inexistente->json('message')
        );
    }

    #[Test]
    public function un_correo_sin_verificar_no_puede_iniciar_sesion(): void
    {
        $this->makeUser(['email_verified_at' => null]);

        $this->postJson('/api/login', [
            'email_tenant' => 'juan.perez@isp-test',
            'password'     => 'secreto123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function una_entrada_con_patron_de_inyeccion_se_rechaza(): void
    {
        $this->makeUser();

        $this->postJson('/api/login', [
            'email_tenant' => "admin' OR 1=1 --",
            'password'     => 'lo que sea',
        ])->assertStatus(400);
    }

    #[Test]
    public function el_limite_de_intentos_bloquea_tras_cinco_fallos(): void
    {
        $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email_tenant' => 'juan.perez@isp-test',
                'password'     => 'incorrecta',
            ])->assertStatus(401);
        }

        // El sexto intento ya no llega a comprobar la contraseña.
        $this->postJson('/api/login', [
            'email_tenant' => 'juan.perez@isp-test',
            'password'     => 'secreto123',
        ])
            ->assertStatus(429)
            ->assertJsonStructure(['success', 'message', 'retry_after']);
    }

    #[Test]
    public function auth_me_devuelve_los_permisos_actualizados_desde_la_base(): void
    {
        $user = $this->makeUser();

        // Un administrador le amplía el rol mientras la sesión está abierta.
        $user->role->update(['permissions' => ['view_clients', 'view_billing']]);

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.permissions', ['view_clients', 'view_billing']);
    }
}
