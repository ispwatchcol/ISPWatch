<?php

namespace Tests\Feature\Support;

use App\Models\ApiClient;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TicketCatalogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FASE 1 · R3 — la aplicación funciona SIN las columnas enum.
 *
 * La suite corre con todas las migraciones aplicadas, así que estos tests se
 * ejecutan contra un esquema donde `support_ticket.status`, `.priority` y
 * `.category` YA NO EXISTEN. Eso es lo que los hace valer: no simulan el estado
 * final, están en él.
 *
 * Lo que se protege aquí, en orden de lo que más caro sería descubrir tarde:
 *
 *   1. Que `status`, `priority` y `category` sigan apareciendo en el JSON. Al
 *      dejar de ser columnas dejan de estar en `$attributes`, y sin `$appends`
 *      desaparecerían de la respuesta SIN NINGÚN ERROR. Se rompería el frontend
 *      en silencio.
 *   2. Que la API pública siga emitiendo el código como cadena.
 *   3. Que crear y actualizar tickets funcione sin las columnas físicas.
 */
class TicketEnumColumnsDroppedTest extends TestCase
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
            'user_id' => $this->customer->id, 'name' => 'Zoraida', 'last_name' => 'Mena', 'status' => true,
        ]);
    }

    private function ticket(array $overrides = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->customer->id,
            'subject'   => 'Sin navegación',
            'status'    => 'open',
            'priority'  => 'medium',
            'category'  => 'technical',
        ], $overrides));
    }

    // ── El esquema ───────────────────────────────────────────────────────

    #[Test]
    public function las_tres_columnas_enum_ya_no_existen(): void
    {
        foreach (['status', 'priority', 'category'] as $columna) {
            $this->assertFalse(
                Schema::hasColumn('support_ticket', $columna),
                "La columna `{$columna}` sigue existiendo: la R3 no se aplicó."
            );
        }
    }

    #[Test]
    public function las_columnas_de_catalogo_y_las_nuevas_siguen_intactas(): void
    {
        foreach ([
            'status_id', 'priority_id', 'category_id',
            'symptom_id', 'suspected_cause_id', 'confirmed_cause_id', 'solution_id', 'result_id',
            'closed_at', 'resolved_at', 'sectorial_id',
        ] as $columna) {
            $this->assertTrue(
                Schema::hasColumn('support_ticket', $columna),
                "La R3 se llevó por delante `{$columna}`, que debía quedarse."
            );
        }
    }

    // ── Serialización: lo que más silenciosamente podría romperse ────────

    #[Test]
    public function el_json_del_modelo_conserva_las_tres_claves_como_cadena(): void
    {
        $json = $this->ticket(['status' => 'in_progress', 'priority' => 'urgent', 'category' => 'billing'])
            ->fresh()->toArray();

        foreach (['status' => 'in_progress', 'priority' => 'urgent', 'category' => 'billing'] as $clave => $codigo) {
            $this->assertArrayHasKey($clave, $json, "`{$clave}` desapareció del JSON al dropear la columna.");
            $this->assertIsString($json[$clave]);
            $this->assertSame($codigo, $json[$clave]);
        }
    }

    #[Test]
    public function el_detalle_del_panel_devuelve_codigo_y_etiqueta(): void
    {
        $ticket = $this->ticket(['status' => 'resolved', 'priority' => 'low', 'category' => 'services']);

        $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('status', 'resolved')
            ->assertJsonPath('priority', 'low')
            ->assertJsonPath('category', 'services')
            ->assertJsonPath('status_label', 'Resuelto')
            ->assertJsonPath('priority_label', 'Baja')
            ->assertJsonPath('category_label', 'Servicios');
    }

    #[Test]
    public function el_listado_del_panel_tambien_las_conserva(): void
    {
        $this->ticket(['status' => 'closed']);

        $fila = $this->actingAs($this->staff)->getJson('/api/support')->assertOk()->json('0');

        $this->assertSame('closed', $fila['status']);
        $this->assertSame('Cerrado', $fila['status_label']);
    }

    // ── Contrato con el integrador ───────────────────────────────────────

    #[Test]
    public function la_api_publica_sigue_emitiendo_el_codigo_como_cadena(): void
    {
        $this->ticket(['status' => 'in_progress', 'priority' => 'high', 'category' => 'technical']);

        $client = ApiClient::create([
            'tenant_id' => $this->tenant->id, 'name' => 'CNO', 'is_active' => true,
        ]);
        $token = $client->createToken('r3', ['read:support']);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        $fila = $this->getJson('/api/v1/partner/tickets', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->assertOk()->json('data.0');

        foreach (['status' => 'in_progress', 'priority' => 'high', 'category' => 'technical'] as $clave => $codigo) {
            $this->assertIsString($fila[$clave], "`{$clave}` dejó de ser cadena en la API pública.");
            $this->assertFalse(is_numeric($fila[$clave]), "`{$clave}` viaja como número.");
            $this->assertSame($codigo, $fila[$clave]);
        }
    }

    // ── Escritura sin columnas físicas ───────────────────────────────────

    #[Test]
    public function se_crea_un_ticket_por_la_api_sin_las_columnas_fisicas(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/support', [
            'subject'  => 'La ONU parpadea en rojo',
            'category' => 'technical',
            'user_id'  => $this->customer->id,
        ])->assertCreated();

        $ticket = SupportTicket::find($response->json('ticket.id'));

        $this->assertSame('open', $ticket->status);
        $this->assertSame('medium', $ticket->priority);
        $this->assertNotNull($ticket->status_id, 'La clave foránea debe resolverse al crear.');
    }

    #[Test]
    public function se_cambia_el_estado_por_la_api_sin_las_columnas_fisicas(): void
    {
        $ticket = $this->ticket(['status' => 'open']);

        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('ticket.status', 'resolved');

        $ticket->refresh();

        $this->assertSame('resolved', $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertSame(
            'resolved',
            DB::table('ticket_status')->where('id', $ticket->status_id)->value('code'),
        );
    }

    #[Test]
    public function la_asignacion_masiva_del_codigo_sigue_resolviendo_la_clave_foranea(): void
    {
        // `status` ya no es columna pero sigue en `$fillable`: quitarlo de ahí
        // haría que la asignación masiva lo descartara y el ticket naciera sin
        // estado. Este test es el que avisaría de ese descuido.
        $ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->customer->id,
            'subject'   => 'Alta masiva',
            'status'    => 'closed',
            'priority'  => 'urgent',
            'category'  => 'general',
        ]);

        $this->assertNotNull($ticket->status_id);
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertSame('urgent', $ticket->fresh()->priority);
    }

    #[Test]
    public function escribir_la_clave_foranea_directamente_tambien_funciona(): void
    {
        $ticket = $this->ticket(['status' => 'open']);

        $idCerrado = app(TicketCatalogs::class)->id(TicketCatalogs::STATUS, 'closed');
        $ticket->update(['status_id' => $idCerrado]);

        $this->assertSame('closed', $ticket->fresh()->status);
    }

    #[Test]
    public function los_filtros_y_las_estadisticas_siguen_funcionando(): void
    {
        $this->ticket(['status' => 'open', 'subject' => 'Abierto']);
        $this->ticket(['status' => 'closed', 'subject' => 'Cerrado']);

        $filtrados = $this->actingAs($this->staff)->getJson('/api/support?status=open')->assertOk()->json();
        $this->assertCount(1, $filtrados);
        $this->assertSame('Abierto', $filtrados[0]['subject']);

        $stats = $this->actingAs($this->staff)->getJson('/api/support/statistics')->assertOk()->json();
        $this->assertSame(2, $stats['total_tickets']);
        $this->assertSame(1, $stats['open_tickets']);
    }

    #[Test]
    public function el_correo_de_notificacion_se_arma_sin_las_columnas(): void
    {
        // La plantilla lee `$ticket->status` y `$ticket->status_label`. Con las
        // columnas dropeadas, ambos salen del catálogo.
        $ticket = $this->ticket(['status' => 'open']);

        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'in_progress'])
            ->assertOk();

        Mail::assertSent(\App\Mail\SendTicketNotification::class, function ($mail) {
            // Renderizar es lo que de verdad prueba que la plantilla no toca
            // ninguna columna inexistente.
            return str_contains($mail->render(), 'En progreso');
        });
    }
}
