<?php

namespace Tests\Feature\ApiKeys;

use App\Models\ApiClient;
use App\Models\CustomerProfile;
use App\Models\PartnerEvent;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use App\Support\PartnerAccessDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ISPWatch decide, el AAA externo ejecuta (contrato partner 1.3.0).
 *
 * Lo que protegen estas pruebas, en orden de gravedad:
 *
 *  1. Que `access` sea fail-closed: un estado raro, nulo o una ficha
 *     deshabilitada nunca salen como `granted = true`. Del otro lado hay un
 *     AAA que aplica el campo sin pensarlo, así que un falso positivo aquí es
 *     servicio regalado.
 *  2. Que `/customers` y `/services` den la MISMA decisión para el mismo
 *     abonado: dos respuestas distintas obligarían al integrador a elegir, y
 *     eso le devuelve la autoridad.
 *  3. Que `/routers` no filtre credenciales ni routers de otro tenant.
 *  4. Que `meta.head` sea un cursor de arranque correcto.
 */
class PartnerAccessAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private string $tokenA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();

        $client = ApiClient::create([
            'tenant_id' => $this->tenantA->id,
            'name'      => 'AAA externo',
            'is_active' => true,
        ]);

        $token = $client->createToken('test', ['read:customers', 'read:services', 'read:events']);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        $this->tokenA = $token->plainTextToken;
    }

    private function partner(string $uri)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokenA])->getJson($uri);
    }

    private function router(Tenant $tenant, string $name, bool $radius = false): Router
    {
        return Router::create([
            'tenant_id' => $tenant->id,
            'name'      => $name,
            'ip'        => '172.18.9.' . random_int(2, 250),
            'status'    => 'active',
            'radius'    => $radius,
        ]);
    }

    private function customer(Tenant $tenant, string $name, string $serviceStatus, bool $enabled, ?Router $router = null): CustomerProfile
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'user_name' => $name]);
        $plan = Plan::factory()->create(['tenant_id' => $tenant->id, 'name' => "Plan {$name}"]);

        $profile = CustomerProfile::create([
            'user_id'        => $user->id,
            'name'           => $name,
            'last_name'      => 'Apellido',
            'cedula'         => '700' . $user->id,
            'service_status' => $serviceStatus,
            'status'         => $enabled,
            'service_id'     => $plan->id,
            'router_id'      => $router?->id,
        ]);

        UserService::create([
            'user_id'         => $user->id,
            'service_plan_id' => $plan->id,
            'status'          => UserService::STATUS_ACTIVE,
            'start_date'      => now(),
        ]);

        return $profile;
    }

    // ─── La regla ───────────────────────────────────────────────────────────

    public static function decisiones(): array
    {
        return [
            'activo y habilitado'        => ['activo', true, true, 'active'],
            'cortesía y habilitado'      => ['gratis', true, true, 'courtesy'],
            'suspendido'                 => ['suspendido', true, false, 'suspended'],
            'suspendido y deshabilitado' => ['suspendido', false, false, 'suspended'],
            'cancelado'                  => ['cancelado', true, false, 'cancelled'],
            'retirado'                   => ['retirado', true, false, 'retired'],
            'activo pero deshabilitado'  => ['activo', false, false, 'disabled'],
            'estado nulo'                => [null, true, false, 'unknown_status'],
            'estado desconocido'         => ['en_prueba', true, false, 'unknown_status'],
            'mayúsculas y espacios'      => [' Activo ', true, true, 'active'],
        ];
    }

    #[Test]
    #[DataProvider('decisiones')]
    public function la_decision_es_fail_closed(?string $status, bool $enabled, bool $granted, string $reason): void
    {
        $this->assertSame(
            ['granted' => $granted, 'reason' => $reason],
            PartnerAccessDecision::for($status, $enabled)
        );
    }

    #[Test]
    public function todo_motivo_que_puede_salir_esta_publicado(): void
    {
        foreach (self::decisiones() as [$status, $enabled]) {
            $this->assertContains(
                PartnerAccessDecision::for($status, $enabled)['reason'],
                PartnerAccessDecision::REASONS
            );
        }
    }

    // ─── La decisión en la API ──────────────────────────────────────────────

    #[Test]
    public function customers_y_services_entregan_la_misma_decision(): void
    {
        $alDia     = $this->customer($this->tenantA, 'Ana', 'activo', true);
        $moroso    = $this->customer($this->tenantA, 'Beto', 'suspendido', false);
        $heredado  = $this->customer($this->tenantA, 'Carla', 'activo', false);

        $esperado = [
            $alDia->user_id    => ['granted' => true,  'reason' => 'active'],
            $moroso->user_id   => ['granted' => false, 'reason' => 'suspended'],
            $heredado->user_id => ['granted' => false, 'reason' => 'disabled'],
        ];

        foreach ($this->partner('/api/v1/partner/customers')->assertOk()->json('data') as $c) {
            $this->assertSame($esperado[$c['id']], $c['access'], "customers #{$c['id']}");
        }

        foreach ($this->partner('/api/v1/partner/services')->assertOk()->json('data') as $s) {
            $this->assertSame($esperado[$s['customer_id']], $s['access'], "services de #{$s['customer_id']}");
        }

        $this->partner("/api/v1/partner/customers/{$moroso->user_id}")
            ->assertOk()
            ->assertJsonPath('data.access.granted', false);
    }

    #[Test]
    public function el_modo_aaa_externo_no_cambia_la_decision(): void
    {
        // El router en modo RADIUS sólo significa que ISPWatch no escribe en
        // el equipo. Quién navega lo sigue decidiendo ISPWatch.
        $radius = $this->router($this->tenantA, 'PILOTO-AAA', radius: true);
        $moroso = $this->customer($this->tenantA, 'Dora', 'suspendido', false, $radius);

        $this->partner("/api/v1/partner/customers/{$moroso->user_id}")
            ->assertOk()
            ->assertJsonPath('data.router_managed_by_external_aaa', true)
            ->assertJsonPath('data.access.granted', false);
    }

    // ─── /routers ───────────────────────────────────────────────────────────

    #[Test]
    public function lista_los_routers_del_tenant_incluidos_los_que_no_tienen_clientes(): void
    {
        $piloto = $this->router($this->tenantA, 'PILOTO-AAA', radius: true);
        $core   = $this->router($this->tenantA, 'CORE-1');
        $this->router($this->tenantB, 'AJENO');
        $this->customer($this->tenantA, 'Eva', 'activo', true, $core);

        $data = collect($this->partner('/api/v1/partner/routers')->assertOk()->json('data'))
            ->keyBy('router_id');

        $this->assertSame([$piloto->id, $core->id], $data->keys()->sort()->values()->all());

        $this->assertSame([
            'router_id'               => $piloto->id,
            'name'                    => 'PILOTO-AAA',
            'status'                  => 'active',
            'managed_by_external_aaa' => true,
            'customers_count'         => 0,
        ], $data[$piloto->id]);

        $this->assertSame(1, $data[$core->id]['customers_count']);
    }

    #[Test]
    public function un_router_no_expone_como_llegar_al_equipo(): void
    {
        $router = $this->router($this->tenantA, 'CORE-1');

        $body = $this->partner("/api/v1/partner/routers/{$router->id}")->assertOk()->json('data');

        $this->assertSame(
            ['router_id', 'name', 'status', 'managed_by_external_aaa', 'customers_count'],
            array_keys($body),
            'Cualquier campo nuevo en /routers debe agregarse a conciencia: la tabla guarda credenciales.'
        );
    }

    #[Test]
    public function filtra_por_modo_de_gestion(): void
    {
        $piloto = $this->router($this->tenantA, 'PILOTO-AAA', radius: true);
        $this->router($this->tenantA, 'CORE-1');

        $this->partner('/api/v1/partner/routers?managed_by_external_aaa=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.router_id', $piloto->id);
    }

    #[Test]
    public function el_router_de_otro_tenant_responde_404(): void
    {
        $ajeno = $this->router($this->tenantB, 'AJENO');

        $this->partner("/api/v1/partner/routers/{$ajeno->id}")
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
    }

    #[Test]
    public function routers_exige_read_services(): void
    {
        $client = ApiClient::create([
            'tenant_id' => $this->tenantA->id,
            'name'      => 'Sólo cartera',
            'is_active' => true,
        ]);
        $token = $client->createToken('cartera', ['read:customers']);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        $this->withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken])
            ->getJson('/api/v1/partner/routers')
            ->assertForbidden();
    }

    // ─── meta.head ──────────────────────────────────────────────────────────

    #[Test]
    public function head_es_la_cabeza_del_tenant_sin_filtros_ni_eventos_ajenos(): void
    {
        $vacio = $this->partner('/api/v1/partner/events?limit=1')->assertOk()->json('meta');
        $this->assertSame(0, $vacio['head']);

        $ana  = $this->customer($this->tenantA, 'Ana', 'activo', true);
        $beto = $this->customer($this->tenantA, 'Beto', 'activo', true);
        $ana->update(['service_status' => 'suspendido']);
        $beto->update(['service_status' => 'suspendido']);
        $this->customer($this->tenantB, 'Ajena', 'activo', true)->update(['service_status' => 'suspendido']);

        $meta = $this->partner('/api/v1/partner/events?limit=1&customer_id=' . $ana->user_id)
            ->assertOk()->json('meta');

        $propios = PartnerEvent::where('tenant_id', $this->tenantA->id)->max('seq');

        // Con limit=1 y filtrado por Ana, head sigue siendo la cabeza de TODO
        // el tenant: es lo que permite arrancar sin recorrer el historial.
        $this->assertSame((int) $propios, $meta['head']);
        $this->assertTrue($meta['has_more'] || $meta['count'] === 1);

        // Y un cambio posterior a leer head llega al reproducir desde él.
        $ana->update(['service_status' => 'activo']);

        $despues = $this->partner('/api/v1/partner/events?since=' . $meta['head'])->assertOk()->json('data');

        $this->assertContains($ana->user_id, array_column($despues, 'customer_id'));
        $this->assertNotContains($beto->user_id, array_column($despues, 'customer_id'));
    }
}
