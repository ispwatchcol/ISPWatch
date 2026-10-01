<?php

namespace Tests\Feature\ApiKeys;

use App\Imports\Sheets\CustomersSheetImport;
use App\Models\ApiClient;
use App\Models\CustomerProfile;
use App\Models\PartnerEvent;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Router;
use App\Models\Sectorial;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use App\Services\CustomerDeletionService;
use App\Services\MikroTik\CustomerDeprovisionManager;
use App\Services\PartnerEventSequencer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cobertura del feed partner: lo que un AAA externo necesita ver y hasta
 * 2026-09-30 no veía (KAN-111 a KAN-115).
 *
 * Un integrador fail-closed no puede distinguir «no pasó nada» de «pasó algo
 * y no me avisaron». Cada test de aquí es un cambio real que el feed se
 * callaba, o una forma real de perder filas o eventos al recorrer.
 */
class PartnerFeedCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private string $tokenA;
    private string $tokenB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();

        $this->tokenA = $this->issueKey($this->tenantA);
        $this->tokenB = $this->issueKey($this->tenantB);
    }

    private function issueKey(Tenant $tenant): string
    {
        $client = ApiClient::create([
            'tenant_id' => $tenant->id,
            'name'      => "Orquestador {$tenant->id}",
            'is_active' => true,
        ]);

        $token = $client->createToken('test', ['read:customers', 'read:services', 'read:events']);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        return $token->plainTextToken;
    }

    private function api(string $uri, ?string $token = null)
    {
        // El guard cachea la llave de la petición anterior: sin esto, una
        // llamada con la llave B se resolvería como la A.
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->tokenA)])
            ->getJson('/api/v1/partner' . $uri);
    }

    /** Todos los eventos del feed a partir de un cursor. */
    private function feed(int $since = 0, ?string $token = null): array
    {
        return $this->api("/events?since={$since}&limit=500", $token)->assertOk()->json('data');
    }

    private function router(Tenant $tenant, string $name, bool $radius = false): Router
    {
        return Router::create([
            'tenant_id' => $tenant->id,
            'name'      => $name,
            'status'    => 'active',
            'radius'    => $radius,
        ]);
    }

    private function seedCustomer(Tenant $tenant, string $name, ?Router $router = null): CustomerProfile
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'user_name' => $name]);
        $plan = Plan::factory()->create(['tenant_id' => $tenant->id, 'name' => "Plan {$name}"]);

        $profile = CustomerProfile::create([
            'user_id'        => $user->id,
            'name'           => $name,
            'last_name'      => 'Apellido',
            'cedula'         => '800' . $user->id,
            'service_status' => 'activo',
            'status'         => true,
            'service_id'     => $plan->id,
            'router_id'      => $router?->id,
            'ip_user'        => '10.40.0.' . (($user->id % 200) + 2),
            'pppoe_username' => strtolower($name) . '.pppoe',
        ]);

        UserService::create([
            'user_id'         => $user->id,
            'service_plan_id' => $plan->id,
            'status'          => UserService::STATUS_ACTIVE,
            'start_date'      => now(),
        ]);

        return $profile;
    }

    private function eventsOf(int $customerId, string $type): array
    {
        return array_values(array_filter(
            $this->feed(),
            fn ($e) => $e['customer_id'] === $customerId && $e['event_type'] === $type
        ));
    }

    // ─── KAN-112: el cursor no se salta lo que confirma tarde ──────────────

    #[Test]
    public function un_evento_que_confirma_despues_de_uno_mas_nuevo_no_se_pierde(): void
    {
        // Así se perdía: la transacción A toma el id 50, la B toma el 100 y
        // confirma primero. El integrador lee el 100 y avanza su cursor. Con
        // cursor por `id`, el 50 —que aparece después— quedaba por debajo del
        // cursor para siempre.
        $profile = $this->seedCustomer($this->tenantA, 'Tardio');
        PartnerEvent::query()->delete();

        PartnerEvent::query()->insert([
            'id' => 100, 'tenant_id' => $this->tenantA->id, 'event_type' => PartnerEvent::SERVICE_SUSPENDED,
            'customer_id' => $profile->user_id, 'occurred_at' => now(),
        ]);

        $primera = $this->api('/events?since=0')->assertOk();
        $cursor  = $primera->json('meta.next_since');
        $this->assertCount(1, $primera->json('data'));

        // Confirma ahora, con un id MENOR que el que el integrador ya leyó.
        PartnerEvent::query()->insert([
            'id' => 50, 'tenant_id' => $this->tenantA->id, 'event_type' => PartnerEvent::SERVICE_REACTIVATED,
            'customer_id' => $profile->user_id, 'occurred_at' => now(),
        ]);

        $segunda = $this->api("/events?since={$cursor}")->assertOk();

        $this->assertCount(1, $segunda->json('data'), 'El evento que confirmó tarde tiene que llegar');
        $this->assertSame(PartnerEvent::SERVICE_REACTIVATED, $segunda->json('data.0.event_type'));
        $this->assertGreaterThan($cursor, $segunda->json('data.0.event_id'));
    }

    #[Test]
    public function un_evento_publicado_conserva_su_event_id(): void
    {
        // El integrador deduplica por event_id: si cambiara entre lecturas,
        // procesaría dos veces el mismo cambio creyendo que son dos.
        $profile = $this->seedCustomer($this->tenantA, 'Estable');
        $profile->update(['service_status' => 'suspendido']);

        $antes = collect($this->feed())->pluck('event_id')->all();
        app(PartnerEventSequencer::class)->publishPending();
        $despues = collect($this->feed())->pluck('event_id')->all();

        $this->assertSame($antes, $despues);
    }

    #[Test]
    public function la_revision_sigue_al_evento_publicado_aunque_haya_confirmado_tarde(): void
    {
        // Con MAX(id) la revisión apuntaba al evento de id más alto, que no es
        // el último que el integrador recibió: le decía que ya estaba al día.
        $profile = $this->seedCustomer($this->tenantA, 'Revision');
        PartnerEvent::query()->delete();

        PartnerEvent::query()->insert([
            'id' => 100, 'tenant_id' => $this->tenantA->id, 'event_type' => PartnerEvent::SERVICE_SUSPENDED,
            'customer_id' => $profile->user_id, 'occurred_at' => now(),
        ]);
        $this->feed();

        PartnerEvent::query()->insert([
            'id' => 50, 'tenant_id' => $this->tenantA->id, 'event_type' => PartnerEvent::SERVICE_REACTIVATED,
            'customer_id' => $profile->user_id, 'occurred_at' => now(),
        ]);

        $revision = $this->api("/customers/{$profile->user_id}")->assertOk()->json('data.revision');
        $ultimo   = collect($this->feed())->last();

        $this->assertSame(PartnerEvent::SERVICE_REACTIVATED, $ultimo['event_type']);
        $this->assertSame($ultimo['event_id'], $revision);
    }

    // ─── KAN-111: la carga masiva también avisa ────────────────────────────

    #[Test]
    public function la_carga_masiva_publica_un_service_created_por_cliente(): void
    {
        // La importación inserta los usuarios con role_id = 3 fijo (el rol
        // global «Cliente» de producción); la base de pruebas no lo trae.
        Role::forceCreate(['id' => 3, 'name' => 'Cliente', 'permissions' => []]);

        $router = Router::create([
            'tenant_id' => $this->tenantA->id,
            'name'      => 'Core Importacion',
            'ip'        => '172.18.5.2',
            'status'    => 'active',
            'pppoe'     => false,
        ]);
        Plan::factory()->create(['tenant_id' => $this->tenantA->id, 'name' => 'Hogar 10M']);
        Sectorial::create(['tenant_id' => $this->tenantA->id, 'name' => 'Sector Import', 'element_type' => 'sectorial']);

        $filas = collect(range(1, 3))->map(fn ($i) => [
            'nombre'           => "Importado{$i}",
            'apellido'         => 'Masivo',
            'ip_usuario'       => "10.50.0.{$i}",
            'ip_router'        => '172.18.5.2',
            'nombre_plan'      => 'Hogar 10M',
            'nombre_sectorial' => 'Sector Import',
        ]);

        $import = new CustomersSheetImport($this->tenantA->id);
        $import->collection($filas);

        $this->assertSame([], $import->errors, 'La importación no debió rechazar filas');

        $servicios = UserService::whereIn(
            'user_id',
            CustomerProfile::where('router_id', $router->id)->pluck('user_id')
        )->get();
        $this->assertCount(3, $servicios, 'La importación debió crear los tres servicios');

        $creados = collect($this->feed())->where('event_type', PartnerEvent::SERVICE_CREATED);

        $this->assertCount(3, $creados);
        $this->assertEqualsCanonicalizing(
            $servicios->pluck('id')->all(),
            $creados->pluck('service_id')->all(),
            'Cada evento debe apuntar al servicio que se creó'
        );

        // Y sólo los ve el tenant dueño.
        $this->assertCount(0, $this->feed(0, $this->tokenB));
    }

    // ─── KAN-113: la baja física deja una lápida ───────────────────────────

    #[Test]
    public function eliminar_un_cliente_publica_su_lapida_con_lo_necesario_para_revocar(): void
    {
        $this->app->instance(CustomerDeprovisionManager::class, \Mockery::mock(CustomerDeprovisionManager::class));

        // Sin credenciales de gestión: el borrado no intenta hablar con el equipo.
        $router  = $this->router($this->tenantA, 'NAS Piloto', radius: true);
        $profile = $this->seedCustomer($this->tenantA, 'Baja', $router);
        $servicio = UserService::where('user_id', $profile->user_id)->value('id');
        $user     = User::findOrFail($profile->user_id);

        app(CustomerDeletionService::class)->delete($user, $profile);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);

        $lapidas = $this->eventsOf($user->id, PartnerEvent::CUSTOMER_DELETED);

        $this->assertCount(1, $lapidas);
        $this->assertSame([(int) $servicio], $lapidas[0]['changes']['service_ids']);
        $this->assertSame($router->id, $lapidas[0]['changes']['router_id']);
        $this->assertTrue($lapidas[0]['changes']['managed_by_external_aaa']);

        // El recurso ya no existe; la lápida es lo único que queda.
        $this->api("/customers/{$user->id}")->assertNotFound();

        $this->assertSame([], array_filter(
            $this->feed(0, $this->tokenB),
            fn ($e) => $e['event_type'] === PartnerEvent::CUSTOMER_DELETED
        ), 'Otro tenant no puede enterarse de la baja');
    }

    // ─── KAN-114: router, red y modo RADIUS ────────────────────────────────

    #[Test]
    public function cambiar_de_router_publica_el_anterior_y_el_nuevo(): void
    {
        $viejo   = $this->router($this->tenantA, 'NAS Viejo', radius: false);
        $nuevo   = $this->router($this->tenantA, 'NAS Nuevo', radius: true);
        $profile = $this->seedCustomer($this->tenantA, 'Mudanza', $viejo);

        $profile->update(['router_id' => $nuevo->id]);

        $eventos = $this->eventsOf($profile->user_id, PartnerEvent::ROUTER_CHANGED);

        $this->assertCount(1, $eventos);
        $this->assertSame(['from' => $viejo->id, 'to' => $nuevo->id], $eventos[0]['changes']['router_id']);
        $this->assertSame(['from' => false, 'to' => true], $eventos[0]['changes']['managed_by_external_aaa']);

        // Y la revisión del cliente se mueve: antes no cambiaba.
        $this->assertSame(
            $eventos[0]['event_id'],
            $this->api("/customers/{$profile->user_id}")->json('data.revision')
        );
    }

    #[Test]
    public function cambiar_la_ip_o_el_usuario_pppoe_publica_un_cambio_de_red_sin_los_valores(): void
    {
        $profile = $this->seedCustomer($this->tenantA, 'Red');

        $profile->update(['ip_user' => '10.99.99.99', 'pppoe_username' => 'red.nuevo']);

        $eventos = $this->eventsOf($profile->user_id, PartnerEvent::NETWORK_CHANGED);

        $this->assertCount(1, $eventos);
        $this->assertEqualsCanonicalizing(['ip', 'pppoe_username'], $eventos[0]['changes']['fields']);

        // El log de eventos no se poda: la IP no tiene por qué quedar ahí.
        $this->assertStringNotContainsString('10.99.99.99', json_encode($eventos));
    }

    #[Test]
    public function activar_radius_en_un_router_avisa_a_todos_sus_clientes_y_a_nadie_mas(): void
    {
        $router = $this->router($this->tenantA, 'NAS Que Migra', radius: false);
        $otro   = $this->router($this->tenantA, 'NAS Ajeno', radius: false);

        $suyos = collect(['Uno', 'Dos', 'Tres'])->map(fn ($n) => $this->seedCustomer($this->tenantA, $n, $router));
        $ajeno = $this->seedCustomer($this->tenantA, 'Ajeno', $otro);

        $router->update(['radius' => true]);

        $eventos = collect($this->feed())->where('event_type', PartnerEvent::ROUTER_CHANGED);

        $this->assertEqualsCanonicalizing(
            $suyos->pluck('user_id')->all(),
            $eventos->pluck('customer_id')->all()
        );
        $this->assertNotContains($ajeno->user_id, $eventos->pluck('customer_id')->all());

        foreach ($eventos as $evento) {
            $this->assertSame(['from' => $router->id, 'to' => $router->id], $evento['changes']['router_id']);
            $this->assertSame(['from' => false, 'to' => true], $evento['changes']['managed_by_external_aaa']);
        }
    }

    #[Test]
    public function tocar_el_router_sin_cambiar_su_modo_no_ensucia_el_feed(): void
    {
        $router = $this->router($this->tenantA, 'NAS Quieto', radius: true);
        $this->seedCustomer($this->tenantA, 'Quieto', $router);
        $antes = count($this->feed());

        $router->update(['name' => 'NAS Renombrado']);

        $this->assertCount($antes, $this->feed());
    }

    #[Test]
    public function is_enabled_que_cambia_solo_tambien_avisa(): void
    {
        // Normalmente se mueve con service_status y ya sale un SERVICE_*. Si
        // se mueve solo, el integrador que exige ambas señales no se enteraba.
        $profile = $this->seedCustomer($this->tenantA, 'Habilitado');

        $profile->update(['status' => false]);

        $eventos = $this->eventsOf($profile->user_id, PartnerEvent::CUSTOMER_UPDATED);

        $this->assertCount(1, $eventos);
        $this->assertSame(['is_enabled'], $eventos[0]['changes']['fields']);
    }

    #[Test]
    public function suspender_no_duplica_el_aviso_de_is_enabled(): void
    {
        $profile = $this->seedCustomer($this->tenantA, 'Suspendido');

        $profile->update(['status' => false, 'service_status' => 'suspendido']);

        $this->assertCount(1, $this->eventsOf($profile->user_id, PartnerEvent::SERVICE_SUSPENDED));
        $this->assertCount(0, $this->eventsOf($profile->user_id, PartnerEvent::CUSTOMER_UPDATED));
    }

    #[Test]
    public function los_servicios_se_pueden_filtrar_por_router(): void
    {
        $nas   = $this->router($this->tenantA, 'NAS Filtro');
        $mio   = $this->seedCustomer($this->tenantA, 'EnNas', $nas);
        $this->seedCustomer($this->tenantA, 'FueraNas');

        $data = $this->api("/services?router_id={$nas->id}")->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($mio->user_id, $data[0]['customer_id']);
    }

    // ─── KAN-115: barrido por cursor ───────────────────────────────────────

    #[Test]
    public function el_barrido_por_cursor_no_pierde_filas_si_se_borra_una_anterior(): void
    {
        $clientes = collect(['C1', 'C2', 'C3', 'C4', 'C5'])
            ->map(fn ($n) => $this->seedCustomer($this->tenantA, $n)->user_id)
            ->sort()->values();

        $primera = $this->api('/customers?after_id=0&per_page=2')->assertOk();
        $vistos  = collect($primera->json('data'))->pluck('id');
        $this->assertTrue($primera->json('meta.has_more'));

        // A mitad del barrido se borra una fila YA leída. Con OFFSET, la
        // siguiente página se correría un lugar y C3 no saldría nunca.
        CustomerProfile::where('user_id', $clientes[0])->delete();
        User::whereKey($clientes[0])->delete();

        $cursor = $primera->json('meta.next_after_id');
        do {
            $pagina = $this->api("/customers?after_id={$cursor}&per_page=2")->assertOk();
            $vistos = $vistos->merge(collect($pagina->json('data'))->pluck('id'));
            $cursor = $pagina->json('meta.next_after_id');
        } while ($pagina->json('meta.has_more'));

        $this->assertEquals($clientes->all(), $vistos->all(), 'Ninguna fila que existía se puede saltar');
    }

    #[Test]
    public function el_barrido_por_cursor_tambien_existe_en_servicios(): void
    {
        $servicios = collect(['S1', 'S2', 'S3'])
            ->map(fn ($n) => UserService::where('user_id', $this->seedCustomer($this->tenantA, $n)->user_id)->value('id'))
            ->sort()->values();

        $vistos = collect();
        $cursor = 0;
        do {
            $pagina = $this->api("/services?after_id={$cursor}&per_page=1")->assertOk();
            $vistos = $vistos->merge(collect($pagina->json('data'))->pluck('service_id'));
            $cursor = $pagina->json('meta.next_after_id');
        } while ($pagina->json('meta.has_more'));

        $this->assertEquals($servicios->all(), $vistos->all());
    }

    #[Test]
    public function after_id_y_page_no_se_combinan(): void
    {
        $this->api('/customers?after_id=0&page=2')->assertStatus(422);
        $this->api('/services?after_id=0&page=2')->assertStatus(422);
    }

    #[Test]
    public function un_cursor_al_final_devuelve_el_mismo_cursor_y_sin_mas(): void
    {
        $profile = $this->seedCustomer($this->tenantA, 'Ultimo');

        $res = $this->api("/customers?after_id={$profile->user_id}")->assertOk();

        $this->assertSame([], $res->json('data'));
        $this->assertSame($profile->user_id, $res->json('meta.next_after_id'));
        $this->assertFalse($res->json('meta.has_more'));
    }
}
