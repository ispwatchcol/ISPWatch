<?php

namespace Tests\Feature\Support;

use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TicketCatalogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FASE 1 · R2 — que la clave foránea sea de verdad la fuente de lectura.
 *
 * El criterio de «hecho» de la R2 no es que la aplicación siga funcionando: es
 * que **nada dependa ya de las columnas enum**, porque esa es exactamente la
 * condición que permite eliminarlas en la R3.
 *
 * Probarlo de forma convincente exige algo más que leer el código. La técnica
 * de este archivo es CORROMPER A PROPÓSITO la columna enum —dejarla con un
 * valor distinto del que dice el catálogo— y comprobar que la aplicación sigue
 * respondiendo lo que dice la FK. Si algún camino se hubiera quedado leyendo el
 * enum, aquí saldría el valor corrupto.
 *
 * Es un escenario imposible en producción, y ese es justamente el punto: sirve
 * como detector, no como caso de uso.
 */
class TicketCatalogReadPathTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create([
            'name' => 'Administrador', 'code' => 'admin',
            'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $role->id,
        ]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $this->customer->id, 'name' => 'Nubia', 'last_name' => 'Peláez', 'status' => true,
        ]);
    }

    private function ticket(array $overrides = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->customer->id,
            'subject'   => 'Servicio intermitente',
            'status'    => 'open',
            'priority'  => 'medium',
            'category'  => 'technical',
        ], $overrides));
    }

    /**
     * Desincroniza el espejo sin tocar la clave foránea.
     *
     * Se escribe con el query builder para saltarse el modelo: pasando por
     * Eloquent, el propio mutator volvería a dejarlo coherente y el detector
     * no detectaría nada.
     */
    private function corromperElEspejo(SupportTicket $ticket, array $valores): void
    {
        DB::table('support_ticket')->where('id', $ticket->id)->update($valores);
    }

    // ── La FK manda ──────────────────────────────────────────────────────

    #[Test]
    public function el_modelo_lee_el_codigo_del_catalogo_y_no_de_la_columna_enum(): void
    {
        $ticket = $this->ticket(['status' => 'open', 'priority' => 'high', 'category' => 'technical']);

        $this->corromperElEspejo($ticket, [
            'status' => 'closed', 'priority' => 'low', 'category' => 'general',
        ]);

        $recargado = SupportTicket::find($ticket->id);

        $this->assertSame('open', $recargado->status, 'El modelo se quedó leyendo la columna enum.');
        $this->assertSame('high', $recargado->priority);
        $this->assertSame('technical', $recargado->category);
    }

    #[Test]
    public function la_api_del_panel_devuelve_lo_que_dice_la_clave_foranea(): void
    {
        $ticket = $this->ticket(['status' => 'in_progress']);
        $this->corromperElEspejo($ticket, ['status' => 'closed']);

        $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('status', 'in_progress');
    }

    #[Test]
    public function la_api_publica_devuelve_lo_que_dice_la_clave_foranea(): void
    {
        $ticket = $this->ticket(['status' => 'resolved', 'priority' => 'urgent']);
        $this->corromperElEspejo($ticket, ['status' => 'open', 'priority' => 'low']);

        $client = \App\Models\ApiClient::create([
            'tenant_id' => $this->tenant->id, 'name' => 'CNO', 'is_active' => true,
        ]);
        $token = $client->createToken('t', ['read:support']);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        $fila = $this->getJson('/api/v1/partner/tickets', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->assertOk()->json('data.0');

        $this->assertSame('resolved', $fila['status']);
        $this->assertSame('urgent', $fila['priority']);
    }

    #[Test]
    public function el_filtro_del_listado_consulta_por_clave_foranea(): void
    {
        $coherente = $this->ticket(['status' => 'open', 'subject' => 'Coherente']);
        $corrupto  = $this->ticket(['status' => 'closed', 'subject' => 'Espejo mentiroso']);

        // El espejo dice 'open' pero su FK apunta a 'closed'.
        $this->corromperElEspejo($corrupto, ['status' => 'open']);

        $filas = $this->actingAs($this->staff)->getJson('/api/support?status=open')->assertOk()->json();

        $this->assertCount(1, $filas, 'El filtro siguió consultando la columna enum.');
        $this->assertSame('Coherente', $filas[0]['subject']);
        $this->assertSame($coherente->id, $filas[0]['id']);
    }

    #[Test]
    public function las_estadisticas_agrupan_por_clave_foranea(): void
    {
        $this->ticket(['status' => 'open']);
        $corrupto = $this->ticket(['status' => 'closed']);
        $this->corromperElEspejo($corrupto, ['status' => 'open']);

        $stats = $this->actingAs($this->staff)->getJson('/api/support/statistics')->assertOk()->json();

        $this->assertSame(1, $stats['open_tickets'], 'El conteo siguió leyendo la columna enum.');
    }

    // ── R2.5: el espejo deja de escribirse ───────────────────────────────
    //
    // ACTUALIZADOS EN LA R2.5, y el cambio es el esperado. Hasta la R2 estos dos
    // tests afirmaban que la columna enum se mantenía sincronizada. Ahora
    // afirman lo contrario: que NO se toca.
    //
    // Es el paso que hace segura la R3 bajo el despliegue de App Platform, donde
    // el contenedor viejo sigue sirviendo mientras el nuevo ya migró. Si el
    // código que deja de escribir el espejo entrara en el mismo despliegue que
    // la migración que elimina las columnas, durante esa ventana el contenedor
    // viejo escribiría columnas ya inexistentes y toda escritura fallaría.

    #[Test]
    public function escribir_el_codigo_ya_no_toca_la_columna_enum(): void
    {
        $ticket = $this->ticket(['status' => 'open']);
        $espejoInicial = DB::table('support_ticket')->where('id', $ticket->id)->value('status');

        $ticket->update(['status' => 'resolved']);

        $fila = DB::table('support_ticket')->where('id', $ticket->id)->first();

        $this->assertSame(
            'resolved',
            DB::table('ticket_status')->where('id', $fila->status_id)->value('code'),
            'La clave foránea, que es la fuente de verdad, sí debe moverse.'
        );

        $this->assertSame(
            $espejoInicial,
            $fila->status,
            'La columna enum debe quedar CONGELADA. Si se movió, la R3 no es segura todavía.'
        );
    }

    #[Test]
    public function escribir_la_clave_foranea_directamente_tampoco_toca_el_espejo(): void
    {
        $ticket = $this->ticket(['status' => 'open']);
        $espejoInicial = DB::table('support_ticket')->where('id', $ticket->id)->value('status');

        // Camino que los accessors no ven: asignación masiva del id.
        $idCerrado = app(TicketCatalogs::class)->id(TicketCatalogs::STATUS, 'closed');
        $ticket->update(['status_id' => $idCerrado]);

        $fila = DB::table('support_ticket')->where('id', $ticket->id)->first();

        $this->assertSame($idCerrado, (int) $fila->status_id);
        $this->assertSame($espejoInicial, $fila->status, 'La columna enum debe quedar congelada.');
    }

    #[Test]
    public function con_el_espejo_obsoleto_la_aplicacion_sigue_respondiendo_bien(): void
    {
        // Es la consecuencia práctica de congelar el espejo: a partir de la
        // R2.5 la columna enum miente por diseño, y nada debe depender de ella.
        $ticket = $this->ticket(['status' => 'open', 'priority' => 'low']);
        $ticket->update(['status' => 'closed', 'priority' => 'urgent']);

        $espejo = DB::table('support_ticket')->where('id', $ticket->id)->first();
        $this->assertNotSame('closed', $espejo->status, 'El escenario no se preparó: el espejo no quedó obsoleto.');

        $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('priority', 'urgent');
    }

    // ── Preparación de la R3: la serialización no depende de la columna ──

    #[Test]
    public function el_json_del_ticket_conserva_las_tres_claves_por_appends(): void
    {
        // Cuando la R3 elimine las columnas, `status` dejará de estar en
        // `$attributes` y sin `$appends` DESAPARECERÍA del JSON sin dar ningún
        // error — comprobado ejecutando contra una base con las columnas ya
        // dropeadas. Declararlas en `$appends` desde la R2.5 deja ese
        // comportamiento fijado antes de que la migración pueda romperlo.
        $ticket = $this->ticket(['status' => 'in_progress', 'priority' => 'high', 'category' => 'billing']);

        $json = $ticket->fresh()->toArray();

        foreach (['status', 'priority', 'category'] as $clave) {
            $this->assertArrayHasKey($clave, $json, "`{$clave}` desapareció del JSON del modelo.");
        }

        $this->assertSame('in_progress', $json['status']);
        $this->assertSame('high', $json['priority']);
        $this->assertSame('billing', $json['category']);
    }

    // ── La validación sale del catálogo ──────────────────────────────────

    #[Test]
    public function retirar_un_estado_del_catalogo_deja_de_aceptarlo_en_la_api(): void
    {
        $ticket = $this->ticket(['status' => 'open']);

        DB::table('ticket_status')->where('code', 'closed')->update(['valid_until' => now()->subDay()]);
        app(TicketCatalogs::class)->flush();

        // La regla de validación se construye desde los códigos VIGENTES, así
        // que retirar una fila deja de aceptarla sin tocar código ni desplegar.
        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'closed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // Y lo que sigue vigente se sigue aceptando.
        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'resolved'])
            ->assertOk();
    }

    #[Test]
    public function un_ticket_con_un_estado_ya_retirado_se_sigue_consultando_bien(): void
    {
        $ticket = $this->ticket(['status' => 'closed']);

        DB::table('ticket_status')->where('code', 'closed')->update(['valid_until' => now()->subDay()]);
        app(TicketCatalogs::class)->flush();

        // Es la razón de que el retiro sea suave: el histórico no se toca.
        $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('status_label', 'Cerrado');
    }

    // ── Etiquetas ────────────────────────────────────────────────────────

    #[Test]
    public function el_ticket_viaja_con_su_etiqueta_ademas_del_codigo(): void
    {
        $ticket = $this->ticket(['status' => 'in_progress', 'priority' => 'urgent', 'category' => 'billing']);

        $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('status_label', 'En progreso')
            ->assertJsonPath('priority_label', 'Urgente')
            ->assertJsonPath('category_label', 'Facturación');
    }

    // ── Endpoint de catálogos para la SPA ────────────────────────────────

    #[Test]
    public function el_endpoint_de_catalogos_entrega_codigo_y_etiqueta_ordenados(): void
    {
        $data = $this->actingAs($this->staff)->getJson('/api/catalogs/ticket')->assertOk()->json();

        $this->assertSame(
            ['open', 'in_progress', 'resolved', 'closed'],
            collect($data['statuses'])->pluck('code')->all(),
            'El orden lo fija `weight`, no el orden de inserción.'
        );

        $this->assertSame('Abierto', $data['statuses'][0]['label']);
        $this->assertSame(['low', 'medium', 'high', 'urgent'], collect($data['priorities'])->pluck('code')->all());
        $this->assertSame(4, count($data['categories']));
        $this->assertSame(1, $data['versions']['status']);
    }

    #[Test]
    public function el_endpoint_de_catalogos_no_ofrece_filas_retiradas(): void
    {
        DB::table('ticket_priority')->where('code', 'urgent')->update(['valid_until' => now()->subDay()]);
        app(TicketCatalogs::class)->flush();

        $data = $this->actingAs($this->staff)->getJson('/api/catalogs/ticket')->assertOk()->json();

        $this->assertNotContains('urgent', collect($data['priorities'])->pluck('code')->all());
    }

    #[Test]
    public function el_endpoint_de_catalogos_exige_autenticacion(): void
    {
        $this->getJson('/api/catalogs/ticket')->assertUnauthorized();
    }
}
