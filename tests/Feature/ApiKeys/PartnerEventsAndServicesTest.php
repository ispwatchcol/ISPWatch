<?php

namespace Tests\Feature\ApiKeys;

use App\Models\ApiClient;
use App\Models\CustomerProfile;
use App\Models\PartnerEvent;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Contratos de servicio y feed de cambios de la API pública.
 *
 * Lo que estos tests protegen, en orden de gravedad:
 *
 *  1. Que el feed no se salte eventos. Un cursor mal ordenado deja al
 *     integrador desincronizado sin ningún error visible — es el fallo más
 *     caro y el más silencioso de todo el subsistema.
 *  2. Que un tenant no vea los eventos ni los servicios de otro.
 *  3. Que los cambios entren por cualquier puerta (panel, consola, carga
 *     masiva) y no solo por el controlador.
 */
class PartnerEventsAndServicesTest extends TestCase
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
            'name'      => 'Orquestador del ISP A',
            'is_active' => true,
        ]);

        $this->tokenA = $this->issueKey($client, [
            'read:customers', 'read:services', 'read:events',
        ]);
    }

    private function issueKey(ApiClient $client, array $abilities): string
    {
        $token = $client->createToken('test', $abilities);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        return $token->plainTextToken;
    }

    private function headers(?string $token = null): array
    {
        return ['Authorization' => 'Bearer ' . ($token ?? $this->tokenA)];
    }

    /** Cliente con perfil, plan y servicio. Devuelve el perfil. */
    private function seedCustomer(Tenant $tenant, string $name, ?Plan $plan = null): CustomerProfile
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'user_name' => $name]);
        // Nombre único: `service_plan` tiene único (name, tenant_id) y la
        // factory elige de una lista corta, así que dos clientes del mismo
        // inquilino chocarían.
        $plan ??= Plan::factory()->create([
            'tenant_id' => $tenant->id,
            'name'      => "Plan {$name}",
        ]);

        $profile = CustomerProfile::create([
            'user_id'        => $user->id,
            'name'           => $name,
            'last_name'      => 'Apellido',
            'cedula'         => '900' . $user->id,
            'service_status' => 'activo',
            'status'         => true,
            'service_id'     => $plan->id,
            'ip_user'        => '10.30.0.' . (($user->id % 200) + 2),
            'pppoe_username' => strtolower($name) . '.pppoe',
            'pppoe_password' => 'no-debe-salir',
        ]);

        UserService::create([
            'user_id'         => $user->id,
            'service_plan_id' => $plan->id,
            'status'          => UserService::STATUS_ACTIVE,
            'start_date'      => now(),
        ]);

        return $profile;
    }

    // ─── Emisión de eventos ─────────────────────────────────────────────────

    #[Test]
    public function suspender_a_un_cliente_publica_un_evento(): void
    {
        $profile = $this->seedCustomer($this->tenantA, 'Ana');

        $profile->update(['service_status' => 'suspendido']);

        $event = PartnerEvent::where('customer_id', $profile->user_id)
            ->where('event_type', PartnerEvent::SERVICE_SUSPENDED)
            ->first();

        $this->assertNotNull($event, 'La suspensión debe publicar un evento');
        $this->assertSame($this->tenantA->id, (int) $event->tenant_id);
        $this->assertSame('activo', $event->changes['service_status']['from']);
        $this->assertSame('suspendido', $event->changes['service_status']['to']);
    }

    #[Test]
    public function reactivar_no_es_lo_mismo_que_activar(): void
    {
        // La distinción importa: del lado del integrador, salir de una
        // suspensión por pago dispara acciones distintas que un alta nueva.
        $profile = $this->seedCustomer($this->tenantA, 'Beto');

        $profile->update(['service_status' => 'suspendido']);
        $profile->update(['service_status' => 'activo']);

        $types = PartnerEvent::where('customer_id', $profile->user_id)
            ->orderBy('id')->pluck('event_type')->all();

        $this->assertContains(PartnerEvent::SERVICE_SUSPENDED, $types);
        $this->assertContains(PartnerEvent::SERVICE_REACTIVATED, $types);
        $this->assertNotContains(PartnerEvent::SERVICE_ACTIVATED, $types);
    }

    #[Test]
    public function retirar_a_un_cliente_publica_una_baja(): void
    {
        $profile = $this->seedCustomer($this->tenantA, 'Carla');

        $profile->update(['service_status' => 'retirado']);

        $this->assertDatabaseHas('partner_events', [
            'customer_id' => $profile->user_id,
            'event_type'  => PartnerEvent::SERVICE_CANCELLED,
        ]);
    }

    #[Test]
    public function cambiar_de_plan_publica_un_evento(): void
    {
        $profile = $this->seedCustomer($this->tenantA, 'Diana');
        $otro    = Plan::factory()->create(['tenant_id' => $this->tenantA->id]);

        $profile->update(['service_id' => $otro->id]);

        $this->assertDatabaseHas('partner_events', [
            'customer_id' => $profile->user_id,
            'event_type'  => PartnerEvent::PLAN_CHANGED,
        ]);
    }

    #[Test]
    public function editar_datos_irrelevantes_no_ensucia_el_feed(): void
    {
        // Si cualquier edición emitiera evento, el feed sería ruido y el
        // integrador terminaría ignorándolo.
        $profile = $this->seedCustomer($this->tenantA, 'Elena');
        PartnerEvent::query()->delete();

        $profile->update(['comments' => 'nota interna del operador']);

        $this->assertSame(0, PartnerEvent::count());
    }

    #[Test]
    public function el_evento_no_transporta_credenciales(): void
    {
        $profile = $this->seedCustomer($this->tenantA, 'Fabio');
        $profile->update(['service_status' => 'suspendido']);

        $raw = PartnerEvent::where('customer_id', $profile->user_id)->get()->toJson();

        $this->assertStringNotContainsString('no-debe-salir', $raw);
    }

    // ─── Feed por cursor ────────────────────────────────────────────────────

    #[Test]
    public function el_feed_avanza_por_cursor_sin_saltarse_eventos(): void
    {
        // El fallo que este test existe para evitar: un cursor mal ordenado
        // deja huecos y el integrador nunca se entera.
        $profile = $this->seedCustomer($this->tenantA, 'Gina');
        $profile->update(['service_status' => 'suspendido']);
        $profile->update(['service_status' => 'activo']);
        $profile->update(['service_status' => 'retirado']);

        $total = PartnerEvent::where('tenant_id', $this->tenantA->id)->count();

        $vistos = [];
        $cursor = 0;

        // Se recorre de a uno para forzar varias vueltas del cursor.
        for ($i = 0; $i < $total + 2; $i++) {
            $res = $this->withHeaders($this->headers())
                ->getJson("/api/v1/partner/events?since={$cursor}&limit=1")
                ->assertOk();

            $data = $res->json('data');
            if (!$data) {
                break;
            }

            $vistos[] = $data[0]['event_id'];
            $cursor   = $res->json('meta.next_since');
        }

        $this->assertCount($total, $vistos, 'El cursor no debe saltarse ni repetir eventos');
        $this->assertSame(array_unique($vistos), $vistos, 'No debe repetir eventos');
        $this->assertSame($vistos, array_values(collect($vistos)->sort()->all()), 'Debe venir en orden ascendente');
    }

    #[Test]
    public function el_feed_no_filtra_eventos_de_otro_tenant(): void
    {
        $mio  = $this->seedCustomer($this->tenantA, 'Hugo');
        $ajeno = $this->seedCustomer($this->tenantB, 'Ivan');

        $mio->update(['service_status' => 'suspendido']);
        $ajeno->update(['service_status' => 'suspendido']);

        $res = $this->withHeaders($this->headers())
            ->getJson('/api/v1/partner/events')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('customer_id')->unique()->all();

        $this->assertContains($mio->user_id, $ids);
        $this->assertNotContains($ajeno->user_id, $ids);
    }

    #[Test]
    public function sin_eventos_nuevos_el_cursor_no_retrocede(): void
    {
        $profile = $this->seedCustomer($this->tenantA, 'Julia');
        $profile->update(['service_status' => 'suspendido']);

        $ultimo = PartnerEvent::where('tenant_id', $this->tenantA->id)->max('id');

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/partner/events?since={$ultimo}")
            ->assertOk();

        $this->assertSame([], $res->json('data'));
        // Devolver 0 aquí haría que el integrador reprocesara todo desde el
        // principio en cada ciclo vacío.
        $this->assertSame($ultimo, $res->json('meta.next_since'));
        $this->assertFalse($res->json('meta.has_more'));
    }

    #[Test]
    public function el_feed_exige_su_propia_ability(): void
    {
        $client = ApiClient::create([
            'tenant_id' => $this->tenantA->id,
            'name'      => 'Solo cartera',
            'is_active' => true,
        ]);
        $token = $this->issueKey($client, ['read:customers']);

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/partner/events')
            ->assertForbidden();
    }

    // ─── Servicios ──────────────────────────────────────────────────────────

    #[Test]
    public function el_servicio_expone_un_id_estable_distinto_del_cliente(): void
    {
        // Dos usuarios sin servicio (personal interno, que existe de verdad)
        // desfasan la secuencia de `users` respecto a la de `user_services`.
        // Sin eso ambos ids valdrían lo mismo por casualidad y el test no
        // demostraría que son espacios de identidad distintos.
        User::factory()->count(2)->create(['tenant_id' => $this->tenantA->id]);
        $profile = $this->seedCustomer($this->tenantA, 'Karla');
        $service = UserService::where('user_id', $profile->user_id)->firstOrFail();

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/partner/services/{$service->id}")
            ->assertOk();

        $this->assertSame($service->id, $res->json('data.service_id'));
        $this->assertSame($profile->user_id, $res->json('data.customer_id'));

        // El id del servicio NO es el del cliente: es la llave que permitirá
        // varios puntos por titular cuando el modelo lo soporte.
        $this->assertNotSame($profile->user_id, $res->json('data.service_id'));
    }

    #[Test]
    public function el_servicio_no_expone_la_contrasena_pppoe(): void
    {
        $profile = $this->seedCustomer($this->tenantA, 'Luis');
        $service = UserService::where('user_id', $profile->user_id)->firstOrFail();

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/partner/services/{$service->id}")
            ->assertOk();

        $this->assertStringNotContainsString('no-debe-salir', $res->getContent());
        $this->assertSame('luis.pppoe', $res->json('data.network.pppoe_username'));
    }

    #[Test]
    public function los_servicios_se_pueden_filtrar_por_cliente(): void
    {
        $uno = $this->seedCustomer($this->tenantA, 'Mario');
        $this->seedCustomer($this->tenantA, 'Nora');

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/partner/services?customer_id={$uno->user_id}")
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($uno->user_id, $res->json('data.0.customer_id'));
    }

    #[Test]
    public function un_servicio_de_otro_tenant_responde_404_y_no_403(): void
    {
        // 403 le confirmaría al integrador que ese id existe en la plataforma.
        $ajeno   = $this->seedCustomer($this->tenantB, 'Olga');
        $service = UserService::where('user_id', $ajeno->user_id)->firstOrFail();

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/partner/services/{$service->id}")
            ->assertNotFound();
    }

    // ─── Revisión ───────────────────────────────────────────────────────────

    #[Test]
    public function la_revision_del_cliente_sigue_al_ultimo_evento(): void
    {
        $profile = $this->seedCustomer($this->tenantA, 'Pablo');
        $profile->update(['service_status' => 'suspendido']);

        $ultimo = PartnerEvent::where('customer_id', $profile->user_id)->max('id');

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/partner/customers/{$profile->user_id}")
            ->assertOk();

        $this->assertSame($ultimo, $res->json('data.revision'));
    }

    #[Test]
    public function la_revision_avanza_con_cada_cambio(): void
    {
        $profile = $this->seedCustomer($this->tenantA, 'Rosa');

        $profile->update(['service_status' => 'suspendido']);
        $primera = $this->withHeaders($this->headers())
            ->getJson("/api/v1/partner/customers/{$profile->user_id}")
            ->json('data.revision');

        $profile->update(['service_status' => 'activo']);
        $segunda = $this->withHeaders($this->headers())
            ->getJson("/api/v1/partner/customers/{$profile->user_id}")
            ->json('data.revision');

        $this->assertGreaterThan($primera, $segunda);
    }

    #[Test]
    public function la_revision_de_un_cliente_no_la_mueve_otro(): void
    {
        // Con una secuencia global es fácil equivocarse y devolver el último id
        // del feed en vez del último del recurso. Eso haría que el integrador
        // creyera que TODOS sus clientes cambiaron cada vez que cambia uno.
        $uno = $this->seedCustomer($this->tenantA, 'Sara');
        $dos = $this->seedCustomer($this->tenantA, 'Tomas');

        $uno->update(['service_status' => 'suspendido']);
        $antes = $this->withHeaders($this->headers())
            ->getJson("/api/v1/partner/customers/{$dos->user_id}")
            ->json('data.revision');

        $uno->update(['service_status' => 'activo']);
        $despues = $this->withHeaders($this->headers())
            ->getJson("/api/v1/partner/customers/{$dos->user_id}")
            ->json('data.revision');

        $this->assertSame($antes, $despues);
    }

    // ─── Búsqueda por documento ─────────────────────────────────────────────

    #[Test]
    public function se_puede_buscar_un_cliente_por_documento_exacto(): void
    {
        $profile = $this->seedCustomer($this->tenantA, 'Ursula');

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/partner/customers?document={$profile->cedula}")
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($profile->user_id, $res->json('data.0.id'));
    }

    #[Test]
    public function la_busqueda_por_documento_no_admite_coincidencia_parcial(): void
    {
        // Una búsqueda parcial sobre documentos convertiría esta ruta en un
        // enumerador de la base de clientes del ISP.
        $profile = $this->seedCustomer($this->tenantA, 'Vera');
        $parcial = substr((string) $profile->cedula, 0, 3);

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/partner/customers?document={$parcial}")
            ->assertOk();

        $this->assertCount(0, $res->json('data'));
    }
}
