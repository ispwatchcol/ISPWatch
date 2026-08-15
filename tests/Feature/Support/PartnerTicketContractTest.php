<?php

namespace Tests\Feature\Support;

use App\Models\ApiClient;
use App\Models\CustomerProfile;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CONTRATO CONGELADO de GET /api/v1/partner/tickets.
 *
 * Este archivo existe por una razón muy concreta y conviene no perderla de
 * vista al refactorizar: la API pública es un contrato con un integrador
 * externo que ya la consume. Hoy `status`, `priority` y `category` salen como
 * CADENA (el código del valor: "open", "high", "technical") porque se leen
 * directamente de las columnas enum de `support_ticket`.
 *
 * La reestructuración del módulo sustituye esos enums por catálogos con clave
 * foránea. El modo de fallo que este test previene es exactamente ese: que al
 * migrar a `status_id` la respuesta empiece a devolver **enteros**. Para el
 * integrador eso no es un cambio de tipo, es una rotura silenciosa — su
 * `if (status === 'open')` deja de coincidir sin lanzar ningún error, y el
 * síntoma aparece días después como "no me llegan los tickets abiertos".
 *
 * Por eso el test se escribe ANTES de tocar el controlador, contra el
 * comportamiento actual: si pasa hoy y sigue pasando después de la migración,
 * el contrato se respetó. No mide la implementación, mide lo que ve el cliente.
 *
 * Regla para quien venga después: este archivo NO se ajusta para que pase.
 * Si falla tras un cambio, el que está mal es el cambio.
 */
class PartnerTicketContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private string $token;

    /**
     * Claves exactas que el integrador recibe hoy por ticket. Se afirma el
     * conjunto COMPLETO y no sólo la presencia de algunas: agregar un campo es
     * compatible hacia atrás, pero quitar o renombrar uno no lo es, y sin
     * comparación estricta un `unset()` accidental pasaría desapercibido.
     */
    private const CLAVES_DEL_TICKET = [
        'id', 'customer_id', 'subject', 'description', 'status', 'priority',
        'category', 'resolved_at', 'created_at', 'updated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $client = ApiClient::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'CNO — plataforma de diagnóstico',
            'is_active' => true,
        ]);

        // Llave real, no Sanctum::actingAs: ese helper inyecta un TransientToken
        // que EnsureApiKeyRequest rechaza, así que no probaría la cadena real.
        $token = $client->createToken('contrato', ['read:support']);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        $this->token = $token->plainTextToken;
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    private function ticket(array $overrides = []): SupportTicket
    {
        $customer = User::factory()->create(['tenant_id' => $this->tenant->id]);

        CustomerProfile::create([
            'user_id' => $customer->id, 'name' => 'Elsa', 'last_name' => 'Quintero', 'status' => true,
        ]);

        return SupportTicket::create(array_merge([
            'tenant_id'   => $this->tenant->id,
            'user_id'     => $customer->id,
            'subject'     => 'Sin señal desde anoche',
            'description' => 'El abonado reporta LED rojo en la ONU.',
            'status'      => 'open',
            'priority'    => 'high',
            'category'    => 'technical',
        ], $overrides));
    }

    // ── Lo que este archivo protege ──────────────────────────────────────

    #[Test]
    public function status_priority_y_category_salen_como_cadena_no_como_entero(): void
    {
        $this->ticket();

        $fila = $this->getJson('/api/v1/partner/tickets', $this->headers())
            ->assertOk()
            ->json('data.0');

        foreach (['status' => 'open', 'priority' => 'high', 'category' => 'technical'] as $campo => $codigo) {
            $this->assertIsString(
                $fila[$campo],
                "El campo `{$campo}` dejó de ser una cadena. Si esto falla tras migrar a catálogos, "
                . 'la respuesta está devolviendo el id de la fila en vez de su código estable.'
            );

            $this->assertSame(
                $codigo,
                $fila[$campo],
                "El campo `{$campo}` debe seguir devolviendo el código estable, no la etiqueta visible."
            );
        }
    }

    #[Test]
    public function el_codigo_nunca_es_numerico_ni_siquiera_como_cadena(): void
    {
        $this->ticket();

        $fila = $this->getJson('/api/v1/partner/tickets', $this->headers())
            ->assertOk()
            ->json('data.0');

        // Cinturón y tirantes: `assertIsString` sola dejaría pasar un "3" —
        // que es justo lo que produce castear un id a cadena al serializar.
        foreach (['status', 'priority', 'category'] as $campo) {
            $this->assertFalse(
                is_numeric($fila[$campo]),
                "El campo `{$campo}` trae un valor numérico ('{$fila[$campo]}'). "
                . 'Parece el id del catálogo serializado como cadena, no el código.'
            );
        }
    }

    #[Test]
    public function la_forma_de_la_respuesta_no_cambia(): void
    {
        $this->ticket();

        $fila = $this->getJson('/api/v1/partner/tickets', $this->headers())
            ->assertOk()
            ->json('data.0');

        $this->assertEqualsCanonicalizing(
            self::CLAVES_DEL_TICKET,
            array_keys($fila),
            'Cambió el juego de claves del ticket. Agregar campos es compatible; '
            . 'quitarlos o renombrarlos rompe al integrador.'
        );
    }

    #[Test]
    public function los_cuatro_estados_y_las_cuatro_prioridades_viajan_como_codigo(): void
    {
        foreach (['open', 'in_progress', 'resolved', 'closed'] as $estado) {
            $this->ticket(['status' => $estado, 'subject' => 'Estado ' . $estado]);
        }

        $filas = collect(
            $this->getJson('/api/v1/partner/tickets?per_page=100', $this->headers())
                ->assertOk()
                ->json('data')
        );

        $this->assertEqualsCanonicalizing(
            ['open', 'in_progress', 'resolved', 'closed'],
            $filas->pluck('status')->all(),
            'Los cuatro códigos de estado deben viajar tal cual por la API.'
        );
    }

    #[Test]
    public function el_filtro_por_status_sigue_aceptando_el_codigo_en_texto(): void
    {
        $this->ticket(['status' => 'open',   'subject' => 'Abierto']);
        $this->ticket(['status' => 'closed', 'subject' => 'Cerrado']);

        // El integrador filtra por código, no por id: si la migración cambiara
        // el filtro a entero, sus consultas devolverían vacío en silencio.
        $filas = $this->getJson('/api/v1/partner/tickets?status=open', $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $filas);
        $this->assertSame('Abierto', $filas[0]['subject']);
        $this->assertSame('open', $filas[0]['status']);
    }

    #[Test]
    public function el_filtro_por_priority_sigue_aceptando_el_codigo_en_texto(): void
    {
        $this->ticket(['priority' => 'urgent', 'subject' => 'Urgente']);
        $this->ticket(['priority' => 'low',    'subject' => 'Baja']);

        $filas = $this->getJson('/api/v1/partner/tickets?priority=urgent', $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $filas);
        $this->assertSame('Urgente', $filas[0]['subject']);
        $this->assertSame('urgent', $filas[0]['priority']);
    }

    #[Test]
    public function la_envoltura_de_paginacion_se_mantiene(): void
    {
        $this->ticket();

        $this->getJson('/api/v1/partner/tickets', $this->headers())
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'status', 'priority', 'category']],
                'meta' => ['page', 'per_page', 'total', 'last_page'],
            ]);
    }

    #[Test]
    public function updated_since_sigue_siendo_el_mecanismo_de_consulta_incremental(): void
    {
        $viejo = $this->ticket(['subject' => 'Antiguo']);
        $viejo->forceFill(['updated_at' => now()->subMonth()])->save();

        $this->ticket(['subject' => 'Reciente']);

        $filas = $this->getJson(
            '/api/v1/partner/tickets?updated_since=' . now()->subWeek()->toDateString(),
            $this->headers()
        )->assertOk()->json('data');

        $this->assertCount(1, $filas);
        $this->assertSame('Reciente', $filas[0]['subject']);
    }
}
