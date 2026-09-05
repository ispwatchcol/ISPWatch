<?php

namespace Tests\Feature\Support;

use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SupportTicketHistory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * PR #3 · Historial inalterable del ticket (F1-17).
 *
 * LO QUE ESTOS TESTS PROTEGEN ES EL CRITERIO LITERAL DEL CLIENTE
 * `Solicitud_Maestra_ISPwash_CNO_V1_1.docx`, sección 18:
 *
 *   «Cada cambio debe conservar fecha/hora, usuario o aplicación, estado
 *    anterior/nuevo, campo modificado y valores anteriores/nuevos. La auditoría
 *    no debe ser editable desde la operación ordinaria.»
 *
 * De ahí que se afirme el juego COMPLETO —actor, campo, valor anterior y valor
 * nuevo— en cada evento, y no sólo que «se registró algo».
 */
class TicketHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('s3');

        $this->tenant = Tenant::factory()->create();

        $role = Role::create([
            'name' => 'Administrador', 'code' => 'admin',
            'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $role->id,
        ]);

        $this->customer = $this->clienteDe($this->tenant);
    }

    private function clienteDe(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id' => $user->id, 'name' => 'Axel', 'last_name' => 'Cano', 'status' => true,
        ]);

        return $user;
    }

    /** Ticket creado por la vía normal, con el observer ya enganchado. */
    private function ticket(array $extra = [], ?Tenant $tenant = null, ?User $cliente = null): SupportTicket
    {
        $tenant ??= $this->tenant;

        return SupportTicket::create([
            'tenant_id' => $tenant->id,
            'user_id'   => ($cliente ?? $this->customer)->id,
            'subject'   => 'Sin señal desde anoche',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ] + $extra);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, SupportTicketHistory> */
    private function eventosDe(SupportTicket $ticket)
    {
        return SupportTicketHistory::withoutGlobalScopes()
            ->where('support_ticket_id', $ticket->id)
            ->orderBy('id')
            ->get();
    }

    private function ultimoDe(SupportTicket $ticket, string $tipo): ?SupportTicketHistory
    {
        return $this->eventosDe($ticket)->where('event_type', $tipo)->last();
    }

    // ── Eventos mínimos ──────────────────────────────────────────────────

    #[Test]
    public function crear_un_ticket_deja_un_evento_de_alta(): void
    {
        $this->actingAs($this->staff);

        $ticket = $this->ticket();

        $eventos = $this->eventosDe($ticket);

        $this->assertCount(1, $eventos, 'El alta es UN evento, no uno por campo.');

        $alta = $eventos->first();

        $this->assertSame(SupportTicketHistory::CREATED, $alta->event_type);
        $this->assertSame($this->staff->id, (int) $alta->actor_user_id);
        $this->assertSame('Sin señal desde anoche', $alta->metadata['subject']);
        $this->assertSame('open', $alta->metadata['status']);
        $this->assertNotNull($alta->created_at);
    }

    #[Test]
    public function el_alta_con_diagnostico_lo_deja_en_metadata(): void
    {
        $this->actingAs($this->staff);

        $ticket = $this->ticket(['symptom' => 'S02', 'confirmed_cause' => 'RF']);

        $alta = $this->eventosDe($ticket)->first();

        $this->assertSame('S02', $alta->metadata['diagnostico']['symptom']);
        $this->assertSame('RF', $alta->metadata['diagnostico']['confirmed_cause']);
    }

    #[Test]
    public function cambiar_estado_prioridad_y_categoria_deja_un_evento_por_campo(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->putJson("/api/support/{$ticket->id}", [
            'status' => 'in_progress', 'priority' => 'high', 'category' => 'billing',
        ])->assertOk();

        $esperado = [
            SupportTicketHistory::STATUS   => ['status', 'open', 'in_progress'],
            SupportTicketHistory::PRIORITY => ['priority', 'medium', 'high'],
            SupportTicketHistory::CATEGORY => ['category', 'technical', 'billing'],
        ];

        foreach ($esperado as $tipo => [$campo, $antes, $despues]) {
            $evento = $this->ultimoDe($ticket, $tipo);

            $this->assertNotNull($evento, "Falta el evento {$tipo}.");
            $this->assertSame($campo, $evento->field);
            $this->assertSame($antes, $evento->old_value);
            $this->assertSame($despues, $evento->new_value);
            $this->assertSame($this->staff->id, (int) $evento->actor_user_id);
        }
    }

    #[Test]
    public function el_evento_guarda_la_etiqueta_del_momento_ademas_del_codigo(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['status' => 'resolved'])->assertOk();

        $evento = $this->ultimoDe($ticket, SupportTicketHistory::STATUS);

        // El código es el contrato; la etiqueta es lo que vio el operador. Las
        // etiquetas son editables por diseño (R1), así que se congelan aquí:
        // reetiquetar un catálogo no puede reescribir el pasado.
        $this->assertSame('Abierto', $evento->metadata['old_label']);
        $this->assertSame('Resuelto', $evento->metadata['new_label']);
    }

    #[Test]
    public function cambiar_el_tecnico_deja_evento_con_nombre_legible(): void
    {
        $tecnico = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_name' => 'Juan', 'user_lastname' => 'Restrepo',
        ]);

        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['staff_id' => $tecnico->id])->assertOk();

        $evento = $this->ultimoDe($ticket, SupportTicketHistory::STAFF);

        $this->assertNotNull($evento);
        $this->assertSame('staff_id', $evento->field);
        $this->assertNull($evento->old_value, 'No tenía técnico asignado.');
        $this->assertSame((string) $tecnico->id, $evento->new_value);
        $this->assertSame('Juan Restrepo', $evento->metadata['new_label']);
    }

    #[Test]
    public function los_cinco_campos_de_diagnostico_dejan_evento(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->putJson("/api/support/{$ticket->id}", [
            'symptom'         => 'S02',
            'suspected_cause' => 'RF',
            'confirmed_cause' => 'CL',
            'solution'        => 'AC07',
            'result'          => 'R02',
        ])->assertOk();

        $esperado = [
            SupportTicketHistory::SYMPTOM         => ['symptom', 'S02'],
            SupportTicketHistory::SUSPECTED_CAUSE => ['suspected_cause', 'RF'],
            SupportTicketHistory::CONFIRMED_CAUSE => ['confirmed_cause', 'CL'],
            SupportTicketHistory::SOLUTION        => ['solution', 'AC07'],
            SupportTicketHistory::RESULT          => ['result', 'R02'],
        ];

        foreach ($esperado as $tipo => [$campo, $codigo]) {
            $evento = $this->ultimoDe($ticket, $tipo);

            $this->assertNotNull($evento, "Falta el evento {$tipo}.");
            $this->assertSame($campo, $evento->field);
            $this->assertNull($evento->old_value);
            $this->assertSame($codigo, $evento->new_value);
        }

        // La causa sospechada y la confirmada comparten catálogo pero son campos
        // distintos: no pueden colapsar en un solo evento.
        $this->assertNotSame(
            $this->ultimoDe($ticket, SupportTicketHistory::SUSPECTED_CAUSE)->new_value,
            $this->ultimoDe($ticket, SupportTicketHistory::CONFIRMED_CAUSE)->new_value,
        );
    }

    #[Test]
    public function borrar_un_campo_de_diagnostico_tambien_deja_evento(): void
    {
        $ticket = $this->ticket(['result' => 'R02']);

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['result' => null])->assertOk();

        $evento = $this->ultimoDe($ticket, SupportTicketHistory::RESULT);

        $this->assertSame('R02', $evento->old_value);
        $this->assertNull($evento->new_value);
    }

    #[Test]
    public function guardar_una_nota_deja_evento_sin_copiar_el_contenido(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/message", [
            'message' => 'Se revisó la ONU; potencia dentro de rango.', 'is_internal' => true,
        ])->assertCreated();

        $evento = $this->ultimoDe($ticket, SupportTicketHistory::NOTE_ADDED);

        $this->assertNotNull($evento);
        $this->assertSame($this->staff->id, (int) $evento->actor_user_id);
        $this->assertTrue($evento->metadata['is_internal']);
        $this->assertArrayHasKey('note_id', $evento->metadata);

        // El texto vive en la bitácora de trabajo, que sí es editable. Copiarlo
        // a un registro inmutable dejaría dos versiones que pueden divergir.
        $this->assertStringNotContainsString('potencia dentro de rango', json_encode($evento->metadata));
    }

    #[Test]
    public function adjuntar_un_archivo_deja_evento_sin_revelar_la_ruta(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->post("/api/support/{$ticket->id}", [
            '_method' => 'PUT',
            'attachments' => [UploadedFile::fake()->image('evidencia.jpg', 40, 30)],
        ])->assertOk();

        $evento = $this->ultimoDe($ticket, SupportTicketHistory::ATTACHMENT_ADDED);

        $this->assertNotNull($evento);
        $this->assertSame('evidencia.jpg', $evento->metadata['file_name']);
        $this->assertArrayHasKey('attachment_id', $evento->metadata);

        // La ruta del bucket es interna. El historial se pinta en pantalla, y
        // publicarla anularía el endpoint autenticado que la protege.
        $serializado = json_encode($evento->metadata);
        $this->assertStringNotContainsString('support_attachments/', $serializado);
        $this->assertStringNotContainsString('file_path', $serializado);
    }

    #[Test]
    public function generar_un_cargo_deja_evento_de_referencia(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/charge", [
            'items' => [['description' => 'Cambio de CPE', 'quantity' => 1, 'unit_price' => 120000]],
        ])->assertCreated();

        $evento = $this->ultimoDe($ticket, SupportTicketHistory::CHARGE_CREATED);

        $this->assertNotNull($evento);
        $this->assertArrayHasKey('invoice_id', $evento->metadata);

        // Referencia, no copia: el importe vive en la factura y allí cambia
        // —se anula, se paga—; congelarlo aquí dejaría una cifra que envejece.
        $this->assertArrayNotHasKey('total', $evento->metadata);
    }

    #[Test]
    public function un_cambio_del_sistema_sin_sesion_se_marca_como_system(): void
    {
        // Sin `actingAs`: es lo que ocurre desde el scheduler o un comando.
        $ticket = $this->ticket();

        $alta = $this->eventosDe($ticket)->first();

        $this->assertNull($alta->actor_user_id, 'Sin actor humano no se inventa uno.');
        $this->assertSame('system', $alta->source);
    }

    #[Test]
    public function un_cambio_desde_el_panel_se_marca_con_su_origen(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['status' => 'closed'])->assertOk();

        $evento = $this->ultimoDe($ticket, SupportTicketHistory::STATUS);

        $this->assertNotSame('system', $evento->source);
        $this->assertSame($this->staff->id, (int) $evento->actor_user_id);
    }

    // ── Actor ────────────────────────────────────────────────────────────

    #[Test]
    public function el_actor_sale_del_servidor_aunque_el_cliente_mande_otro(): void
    {
        $otro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->putJson("/api/support/{$ticket->id}", [
            'status'  => 'resolved',
            'user_id' => $otro->id,
            'actor_user_id' => $otro->id,
        ])->assertOk();

        $this->assertSame(
            $this->staff->id,
            (int) $this->ultimoDe($ticket, SupportTicketHistory::STATUS)->actor_user_id,
            'Suplantar al actor por payload no puede funcionar.',
        );
    }

    // ── Cambios reales, no payload ───────────────────────────────────────

    #[Test]
    public function guardar_sin_cambiar_nada_no_crea_eventos(): void
    {
        $ticket = $this->ticket();
        $antes = $this->eventosDe($ticket)->count();

        // La pantalla de edición reenvía el formulario ENTERO en cada guardado.
        // Si eso dejara un evento por campo, el historial sería inservible.
        $this->actingAs($this->staff)->putJson("/api/support/{$ticket->id}", [
            'subject'  => 'Sin señal desde anoche',
            'status'   => 'open',
            'priority' => 'medium',
            'category' => 'technical',
        ])->assertOk();

        $this->assertSame($antes, $this->eventosDe($ticket)->count());
    }

    #[Test]
    public function solo_el_campo_que_cambia_deja_evento(): void
    {
        $ticket = $this->ticket();
        $antes = $this->eventosDe($ticket)->count();

        $this->actingAs($this->staff)->putJson("/api/support/{$ticket->id}", [
            'status'   => 'in_progress',
            'priority' => 'medium',
            'category' => 'technical',
        ])->assertOk();

        $eventos = $this->eventosDe($ticket);

        $this->assertSame($antes + 1, $eventos->count());
        $this->assertSame(SupportTicketHistory::STATUS, $eventos->last()->event_type);
    }

    #[Test]
    public function reenviar_la_misma_nota_no_duplica_el_evento(): void
    {
        $ticket = $this->ticket();
        $cuerpo = ['message' => 'Se reinició el CPE.', 'is_internal' => true];

        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/message", $cuerpo);
        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/message", $cuerpo);

        $this->assertCount(
            1,
            $this->eventosDe($ticket)->where('event_type', SupportTicketHistory::NOTE_ADDED),
            'La guardia anti-duplicado de la nota debe evitar también el evento gemelo.',
        );
    }

    // ── Inalterabilidad ──────────────────────────────────────────────────

    #[Test]
    public function un_evento_no_se_puede_modificar(): void
    {
        $this->actingAs($this->staff);
        $ticket = $this->ticket();

        $evento = $this->eventosDe($ticket)->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inalterable');

        $evento->update(['new_value' => 'manipulado']);
    }

    #[Test]
    public function un_evento_no_se_puede_borrar(): void
    {
        $this->actingAs($this->staff);
        $ticket = $this->ticket();

        $evento = $this->eventosDe($ticket)->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inalterable');

        $evento->delete();
    }

    #[Test]
    public function no_existen_rutas_para_editar_ni_borrar_el_historial(): void
    {
        $ticket = $this->ticket();

        foreach ([['put', '/api/support/' . $ticket->id . '/history/1'],
                  ['patch', '/api/support/' . $ticket->id . '/history/1'],
                  ['delete', '/api/support/' . $ticket->id . '/history/1'],
                  ['post', '/api/support/' . $ticket->id . '/history']] as [$verbo, $ruta]) {
            $respuesta = $this->actingAs($this->staff)->json(strtoupper($verbo), $ruta);

            $this->assertContains(
                $respuesta->status(),
                [404, 405],
                "No debe existir {$verbo} {$ruta}.",
            );
        }
    }

    // ── Endpoint de lectura ──────────────────────────────────────────────

    #[Test]
    public function el_endpoint_devuelve_el_historial_mas_reciente_primero(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['status' => 'in_progress'])->assertOk();
        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['status' => 'resolved'])->assertOk();

        $datos = $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}/history")->assertOk()->json();

        $tipos = array_column($datos['data'], 'event_type');

        $this->assertSame(SupportTicketHistory::STATUS, $tipos[0]);
        $this->assertSame(SupportTicketHistory::CREATED, end($tipos));
        $this->assertSame('resolved', $datos['data'][0]['new_value']);
        $this->assertArrayHasKey('current_page', $datos, 'Debe venir paginado.');
    }

    #[Test]
    public function el_endpoint_incluye_el_actor_legible(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['status' => 'closed'])->assertOk();

        $primero = $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}/history")->assertOk()->json('data.0');

        $this->assertSame($this->staff->id, (int) $primero['actor_user_id']);
        $this->assertNotNull($primero['actor'], 'El actor debe venir resuelto para pintarlo.');
        $this->assertArrayNotHasKey('password', $primero['actor']);
    }

    #[Test]
    public function el_endpoint_exige_sesion(): void
    {
        $ticket = $this->ticket();

        $this->getJson("/api/support/{$ticket->id}/history")->assertUnauthorized();
    }

    #[Test]
    public function un_rol_sin_permiso_de_soporte_no_ve_el_historial(): void
    {
        $rol = Role::create([
            'name' => 'Cartera', 'code' => 'billing',
            'permissions' => ['view_billing'], 'tenant_id' => $this->tenant->id,
        ]);
        $usuario = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rol->id]);

        $ticket = $this->ticket();

        $this->actingAs($usuario)
            ->getJson("/api/support/{$ticket->id}/history")->assertForbidden();
    }

    // ── Multi-tenencia ───────────────────────────────────────────────────

    #[Test]
    public function no_se_lee_el_historial_de_un_ticket_de_otro_isp(): void
    {
        $otro = Tenant::factory()->create();
        $ajeno = $this->ticket([], $otro, $this->clienteDe($otro));

        $this->actingAs($this->staff)
            ->getJson("/api/support/{$ajeno->id}/history")->assertNotFound();
    }

    #[Test]
    public function los_eventos_se_estampan_con_el_tenant_del_ticket(): void
    {
        $otro = Tenant::factory()->create();
        $ajeno = $this->ticket([], $otro, $this->clienteDe($otro));

        $evento = $this->eventosDe($ajeno)->first();

        $this->assertSame(
            $otro->id,
            (int) $evento->tenant_id,
            'El tenant sale del ticket, no de quien esté autenticado.',
        );
    }

    #[Test]
    public function el_historial_de_un_isp_no_aparece_en_el_de_otro(): void
    {
        $otro = Tenant::factory()->create();
        $ajeno = $this->ticket([], $otro, $this->clienteDe($otro));
        $mio = $this->ticket();

        $datos = $this->actingAs($this->staff)
            ->getJson("/api/support/{$mio->id}/history")->assertOk()->json('data');

        foreach ($datos as $evento) {
            $this->assertSame($mio->id, (int) $evento['support_ticket_id']);
            $this->assertSame($this->tenant->id, (int) $evento['tenant_id']);
        }

        $this->assertNotEmpty($this->eventosDe($ajeno), 'El otro ticket sí tiene su propio historial.');
    }

    // ── No regresión ─────────────────────────────────────────────────────

    #[Test]
    public function un_ticket_anterior_al_pr3_sigue_cargando_y_empieza_a_auditarse(): void
    {
        // Fila escrita por SQL directo: sin pasar por el modelo, así que no tiene
        // evento de alta. Es lo que hay en producción.
        $id = DB::table('support_ticket')->insertGetId([
            'tenant_id'   => $this->tenant->id,
            'user_id'     => $this->customer->id,
            'subject'     => 'Ticket anterior al PR #3',
            'status_id'   => DB::table('ticket_status')->where('code', 'open')->value('id'),
            'priority_id' => DB::table('ticket_priority')->where('code', 'medium')->value('id'),
            'category_id' => DB::table('ticket_category')->where('code', 'technical')->value('id'),
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->staff)->getJson("/api/support/{$id}")->assertOk();

        $vacio = $this->actingAs($this->staff)
            ->getJson("/api/support/{$id}/history")->assertOk()->json('data');

        $this->assertSame([], $vacio, 'Sin historial previo, pero la pantalla carga.');

        // Y desde ahora sí se audita.
        $this->actingAs($this->staff)
            ->putJson("/api/support/{$id}", ['status' => 'resolved'])->assertOk();

        $datos = $this->actingAs($this->staff)
            ->getJson("/api/support/{$id}/history")->assertOk()->json('data');

        $this->assertCount(1, $datos);
        $this->assertSame('open', $datos[0]['old_value']);
        $this->assertSame('resolved', $datos[0]['new_value']);
    }

    #[Test]
    public function el_diagnostico_las_notas_y_los_adjuntos_siguen_funcionando(): void
    {
        $creado = $this->actingAs($this->staff)->postJson('/api/support', [
            'subject' => 'Humo del PR #3',
            'user_id' => $this->customer->id,
            'symptom' => 'S02', 'confirmed_cause' => 'NF', 'solution' => 'AC02', 'result' => 'R01',
        ])->assertCreated();

        $id = $creado->json('ticket.id');

        $this->actingAs($this->staff)->postJson("/api/support/{$id}/message", [
            'message' => 'Nota de la visita', 'is_internal' => true,
        ])->assertCreated();

        $this->actingAs($this->staff)->post("/api/support/{$id}", [
            '_method' => 'PUT',
            'attachments' => [UploadedFile::fake()->image('sp1.jpg')],
        ])->assertOk();

        $detalle = $this->actingAs($this->staff)->getJson("/api/support/{$id}")->assertOk()->json();

        $this->assertSame('S02', $detalle['diagnosis']['symptom']['code']);
        $this->assertCount(1, $detalle['messages']);
        $this->assertCount(1, $detalle['attachments']);
        $this->assertSame('open', $detalle['status']);
    }

    #[Test]
    public function las_columnas_enum_de_la_r3_no_reaparecen(): void
    {
        foreach (['status', 'priority', 'category'] as $columna) {
            $this->assertFalse(
                \Schema::hasColumn('support_ticket', $columna),
                "El PR #3 no debe reintroducir la columna `{$columna}`.",
            );
        }
    }

    #[Test]
    public function el_contrato_de_socios_no_expone_el_historial(): void
    {
        $cliente = \App\Models\ApiClient::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Integrador', 'is_active' => true,
        ]);
        $token = $cliente->createToken('contrato', ['read:support']);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        $ticket = $this->ticket();
        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['status' => 'resolved'])->assertOk();

        $this->app['auth']->forgetGuards();

        $fila = $this->getJson('/api/v1/partner/tickets', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->assertOk()->json('data.0');

        foreach (['history', 'events', 'audit', 'messages', 'attachments', 'diagnosis'] as $clave) {
            $this->assertArrayNotHasKey($clave, $fila, "`{$clave}` no forma parte del contrato de socios.");
        }

        // Y tampoco existe NINGUNA ruta de historial bajo /v1/partner.
        //
        // Se comprueba sobre el enrutador y no pidiendo la URL: `web.php` tiene
        // un catch-all del SPA que atiende cualquier ruta no registrada —también
        // bajo `/api`— y responde 200 con el HTML. Un `assertNotFound()` aquí
        // pasaría o fallaría por una razón que no es la que se quiere probar.
        $rutasDeSocios = collect(\Route::getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter(fn (string $uri) => str_contains($uri, 'v1/partner'));

        $this->assertNotEmpty($rutasDeSocios, 'La API de socios debe existir.');

        foreach ($rutasDeSocios as $uri) {
            $this->assertStringNotContainsString(
                'history',
                $uri,
                "La ruta `{$uri}` expondría el historial al integrador (decisión D-07).",
            );
        }
    }

    // ── Interfaz ─────────────────────────────────────────────────────────

    #[Test]
    public function la_pantalla_de_detalle_pinta_el_historial(): void
    {
        $fuente = file_get_contents(resource_path('js/pages/SupportDetail.vue'));

        $this->assertStringContainsString('Historial', $fuente);
        $this->assertStringContainsString('cargarHistorial', $fuente);
        $this->assertStringContainsString('America/Bogota', $fuente);
        $this->assertStringContainsString('etiquetaDeEvento', $fuente);

        // Sin ruta de almacenamiento en pantalla.
        $this->assertStringNotContainsString('file_path', $fuente);
    }
}
