<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketHistory;
use App\Models\Tenant;
use App\Models\TicketIntervention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR F1 · Intervenciones técnicas del ticket (§ 14 de la Solicitud Maestra).
 *
 * LO QUE ESTE ARCHIVO PROTEGE
 *
 * La § 14 pide que un ticket admita varias intervenciones, cada una con su
 * técnico, sus tiempos, su hallazgo, su acción, su resultado y su evidencia.
 * Pero el invariante que de verdad hay que blindar no es ése, es el de al lado:
 * el § 15.10 dice que «el cierre no debe borrar la causa sospechada, **las
 * intervenciones** ni los estados anteriores».
 *
 * De ahí que no exista borrado —ni endpoint, ni `deleted_at`, ni `delete()` en
 * el modelo— y que corregir una visita cerrada obligue a reabrirla con motivo.
 * Varios de estos tests existen sólo para que esa puerta no se abra por
 * descuido en un PR futuro.
 */
class TicketInterventionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $customer;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('s3');

        $this->tenant = Tenant::factory()->create();

        // `CheckPermission` deja pasar SIEMPRE a `role_id == 1`. Con
        // `RefreshDatabase` el primer rol creado se queda ese id, así que se
        // quema uno: sin esto, cualquier rol de prueba entraría por el bypass y
        // los casos negativos serían falsos positivos.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);

        $this->customer = $this->clienteDe($this->tenant);
        $this->staff    = $this->usuarioCon($this->permisosDeCampo());
    }

    // ── Apoyo ────────────────────────────────────────────────────────────

    private function clienteDe(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id' => $user->id, 'name' => 'Axel', 'last_name' => 'Cano', 'status' => true,
        ]);

        return $user;
    }

    /** @return array<int, string> */
    private function permisosDeCampo(): array
    {
        return [
            Permissions::TICKET_VIEW,
            Permissions::TICKET_INTERVENE,
            Permissions::TICKET_ATTACH,
            Permissions::TICKET_VIEW_EVIDENCE,
            Permissions::TICKET_VIEW_HISTORY,
            Permissions::TICKET_EDIT,
        ];
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(array $permisos, string $code = 'staff', ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;

        $rol = Role::create([
            'name'        => 'Rol ' . $code . ' ' . count($permisos) . ' ' . uniqid(),
            'code'        => $code,
            'permissions' => $permisos,
            'tenant_id'   => $tenant->id,
        ]);

        // `user_name` / `user_lastname` explícitos: el factory sólo llena `name`,
        // y el nombre congelado de la intervención sale de esas dos columnas.
        return User::factory()->create([
            'tenant_id'     => $tenant->id,
            'role_id'       => $rol->id,
            'user_name'     => 'Juan',
            'user_lastname' => 'Pérez',
        ]);
    }

    private function ticket(?Tenant $tenant = null, ?User $cliente = null): SupportTicket
    {
        $tenant ??= $this->tenant;

        return SupportTicket::create([
            'tenant_id' => $tenant->id,
            'user_id'   => ($cliente ?? $this->customer)->id,
            'subject'   => 'Sin señal desde anoche',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    /** @return array<string, mixed> */
    private function cuerpo(array $extra = []): array
    {
        // `$extra` PRIMERO: en PHP `$a + $b` conserva las claves de `$a`, así
        // que al revés los valores por defecto ganarían y `$extra` no serviría
        // para nada.
        return $extra + [
            'kind'          => TicketIntervention::KIND_PRESENCIAL,
            'technician_id' => $this->staff->id,
            'started_at'    => '2026-09-23T08:00:00',
            'finding'       => 'Cable deteriorado entre PoE y CPE.',
            'action_taken'  => 'Cambio de cable y realineación.',
            'outcome'       => 'Servicio restablecido en sitio.',
            'next_step'     => 'Vigilar 24 horas.',
        ];
    }

    private function crear(SupportTicket $ticket, array $extra = [], ?User $como = null): int
    {
        return $this->actingAs($como ?? $this->staff)
            ->postJson("/api/support/{$ticket->id}/interventions", $this->cuerpo($extra))
            ->assertCreated()
            ->json('intervention.id');
    }

    // ── Alta y correlativo ───────────────────────────────────────────────

    #[Test]
    public function un_ticket_admite_varias_intervenciones_con_numero_correlativo(): void
    {
        $ticket = $this->ticket();

        $this->crear($ticket);
        $this->crear($ticket, ['kind' => TicketIntervention::KIND_REMOTO]);
        $this->crear($ticket);

        $this->assertSame(
            [1, 2, 3],
            TicketIntervention::where('support_ticket_id', $ticket->id)
                ->orderBy('sequence')->pluck('sequence')->map(fn ($n) => (int) $n)->all(),
        );
    }

    #[Test]
    public function el_correlativo_es_por_ticket_no_global(): void
    {
        $uno = $this->ticket();
        $dos = $this->ticket();

        $this->crear($uno);
        $this->crear($uno);
        $this->crear($dos);

        // El técnico habla de «la primera visita de este ticket», no de «la
        // intervención 4 817».
        $this->assertSame(
            1,
            (int) TicketIntervention::where('support_ticket_id', $dos->id)->value('sequence'),
        );
    }

    #[Test]
    public function la_base_impide_dos_intervenciones_con_el_mismo_numero(): void
    {
        $ticket = $this->ticket();
        $this->crear($ticket);

        // Lo que pasaría si dos técnicos calcularan el correlativo a la vez.
        $this->expectException(\Illuminate\Database\QueryException::class);

        TicketIntervention::create([
            'tenant_id' => $this->tenant->id,
            'support_ticket_id' => $ticket->id,
            'sequence' => 1,
            'kind' => TicketIntervention::KIND_REMOTO,
            'started_at' => now(),
        ]);
    }

    #[Test]
    public function se_registran_intervenciones_remotas_y_presenciales(): void
    {
        $ticket = $this->ticket();

        foreach ([TicketIntervention::KIND_REMOTO, TicketIntervention::KIND_PRESENCIAL] as $tipo) {
            $this->actingAs($this->staff)
                ->postJson("/api/support/{$ticket->id}/interventions", $this->cuerpo(['kind' => $tipo]))
                ->assertCreated()
                ->assertJsonPath('intervention.kind', $tipo);
        }

        // Y nada más: la § 14 sólo nombra esos dos.
        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/interventions", $this->cuerpo(['kind' => 'telepatica']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('kind');
    }

    #[Test]
    public function la_intervencion_guarda_los_campos_de_la_seccion_14(): void
    {
        $ticket = $this->ticket();
        $acompanante = $this->usuarioCon([Permissions::TICKET_VIEW]);

        $id = $this->crear($ticket, ['assistant_id' => $acompanante->id]);

        $i = TicketIntervention::find($id);

        $this->assertSame('presencial', $i->kind);
        $this->assertSame($this->staff->id, (int) $i->technician_id);
        $this->assertSame($acompanante->id, (int) $i->assistant_id);
        $this->assertNotNull($i->started_at);
        $this->assertSame('Cable deteriorado entre PoE y CPE.', $i->finding);
        $this->assertSame('Cambio de cable y realineación.', $i->action_taken);
        $this->assertSame('Servicio restablecido en sitio.', $i->outcome);
        $this->assertSame('Vigilar 24 horas.', $i->next_step);
        $this->assertTrue($i->is_open, 'Sin `finished_at` la intervención está en curso.');
    }

    #[Test]
    public function el_nombre_del_tecnico_queda_congelado(): void
    {
        $ticket = $this->ticket();
        $id = $this->crear($ticket);

        $this->assertSame('Juan Pérez', TicketIntervention::find($id)->technician_name);
    }

    // ── Técnico y tenant ─────────────────────────────────────────────────

    #[Test]
    public function un_tecnico_de_otro_isp_se_rechaza(): void
    {
        $otro   = Tenant::factory()->create();
        $ajeno  = $this->usuarioCon([Permissions::TICKET_VIEW], 'staff', $otro);
        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/interventions", $this->cuerpo(['technician_id' => $ajeno->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('technician_id');

        $this->assertSame(0, TicketIntervention::count());
    }

    #[Test]
    public function un_acompanante_de_otro_isp_se_rechaza(): void
    {
        $otro   = Tenant::factory()->create();
        $ajeno  = $this->usuarioCon([Permissions::TICKET_VIEW], 'staff', $otro);
        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/interventions", $this->cuerpo(['assistant_id' => $ajeno->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('assistant_id');
    }

    #[Test]
    public function no_se_interviene_el_ticket_de_otro_isp(): void
    {
        $otro   = Tenant::factory()->create();
        $ajeno  = $this->ticket($otro, $this->clienteDe($otro));

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ajeno->id}/interventions", $this->cuerpo())
            ->assertNotFound();

        $this->assertSame(0, TicketIntervention::count());
    }

    #[Test]
    public function las_intervenciones_de_otro_isp_no_se_listan(): void
    {
        $otro      = Tenant::factory()->create();
        $ticketAjeno = $this->ticket($otro, $this->clienteDe($otro));
        $staffAjeno  = $this->usuarioCon($this->permisosDeCampo(), 'staff', $otro);

        $this->crear($ticketAjeno, ['technician_id' => $staffAjeno->id], como: $staffAjeno);

        $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticketAjeno->id}/interventions")
            ->assertNotFound();
    }

    // ── Ticket archivado ─────────────────────────────────────────────────

    #[Test]
    public function un_ticket_archivado_no_admite_intervenciones(): void
    {
        $ticket = $this->ticket();
        $ticket->delete(); // archivado (SoftDeletes, PR C)

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/interventions", $this->cuerpo())
            ->assertNotFound();

        $this->assertSame(0, TicketIntervention::count());
    }

    #[Test]
    public function las_intervenciones_de_un_ticket_archivado_siguen_siendo_consultables(): void
    {
        $ticket = $this->ticket();
        $this->crear($ticket);
        $ticket->delete();

        // El § 15.10 exige que el expediente no pierda sus intervenciones. Quien
        // puede ver archivados tiene que poder leerlas.
        $archivista = $this->usuarioCon([
            Permissions::TICKET_VIEW, Permissions::TICKET_ARCHIVE, Permissions::TICKET_RESTORE,
        ], 'admin');

        $this->actingAs($archivista)
            ->getJson("/api/support/{$ticket->id}/interventions")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ── Cerrojo de edición y reapertura ──────────────────────────────────

    #[Test]
    public function una_intervencion_en_curso_se_puede_editar(): void
    {
        $ticket = $this->ticket();
        $id     = $this->crear($ticket);

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}/interventions/{$id}", [
                'finding' => 'Corregido: el cable estaba bien; falló el PoE.',
            ])
            ->assertOk();

        $this->assertSame(
            'Corregido: el cable estaba bien; falló el PoE.',
            TicketIntervention::find($id)->finding,
        );
    }

    #[Test]
    public function una_intervencion_finalizada_no_se_puede_editar(): void
    {
        $ticket = $this->ticket();
        $id     = $this->crear($ticket, ['finished_at' => '2026-09-23T09:30:00']);

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}/interventions/{$id}", ['finding' => 'Otra cosa'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'intervention_finished');

        $this->assertSame(
            'Cable deteriorado entre PoE y CPE.',
            TicketIntervention::find($id)->finding,
            'El contenido no puede cambiar por un intento rechazado.',
        );
    }

    #[Test]
    public function el_modelo_tambien_bloquea_editar_una_finalizada(): void
    {
        $ticket = $this->ticket();
        $id     = $this->crear($ticket, ['finished_at' => '2026-09-23T09:30:00']);

        // El cerrojo no vive sólo en el controlador: un camino nuevo que no pase
        // por él tampoco debe poder saltárselo en silencio.
        $this->expectException(\RuntimeException::class);

        TicketIntervention::find($id)->update(['finding' => 'Por la puerta de atrás']);
    }

    #[Test]
    public function reabrir_exige_motivo_y_devuelve_la_intervencion_a_editable(): void
    {
        $ticket = $this->ticket();
        $id     = $this->crear($ticket, ['finished_at' => '2026-09-23T09:30:00']);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/interventions/{$id}/reopen", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/interventions/{$id}/reopen", ['reason' => 'corto'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/interventions/{$id}/reopen", [
                'reason' => 'El hallazgo se anotó en el ticket equivocado.',
            ])
            ->assertOk();

        $this->assertNull(TicketIntervention::find($id)->finished_at);

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}/interventions/{$id}", ['finding' => 'Corregido'])
            ->assertOk();
    }

    #[Test]
    public function reabrir_deja_auditoria_con_actor_fecha_y_motivo(): void
    {
        $ticket = $this->ticket();
        $id     = $this->crear($ticket, ['finished_at' => '2026-09-23T09:30:00']);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/interventions/{$id}/reopen", [
                'reason' => 'El técnico anotó mal la hora de cierre.',
            ])->assertOk();

        $evento = SupportTicketHistory::where('support_ticket_id', $ticket->id)
            ->where('event_type', SupportTicketHistory::INTERVENTION_REOPENED)
            ->firstOrFail();

        $this->assertSame($this->staff->id, (int) $evento->actor_user_id);
        $this->assertSame('finished_at', $evento->field);
        $this->assertNotNull($evento->old_value, 'Debe conservar cuándo estaba cerrada.');
        $this->assertNull($evento->new_value);
        $this->assertSame('El técnico anotó mal la hora de cierre.', $evento->metadata['reason']);
        $this->assertNotNull($evento->created_at);
    }

    #[Test]
    public function no_se_reabre_una_intervencion_en_curso(): void
    {
        $ticket = $this->ticket();
        $id     = $this->crear($ticket);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/interventions/{$id}/reopen", [
                'reason' => 'Intento sobre una intervención abierta.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'intervention_not_finished');
    }

    #[Test]
    public function finalizar_de_nuevo_conserva_toda_la_historia(): void
    {
        $ticket = $this->ticket();
        $id     = $this->crear($ticket, ['finished_at' => '2026-09-23T09:30:00']);

        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/interventions/{$id}/reopen", [
            'reason' => 'Faltaba registrar el próximo paso.',
        ])->assertOk();

        $this->actingAs($this->staff)->putJson("/api/support/{$ticket->id}/interventions/{$id}", [
            'next_step'   => 'Reforzar el amarre del drop.',
            'finished_at' => '2026-09-23T10:15:00',
        ])->assertOk();

        // Sólo los eventos de intervención: el historial lleva además el alta
        // del ticket y cualquier otro movimiento, que aquí son ruido.
        $eventos = SupportTicketHistory::where('support_ticket_id', $ticket->id)
            ->where('event_type', 'like', 'intervention%')
            ->orderBy('id')->pluck('event_type')->all();

        // Nada se sustituye: la cronología cuenta la historia completa.
        $this->assertSame([
            SupportTicketHistory::INTERVENTION_STARTED,
            SupportTicketHistory::INTERVENTION_FINISHED,
            SupportTicketHistory::INTERVENTION_REOPENED,
            SupportTicketHistory::INTERVENTION_FINISHED,
        ], $eventos);

        $this->assertNotNull(TicketIntervention::find($id)->finished_at);
    }

    #[Test]
    public function editar_una_intervencion_abierta_deja_evento(): void
    {
        $ticket = $this->ticket();
        $id     = $this->crear($ticket);

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}/interventions/{$id}", ['outcome' => 'Sin cambios'])
            ->assertOk();

        $this->assertSame(
            1,
            SupportTicketHistory::where('support_ticket_id', $ticket->id)
                ->where('event_type', SupportTicketHistory::INTERVENTION_EDITED)->count(),
        );
    }

    // ── Sin borrado ──────────────────────────────────────────────────────

    #[Test]
    public function ninguna_ruta_permite_borrar_una_intervencion(): void
    {
        $ticket = $this->ticket();
        $id     = $this->crear($ticket);

        $this->actingAs($this->staff)
            ->deleteJson("/api/support/{$ticket->id}/interventions/{$id}")
            ->assertStatus(405);

        $this->assertSame(1, TicketIntervention::count());
    }

    #[Test]
    public function el_modelo_prohibe_el_borrado_fisico(): void
    {
        $ticket = $this->ticket();
        $id     = $this->crear($ticket);

        // Sin `deleted_at` a propósito: un borrado blando aquí sería una puerta
        // trasera para que la visita desapareciera del expediente sin que el
        // histórico lo contara (§ 15.10).
        $this->assertFalse(
            \Schema::hasColumn('ticket_intervention', 'deleted_at'),
            'La tabla no debe tener borrado blando.',
        );

        $this->expectException(\RuntimeException::class);
        TicketIntervention::find($id)->delete();
    }

    // ── Evidencia enlazada ───────────────────────────────────────────────

    private function adjuntar(SupportTicket $ticket, string $nombre = 'evidencia.jpg'): SupportTicketAttachment
    {
        $this->actingAs($this->staff)->post("/api/support/{$ticket->id}", [
            '_method'     => 'PUT',
            'attachments' => [UploadedFile::fake()->image($nombre, 40, 30)],
        ])->assertOk();

        return SupportTicketAttachment::where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
    }

    #[Test]
    public function una_evidencia_del_ticket_se_enlaza_a_su_intervencion(): void
    {
        $ticket    = $this->ticket();
        $id        = $this->crear($ticket);
        $evidencia = $this->adjuntar($ticket);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/interventions/{$id}/evidence", [
                'attachment_id' => $evidencia->id,
                'evidence_type' => 'foto_sitio',
                'description'   => 'Cable dañado antes del cambio.',
            ])
            ->assertOk();

        $evidencia->refresh();

        $this->assertSame($id, (int) $evidencia->intervention_id);
        $this->assertSame('foto_sitio', $evidencia->evidence_type);
        $this->assertSame('Cable dañado antes del cambio.', $evidencia->description);

        // Y no se duplicó el archivo: sigue siendo la misma fila.
        $this->assertSame(1, SupportTicketAttachment::count());
    }

    #[Test]
    public function la_evidencia_de_otro_ticket_no_se_puede_enlazar(): void
    {
        $mio  = $this->ticket();
        $otro = $this->ticket();

        $id        = $this->crear($mio);
        $ajena     = $this->adjuntar($otro);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$mio->id}/interventions/{$id}/evidence", [
                'attachment_id' => $ajena->id,
            ])
            ->assertNotFound();

        $this->assertNull($ajena->fresh()->intervention_id);
    }

    #[Test]
    public function la_base_bloquea_una_evidencia_cruzada_entre_tickets(): void
    {
        // La garantía no puede depender de que ningún `where` se olvide: esta
        // tabla NO tiene `tenant_id` propio —lo deriva del ticket— así que un
        // enlace cruzado podría saltar de ISP.
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'La foránea compuesta se crea en PostgreSQL; SQLite no admite '
                . 'añadir foráneas con ALTER TABLE. El CI cubre este caso.'
            );
        }

        $mio  = $this->ticket();
        $otro = $this->ticket();

        $id    = $this->crear($mio);
        $ajena = $this->adjuntar($otro);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('support_ticket_attachment')
            ->where('id', $ajena->id)
            ->update(['intervention_id' => $id]);
    }

    #[Test]
    public function la_evidencia_no_expone_la_ruta_de_almacenamiento(): void
    {
        $ticket    = $this->ticket();
        $id        = $this->crear($ticket);
        $evidencia = $this->adjuntar($ticket);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/interventions/{$id}/evidence", [
                'attachment_id' => $evidencia->id,
            ])->assertOk();

        $fila = $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}/interventions")
            ->assertOk()
            ->json('data.0.attachments.0');

        $this->assertArrayNotHasKey('file_path', $fila, 'La ruta del bucket es interna.');
        $this->assertArrayHasKey('url', $fila);
        $this->assertStringNotContainsString('/storage/', $fila['url']);
    }

    // ── Permisos ─────────────────────────────────────────────────────────

    #[Test]
    public function sin_ticket_intervene_no_se_registra_una_intervencion(): void
    {
        $sinPermiso = $this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_EDIT]);
        $ticket     = $this->ticket();

        $this->actingAs($sinPermiso)
            ->postJson("/api/support/{$ticket->id}/interventions", $this->cuerpo())
            ->assertForbidden();

        $this->assertSame(0, TicketIntervention::count());
    }

    #[Test]
    public function sin_ticket_intervene_tampoco_se_edita_ni_se_reabre(): void
    {
        $ticket = $this->ticket();
        $id     = $this->crear($ticket, ['finished_at' => '2026-09-23T09:30:00']);

        $sinPermiso = $this->usuarioCon([Permissions::TICKET_VIEW]);

        $this->actingAs($sinPermiso)
            ->putJson("/api/support/{$ticket->id}/interventions/{$id}", ['finding' => 'x'])
            ->assertForbidden();

        $this->actingAs($sinPermiso)
            ->postJson("/api/support/{$ticket->id}/interventions/{$id}/reopen", ['reason' => 'diez caracteres'])
            ->assertForbidden();
    }

    #[Test]
    public function con_ticket_view_se_pueden_leer_las_intervenciones(): void
    {
        $ticket = $this->ticket();
        $this->crear($ticket);

        $lector = $this->usuarioCon([Permissions::TICKET_VIEW]);

        $this->actingAs($lector)
            ->getJson("/api/support/{$ticket->id}/interventions")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function sin_sesion_no_se_llega_a_las_intervenciones(): void
    {
        $ticket = $this->ticket();

        $this->getJson("/api/support/{$ticket->id}/interventions")->assertUnauthorized();
        $this->postJson("/api/support/{$ticket->id}/interventions", $this->cuerpo())->assertUnauthorized();
    }

    #[Test]
    public function el_backfill_concede_el_permiso_a_quien_ya_adjuntaba(): void
    {
        // El rol de campo del setUp tiene `ticket_attach`, así que la migración
        // de backfill debió concederle `ticket_intervene`. Es lo que evita el
        // problema de P-52: un permiso declarado y no repartido es una función
        // muerta.
        $rol = Role::find($this->staff->role_id);

        $this->assertContains(Permissions::TICKET_INTERVENE, $rol->permissions);
    }

    // ── Interfaz ─────────────────────────────────────────────────────────
    //
    // El proyecto no tiene runner de JavaScript; la interfaz se verifica sobre
    // el fuente, como ya hace `VersionConsistencyTest`.

    #[Test]
    public function la_interfaz_no_ofrece_borrar_una_intervencion(): void
    {
        $componente = file_get_contents(resource_path('js/components/TicketInterventions.vue'));
        $servicio   = file_get_contents(resource_path('js/services/api/support.js'));

        $this->assertStringNotContainsString('deleteIntervention', $servicio);
        $this->assertStringNotContainsString('apiClient.delete(`/support/${ticketId}/interventions', $servicio);

        // Ni un boton de borrar en la pantalla: la unica correccion es reabrir.
        $this->assertStringNotContainsString('Eliminar intervención', $componente);
        $this->assertStringContainsString('Reabrir', $componente);
    }

    #[Test]
    public function la_interfaz_pide_motivo_al_reabrir(): void
    {
        $componente = file_get_contents(resource_path('js/components/TicketInterventions.vue'));

        $this->assertStringContainsString('motivoReapertura', $componente);
        $this->assertStringContainsString('Entre 10 y 500 caracteres', $componente);
    }

    #[Test]
    public function el_detalle_monta_el_componente_separado(): void
    {
        $detalle = file_get_contents(resource_path('js/pages/SupportDetail.vue'));

        // Componente aparte y no un bloque mas: SupportDetail ya pasa de mil
        // ochocientas lineas. Mismo criterio que `TicketDiagnosisFields`.
        $this->assertStringContainsString('<TicketInterventions', $detalle);
        $this->assertStringContainsString("hasPermission('ticket_intervene')", $detalle);
    }

    #[Test]
    public function el_componente_cubre_carga_vacio_y_error(): void
    {
        $componente = file_get_contents(resource_path('js/components/TicketInterventions.vue'));

        $this->assertStringContainsString('Cargando intervenciones', $componente);
        $this->assertStringContainsString('No hay intervenciones registradas', $componente);
        $this->assertStringContainsString('Reintentar', $componente);
    }

    // ── No regresión ─────────────────────────────────────────────────────

    #[Test]
    public function un_ticket_antiguo_sin_intervenciones_sigue_funcionando(): void
    {
        // Fila escrita por SQL directo, como las que ya existen en producción.
        $id = DB::table('support_ticket')->insertGetId([
            'tenant_id'   => $this->tenant->id,
            'user_id'     => $this->customer->id,
            'subject'     => 'Ticket anterior al PR F1',
            'status_id'   => DB::table('ticket_status')->where('code', 'open')->value('id'),
            'priority_id' => DB::table('ticket_priority')->where('code', 'medium')->value('id'),
            'category_id' => DB::table('ticket_category')->where('code', 'technical')->value('id'),
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->staff)->getJson("/api/support/{$id}")->assertOk();

        $this->actingAs($this->staff)
            ->getJson("/api/support/{$id}/interventions")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function proponer_y_cerrar_no_exigen_intervenciones(): void
    {
        // Decisión D-15: ninguna de las diez reglas del § 15 menciona las
        // intervenciones, y un ticket resuelto en remoto puede no tener visita.
        // Exigirlas bloquearía tickets legítimos.
        $gestor = $this->usuarioCon([
            Permissions::TICKET_VIEW, Permissions::TICKET_EDIT, Permissions::TICKET_DIAGNOSE,
            Permissions::TICKET_CONFIRM_CAUSE, Permissions::TICKET_TRANSITION,
            Permissions::TICKET_CLOSE,
        ], 'admin');

        $ticket = $this->ticket();

        $this->actingAs($gestor)->putJson("/api/support/{$ticket->id}", [
            'confirmed_cause' => 'CL', 'solution' => 'AC05', 'result' => 'R02',
        ])->assertOk();

        // Camino de RESOLUCIÓN REMOTA, que es justo el caso que interesa: el
        // § 17 lo cuenta como indicador propio y la matriz permite saltar del
        // diagnóstico remoto al servicio restablecido sin pasar por la visita.
        $this->actingAs($gestor)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'en_diagnostico_remoto'])
            ->assertOk();

        $this->actingAs($gestor)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'servicio_restablecido'])
            ->assertOk();

        $this->actingAs($gestor)
            ->postJson("/api/support/{$ticket->id}/propose-closure", [
                'reason' => 'Servicio estable tras la atención remota.',
            ])
            ->assertOk();

        $this->assertSame(0, TicketIntervention::count(), 'No se creó ninguna intervención.');
    }

    #[Test]
    public function el_contrato_de_socios_no_expone_intervenciones(): void
    {
        $cliente = \App\Models\ApiClient::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Integrador', 'is_active' => true,
        ]);
        $token = $cliente->createToken('contrato', ['read:support']);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        $ticket = $this->ticket();
        $this->crear($ticket);

        $this->app['auth']->forgetGuards();

        $fila = $this->getJson('/api/v1/partner/tickets', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->assertOk()->json('data.0');

        foreach (['interventions', 'diagnosis', 'attachments', 'messages'] as $clave) {
            $this->assertArrayNotHasKey($clave, $fila, "`{$clave}` no forma parte del contrato de socios.");
        }
    }
}
