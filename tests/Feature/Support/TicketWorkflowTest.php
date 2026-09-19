<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SupportTicketHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TicketWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El ciclo de vida del ticket, tal como lo pide la Solicitud Maestra.
 *
 * QUÉ CIERRA ESTO
 *
 * Hasta ahora el estado se movía desde el formulario de edición y desde
 * `PATCH .../status` sin comprobar de dónde venía. Se podía saltar de recién
 * radicado a cerrado sin causa confirmada, sin acción y sin resultado — las tres
 * primeras reglas del §15— y el historial sólo dejaba constancia de que alguien
 * lo había hecho.
 *
 * FUENTE: Solicitud_Maestra_ISPwash_CNO_V1_1.docx §7 (los nueve estados del
 * flujo y los nueve auxiliares), §15 (reglas obligatorias de cierre) y §18
 * (quién propone y quién cierra). CNO confirmó estados y transiciones por chat
 * el 11/09/2026.
 */
class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $supervisor;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('s3');

        $this->tenant = Tenant::factory()->create();

        // `CheckPermission` deja pasar SIEMPRE a `role_id == 1`. Con
        // `RefreshDatabase` el primer rol creado se lleva ese id, así que se
        // quema uno: sin esto los casos negativos serían falsos positivos.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);

        $this->customer   = $this->clienteDe($this->tenant);
        $this->supervisor = $this->usuarioCon($this->permisosDeSupervisor());
    }

    // ── Andamiaje ────────────────────────────────────────────────────────

    private function clienteDe(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id' => $user->id, 'name' => 'Axel', 'last_name' => 'Cano', 'status' => true,
        ]);

        return $user;
    }

    /** @return array<int, string> */
    private function permisosDeSupervisor(): array
    {
        return [
            Permissions::TICKET_VIEW, Permissions::TICKET_CREATE, Permissions::TICKET_EDIT,
            Permissions::TICKET_DIAGNOSE, Permissions::TICKET_CONFIRM_CAUSE,
            Permissions::TICKET_VIEW_HISTORY, Permissions::TICKET_TRANSITION,
            Permissions::TICKET_CLOSE, Permissions::TICKET_CLOSE_OVERRIDE,
            Permissions::TICKET_REOPEN,
        ];
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(array $permisos, string $code = 'staff'): User
    {
        $rol = Role::create([
            'name' => 'Rol ' . uniqid(), 'code' => $code,
            'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
        ]);

        return User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rol->id]);
    }

    /**
     * Ticket en el estado pedido, escrito DIRECTO en base.
     *
     * A propósito: recorrer el flujo por la API para cada caso haría que un
     * fallo de transición reventara veinte tests a la vez y ocultara cuál es el
     * que falla de verdad.
     */
    private function ticket(string $estado = TicketWorkflow::RADICADO, array $extra = []): SupportTicket
    {
        return SupportTicket::create($extra + [
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->customer->id,
            'subject'   => 'Sin señal desde anoche',
            'status'    => $estado, 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    /** Un ticket con el expediente completo: causa confirmada, acción y resultado. */
    private function ticketCerrable(string $estado = TicketWorkflow::SERVICIO_RESTABLECIDO): SupportTicket
    {
        return $this->ticket($estado, [
            'confirmed_cause' => 'RF',
            'solution'        => 'AC02',
            'result'          => 'R01',
        ]);
    }

    private function mover(SupportTicket $ticket, string $destino, ?User $como = null)
    {
        return $this->actingAs($como ?? $this->supervisor)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => $destino]);
    }

    /** @return array<int, SupportTicketHistory> */
    private function eventos(SupportTicket $ticket, ?string $tipo = null): array
    {
        return SupportTicketHistory::withoutGlobalScopes()
            ->where('support_ticket_id', $ticket->id)
            ->when($tipo, fn ($q) => $q->where('event_type', $tipo))
            ->orderBy('id')
            ->get()->all();
    }

    private const MOTIVO = 'La falla reaparece en el mismo servicio tras la visita.';

    // ── Los estados salen del documento ──────────────────────────────────

    #[Test]
    public function el_catalogo_trae_los_nueve_del_flujo_y_los_nueve_auxiliares(): void
    {
        // § 7 · Ciclo de vida requerido. Si alguien renombra un código, esto
        // falla antes de que llegue a producción con tickets apuntando a él.
        $flujo = [
            'radicado', 'en_clasificacion', 'en_diagnostico_remoto', 'asignado',
            'visita_programada', 'en_intervencion', 'servicio_restablecido',
            'en_observacion', 'cerrado',
        ];

        $auxiliares = [
            'pendiente_cliente', 'pendiente_material', 'pendiente_tercero',
            'pendiente_infraestructura', 'asociado_incidente_masivo', 'duplicado',
            'no_fue_posible_contactar', 'solucion_temporal', 'reabierto',
        ];

        $this->assertCount(9, $flujo);
        $this->assertCount(9, $auxiliares);

        $this->assertEqualsCanonicalizing(
            $flujo,
            DB::table('ticket_status')->where('flow_category', 'main')->pluck('code')->all(),
        );

        $this->assertEqualsCanonicalizing(
            $auxiliares,
            DB::table('ticket_status')->where('flow_category', 'auxiliary')->pluck('code')->all(),
        );

        // Cada uno con etiqueta en español, peso y vigencia.
        foreach (DB::table('ticket_status')->get() as $fila) {
            $this->assertNotEmpty($fila->label, "El estado {$fila->code} necesita etiqueta.");
            $this->assertNotNull($fila->weight);
            $this->assertNotNull($fila->valid_from);
            $this->assertNotEmpty($fila->flow_category);
        }
    }

    #[Test]
    public function restablecido_no_es_lo_mismo_que_cerrado(): void
    {
        // El documento le dedica un recuadro entero: «Servicio restablecido
        // registra el momento en que vuelve la conectividad. Cerrado significa
        // que la causa, la acción, las pruebas finales, el resultado y la
        // validación quedaron documentados. Deben existir timestamps separados».
        $restablecido = DB::table('ticket_status')->where('code', 'servicio_restablecido')->first();
        $cerrado      = DB::table('ticket_status')->where('code', 'cerrado')->first();

        $this->assertTrue((bool) $restablecido->stamps_resolved_at);
        $this->assertFalse((bool) $restablecido->stamps_closed_at);
        $this->assertFalse((bool) $restablecido->is_terminal, 'Restablecido NO cierra el ticket.');

        $this->assertTrue((bool) $cerrado->stamps_closed_at);
        $this->assertTrue((bool) $cerrado->is_terminal);
    }

    // ── Matriz de transiciones ───────────────────────────────────────────

    /** @return array<string, array{string, string, bool}> */
    public static function matrizDeTransiciones(): array
    {
        return [
            // El flujo del §7, paso a paso.
            'radicado → en clasificación'            => ['radicado', 'en_clasificacion', true],
            'en clasificación → diagnóstico remoto'  => ['en_clasificacion', 'en_diagnostico_remoto', true],
            'diagnóstico remoto → asignado'          => ['en_diagnostico_remoto', 'asignado', true],
            'asignado → visita programada'           => ['asignado', 'visita_programada', true],
            'visita programada → intervención'       => ['visita_programada', 'en_intervencion', true],
            'intervención → servicio restablecido'   => ['en_intervencion', 'servicio_restablecido', true],
            'restablecido → en observación'          => ['servicio_restablecido', 'en_observacion', true],

            // «(cuando aplique)» y «(opcional)»: los dos se pueden saltar.
            'asignado → intervención (sin visita)'   => ['asignado', 'en_intervencion', true],
            'diagnóstico remoto → restablecido'      => ['en_diagnostico_remoto', 'servicio_restablecido', true],

            // Vueltas atrás dentro del tramo operativo.
            'visita → diagnóstico remoto'            => ['visita_programada', 'en_diagnostico_remoto', true],
            'restablecido → intervención'            => ['servicio_restablecido', 'en_intervencion', true],

            // Pausas y reanudación.
            'intervención → pendiente de material'   => ['en_intervencion', 'pendiente_material', true],
            'pendiente de material → intervención'   => ['pendiente_material', 'en_intervencion', true],
            'pendiente del cliente → asignado'       => ['pendiente_cliente', 'asignado', true],

            // Saltos que NO se permiten.
            'radicado → intervención'                => ['radicado', 'en_intervencion', false],
            'radicado → restablecido'                => ['radicado', 'servicio_restablecido', false],
            'clasificación → intervención'           => ['en_clasificacion', 'en_intervencion', false],
            'pendiente de material → radicado'       => ['pendiente_material', 'radicado', false],
            'pendiente de material → clasificación'  => ['pendiente_material', 'en_clasificacion', false],

            // De un terminal no se sale por transición.
            'cerrado → intervención'                 => ['cerrado', 'en_intervencion', false],
            'cerrado → radicado'                     => ['cerrado', 'radicado', false],
            'duplicado → asignado'                   => ['duplicado', 'asignado', false],

            // Los cuatro viejos entran al flujo, pero no se vuelve a ellos.
            'open → clasificación'                   => ['open', 'en_clasificacion', true],
            'in_progress → intervención'             => ['in_progress', 'en_intervencion', true],
            'clasificación → open'                   => ['en_clasificacion', 'open', false],
            'intervención → in_progress'             => ['en_intervencion', 'in_progress', false],
        ];
    }

    #[Test]
    #[DataProvider('matrizDeTransiciones')]
    public function la_matriz_permite_y_deniega_lo_que_dice_el_documento(string $desde, string $hacia, bool $permitida): void
    {
        $ticket = $this->ticket($desde);

        $respuesta = $this->mover($ticket, $hacia);

        if ($permitida) {
            $respuesta->assertOk();
            $this->assertSame($hacia, $ticket->fresh()->status);

            return;
        }

        $respuesta->assertStatus(422)->assertJsonPath('error', 'ticket_transition_not_allowed');
        $this->assertSame($desde, $ticket->fresh()->status, 'Una transición denegada no mueve nada.');
    }

    #[Test]
    public function repetir_el_mismo_estado_no_genera_evento(): void
    {
        $ticket = $this->ticket(TicketWorkflow::ASIGNADO);
        $antes  = count($this->eventos($ticket));

        $this->mover($ticket, TicketWorkflow::ASIGNADO)
            ->assertStatus(422)
            ->assertJsonPath('error', 'ticket_same_status');

        $this->assertCount($antes, $this->eventos($ticket), 'Repetir el estado no audita nada.');
    }

    #[Test]
    public function la_transicion_registra_actor_estado_anterior_y_nuevo(): void
    {
        $ticket = $this->ticket();

        $this->mover($ticket, TicketWorkflow::EN_CLASIFICACION)->assertOk();

        $evento = $this->eventos($ticket, SupportTicketHistory::STATUS)[0];

        $this->assertSame('status', $evento->field);
        $this->assertSame('radicado', $evento->old_value);
        $this->assertSame('en_clasificacion', $evento->new_value);
        $this->assertSame($this->supervisor->id, (int) $evento->actor_user_id);
        $this->assertNotSame('system', $evento->source);
        $this->assertNotNull($evento->created_at);
        // Las etiquetas del momento quedan congeladas (R1: son editables).
        $this->assertSame('Radicado', $evento->metadata['old_label']);
        $this->assertSame('En clasificación', $evento->metadata['new_label']);
    }

    #[Test]
    public function el_motivo_de_una_transicion_queda_registrado(): void
    {
        $ticket = $this->ticket(TicketWorkflow::EN_INTERVENCION);

        $this->actingAs($this->supervisor)
            ->patchJson("/api/support/{$ticket->id}/status", [
                'status' => TicketWorkflow::PENDIENTE_MATERIAL,
                'reason' => 'Falta una ONU del modelo que pide la instalación.',
            ])
            ->assertOk();

        $nota = $this->eventos($ticket, SupportTicketHistory::TRANSITION_NOTE)[0];

        $this->assertSame('Falta una ONU del modelo que pide la instalación.', $nota->metadata['reason']);
        $this->assertSame('en_intervencion', $nota->metadata['from']);
        $this->assertSame('pendiente_material', $nota->metadata['to']);
    }

    // ── Permisos ─────────────────────────────────────────────────────────

    #[Test]
    public function sin_ticket_transition_no_se_mueve_el_estado(): void
    {
        $ticket = $this->ticket();
        $sinPermiso = $this->usuarioCon([Permissions::TICKET_VIEW]);

        $this->mover($ticket, TicketWorkflow::EN_CLASIFICACION, $sinPermiso)
            ->assertForbidden()
            ->assertJsonPath('required_permission', 'ticket_transition');

        $this->assertSame('radicado', $ticket->fresh()->status);
    }

    #[Test]
    public function cada_operacion_del_cierre_exige_su_propio_permiso(): void
    {
        $casos = [
            ['propose-closure', Permissions::TICKET_TRANSITION],
            ['close',           Permissions::TICKET_CLOSE],
            ['close-exception', Permissions::TICKET_CLOSE_OVERRIDE],
            ['reopen',          Permissions::TICKET_REOPEN],
        ];

        foreach ($casos as [$ruta, $permiso]) {
            $ticket = $this->ticketCerrable();

            // Todos los permisos MENOS el que toca.
            $sinEse = $this->usuarioCon(array_values(array_diff($this->permisosDeSupervisor(), [$permiso])));

            $this->actingAs($sinEse)
                ->postJson("/api/support/{$ticket->id}/{$ruta}", ['reason' => self::MOTIVO])
                ->assertForbidden()
                ->assertJsonPath('required_permission', $permiso, "La ruta {$ruta} debe exigir {$permiso}.");
        }
    }

    // ── Propuesta de cierre ──────────────────────────────────────────────

    #[Test]
    public function la_propuesta_de_cierre_no_cierra_el_ticket(): void
    {
        $ticket = $this->ticketCerrable();

        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$ticket->id}/propose-closure", [
                'reason' => 'Se cambió el conector y la señal quedó en -21 dBm.',
            ])
            ->assertOk();

        $ticket->refresh();

        // Ése es todo el punto: separa a quien hizo el trabajo de quien
        // acredita que está bien hecho.
        $this->assertSame(TicketWorkflow::EN_OBSERVACION, $ticket->status);
        $this->assertNull($ticket->closed_at, 'Proponer NO cierra.');

        $evento = $this->eventos($ticket, SupportTicketHistory::CLOSURE_PROPOSED)[0];
        $this->assertSame('Se cambió el conector y la señal quedó en -21 dBm.', $evento->metadata['reason']);
        $this->assertSame($this->supervisor->id, (int) $evento->actor_user_id);
    }

    #[Test]
    public function la_propuesta_exige_accion_resultado_y_observacion(): void
    {
        // Sin acción ni resultado: reglas 2 y 3 del §15.
        $incompleto = $this->ticket(TicketWorkflow::SERVICIO_RESTABLECIDO);

        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$incompleto->id}/propose-closure", ['reason' => self::MOTIVO])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ticket_closure_requirements_missing');

        // Sin observación técnica.
        $completo = $this->ticketCerrable();

        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$completo->id}/propose-closure", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    #[Test]
    public function no_se_propone_cerrar_un_ticket_cuyo_servicio_no_volvio(): void
    {
        $ticket = $this->ticketCerrable(TicketWorkflow::EN_INTERVENCION);

        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$ticket->id}/propose-closure", ['reason' => self::MOTIVO])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ticket_cannot_propose_closure');
    }

    // ── Cierre normal ────────────────────────────────────────────────────

    #[Test]
    public function el_cierre_normal_exige_causa_confirmada_accion_y_resultado(): void
    {
        // Un caso por regla incumplida: si mañana alguien quita una
        // comprobación, falla exactamente la suya.
        $reglas = [
            'confirmed_cause' => ['solution' => 'AC02', 'result' => 'R01'],
            'solution'        => ['confirmed_cause' => 'RF', 'result' => 'R01'],
            'result'          => ['confirmed_cause' => 'RF', 'solution' => 'AC02'],
        ];

        foreach ($reglas as $faltante => $presentes) {
            $ticket = $this->ticket(TicketWorkflow::SERVICIO_RESTABLECIDO, $presentes);

            $this->actingAs($this->supervisor)
                ->postJson("/api/support/{$ticket->id}/close", [])
                ->assertStatus(422)
                ->assertJsonPath('error', 'ticket_closure_requirements_missing')
                ->assertJsonPath('missing.0', $faltante);

            $this->assertNull($ticket->fresh()->closed_at, "Falta {$faltante}: no se cierra.");
        }
    }

    #[Test]
    public function el_cierre_normal_sella_closed_at_sin_borrar_resolved_at(): void
    {
        $ticket = $this->ticketCerrable();

        // Pasa por restablecido para que `resolved_at` quede sellado.
        $this->mover($ticket, TicketWorkflow::EN_OBSERVACION)->assertOk();
        $resuelto = $ticket->fresh()->resolved_at;

        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$ticket->id}/close", ['reason' => 'Cliente confirma el servicio.'])
            ->assertOk();

        $ticket->refresh();

        $this->assertSame(TicketWorkflow::CERRADO, $ticket->status);
        $this->assertNotNull($ticket->closed_at);
        $this->assertEquals($resuelto, $ticket->resolved_at, 'Cerrar no puede tocar la fecha de restablecimiento.');

        $evento = $this->eventos($ticket, SupportTicketHistory::CLOSED)[0];
        $this->assertSame('RF', $evento->metadata['confirmed_cause']);
        $this->assertSame($this->supervisor->id, (int) $evento->actor_user_id);
    }

    #[Test]
    public function no_se_cierra_desde_un_estado_en_el_que_el_servicio_no_volvio(): void
    {
        $ticket = $this->ticketCerrable(TicketWorkflow::EN_INTERVENCION);

        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$ticket->id}/close", [])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ticket_cannot_close_from_status');
    }

    #[Test]
    public function un_ticket_ya_cerrado_no_se_vuelve_a_cerrar(): void
    {
        $ticket = $this->ticketCerrable(TicketWorkflow::CERRADO);

        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$ticket->id}/close", [])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ticket_already_closed');
    }

    #[Test]
    public function el_cierre_no_toca_el_expediente(): void
    {
        $ticket = $this->ticketCerrable();
        $eventosAntes = count($this->eventos($ticket));

        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$ticket->id}/close", [])
            ->assertOk();

        $ticket->refresh();

        // §15.10: «el cierre no debe borrar la causa sospechada, las
        // intervenciones ni los estados anteriores».
        $this->assertSame('RF', $ticket->confirmed_cause);
        $this->assertSame('AC02', $ticket->solution);
        $this->assertSame('R01', $ticket->result);
        $this->assertGreaterThan($eventosAntes, count($this->eventos($ticket)));
    }

    // ── Cierre excepcional ───────────────────────────────────────────────

    #[Test]
    public function el_cierre_excepcional_exige_motivo_y_registra_lo_que_falto(): void
    {
        // Sin causa confirmada: la regla 1 del §15, que es justo la que admite
        // «excepción autorizada y justificada».
        $ticket = $this->ticket(TicketWorkflow::SERVICIO_RESTABLECIDO, [
            'solution' => 'AC02', 'result' => 'R01',
        ]);

        // Sin motivo, no.
        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$ticket->id}/close-exception", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        // Con motivo, sí.
        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$ticket->id}/close-exception", [
                'reason' => 'El cliente se mudó y no se pudo confirmar la causa en sitio.',
            ])
            ->assertOk()
            ->assertJsonPath('exception', true);

        $ticket->refresh();
        $this->assertSame(TicketWorkflow::CERRADO, $ticket->status);

        $evento = $this->eventos($ticket, SupportTicketHistory::CLOSED_EXCEPTION)[0];

        // No es un cierre ordinario disfrazado: consta QUÉ requisito faltó.
        $this->assertSame(
            'El cliente se mudó y no se pudo confirmar la causa en sitio.',
            $evento->metadata['reason'],
        );
        $this->assertNotEmpty($evento->metadata['requisitos_incumplidos']);
        $this->assertStringContainsString('Causa confirmada', $evento->metadata['requisitos_incumplidos'][0]);

        // Y NO deja un evento de cierre ordinario.
        $this->assertCount(0, $this->eventos($ticket, SupportTicketHistory::CLOSED));
    }

    #[Test]
    public function no_se_usa_el_cierre_excepcional_cuando_no_falta_nada(): void
    {
        $ticket = $this->ticketCerrable();

        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$ticket->id}/close-exception", ['reason' => self::MOTIVO])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ticket_no_exception_needed');

        $this->assertNull($ticket->fresh()->closed_at);
    }

    #[Test]
    public function cerrar_con_solucion_temporal_exige_la_excepcion(): void
    {
        // §15.9: «solución temporal … debe generar seguimiento o autorización».
        $ticket = $this->ticketCerrable(TicketWorkflow::SOLUCION_TEMPORAL);

        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$ticket->id}/close", [])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ticket_closure_requirements_missing');

        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$ticket->id}/close-exception", [
                'reason' => 'Se autoriza el cierre con solución temporal hasta recibir el material.',
            ])
            ->assertOk();

        $this->assertSame(TicketWorkflow::CERRADO, $ticket->fresh()->status);
    }

    // ── Reapertura ───────────────────────────────────────────────────────

    #[Test]
    public function la_reapertura_exige_motivo_y_conserva_closed_at(): void
    {
        $ticket = $this->ticketCerrable();

        $this->actingAs($this->supervisor)->postJson("/api/support/{$ticket->id}/close", [])->assertOk();

        $cerradoEl = $ticket->fresh()->closed_at;
        $this->assertNotNull($cerradoEl);

        // Sin motivo, no.
        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$ticket->id}/reopen", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$ticket->id}/reopen", ['reason' => self::MOTIVO])
            ->assertOk();

        $ticket->refresh();

        // §7 lista «Reabierto» como estado auxiliar: es donde queda.
        $this->assertSame(TicketWorkflow::REABIERTO, $ticket->status);

        // §19.5: «los estados y timestamps se conservan sin sobrescritura».
        $this->assertEquals($cerradoEl, $ticket->closed_at, 'La fecha del cierre anterior es un hecho.');

        $evento = $this->eventos($ticket, SupportTicketHistory::REOPENED)[0];
        $this->assertSame(self::MOTIVO, $evento->metadata['reason']);
        $this->assertSame('cerrado', $evento->metadata['from']);
        $this->assertNotNull($evento->metadata['closed_at']);
    }

    #[Test]
    public function solo_se_reabre_lo_que_esta_cerrado(): void
    {
        $ticket = $this->ticket(TicketWorkflow::EN_INTERVENCION);

        $this->actingAs($this->supervisor)
            ->postJson("/api/support/{$ticket->id}/reopen", ['reason' => self::MOTIVO])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ticket_not_closed');
    }

    #[Test]
    public function un_ticket_reabierto_vuelve_al_flujo(): void
    {
        $ticket = $this->ticketCerrable(TicketWorkflow::REABIERTO);

        // Desde «Reabierto» se reanuda el trabajo.
        $this->mover($ticket, TicketWorkflow::EN_INTERVENCION)->assertOk();
        $this->assertSame(TicketWorkflow::EN_INTERVENCION, $ticket->fresh()->status);
    }

    // ── Un ticket archivado está fuera del workflow ──────────────────────

    #[Test]
    public function un_ticket_archivado_no_se_mueve_ni_se_cierra_ni_se_reabre(): void
    {
        $ticket = $this->ticketCerrable();
        $ticket->delete(); // archivado (PR C)

        $rutas = [
            ['patch', "/api/support/{$ticket->id}/status", ['status' => TicketWorkflow::EN_OBSERVACION]],
            ['post',  "/api/support/{$ticket->id}/propose-closure", ['reason' => self::MOTIVO]],
            ['post',  "/api/support/{$ticket->id}/close", []],
            ['post',  "/api/support/{$ticket->id}/close-exception", ['reason' => self::MOTIVO]],
            ['post',  "/api/support/{$ticket->id}/reopen", ['reason' => self::MOTIVO]],
        ];

        foreach ($rutas as [$metodo, $ruta, $cuerpo]) {
            // 404 y no 403: un archivado está fuera de la operación, y para
            // tocarlo hay que restaurarlo primero. No se revela que existe.
            $this->actingAs($this->supervisor)
                ->json(strtoupper($metodo), $ruta, $cuerpo)
                ->assertNotFound();
        }

        $this->assertNotNull(SupportTicket::withTrashed()->find($ticket->id)->deleted_at);
    }

    // ── Compatibilidad ───────────────────────────────────────────────────

    #[Test]
    public function un_ticket_legacy_sigue_cargando_y_entra_al_flujo_nuevo(): void
    {
        // Fila como las 27 que ya hay en producción.
        $id = DB::table('support_ticket')->insertGetId([
            'tenant_id'   => $this->tenant->id,
            'user_id'     => $this->customer->id,
            'subject'     => 'Ticket anterior al workflow',
            'status_id'   => DB::table('ticket_status')->where('code', 'open')->value('id'),
            'priority_id' => DB::table('ticket_priority')->where('code', 'medium')->value('id'),
            'category_id' => DB::table('ticket_category')->where('code', 'technical')->value('id'),
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        $detalle = $this->actingAs($this->supervisor)
            ->getJson("/api/support/{$id}")->assertOk()->json();

        $this->assertSame('open', $detalle['status'], 'El ticket viejo no cambia de estado solo.');

        // Y entra al flujo nuevo sin que nadie lo toque a mano.
        $this->actingAs($this->supervisor)
            ->patchJson("/api/support/{$id}/status", ['status' => TicketWorkflow::EN_CLASIFICACION])
            ->assertOk();

        $this->assertSame(
            'en_clasificacion',
            DB::table('ticket_status')
                ->where('id', DB::table('support_ticket')->where('id', $id)->value('status_id'))
                ->value('code'),
        );
    }

    #[Test]
    public function las_estadisticas_cuentan_los_estados_nuevos_por_equivalencia(): void
    {
        // Sin la equivalencia, el tablero habría quedado en cero el día del
        // despliegue: `radicado` no es `open`, pero significa lo mismo.
        $this->ticket(TicketWorkflow::RADICADO);
        $this->ticket(TicketWorkflow::EN_CLASIFICACION);
        $this->ticket(TicketWorkflow::EN_INTERVENCION);
        $this->ticket(TicketWorkflow::ASIGNADO);

        $usuario = $this->usuarioCon([
            Permissions::TICKET_VIEW, Permissions::TICKET_EXPORT,
        ], 'staff');

        $stats = $this->actingAs($usuario)->getJson('/api/support/statistics')->assertOk()->json();

        $this->assertSame(4, $stats['total_tickets']);
        $this->assertSame(2, $stats['open_tickets'], 'radicado + en_clasificacion equivalen a `open`.');
        $this->assertSame(2, $stats['in_progress_tickets'], 'asignado + en_intervencion equivalen a `in_progress`.');
    }

    // ── El endpoint que alimenta la interfaz ─────────────────────────────

    #[Test]
    public function el_endpoint_de_transiciones_dice_lo_que_el_usuario_puede_hacer(): void
    {
        $ticket = $this->ticketCerrable();

        $datos = $this->actingAs($this->supervisor)
            ->getJson("/api/support/{$ticket->id}/transitions")->assertOk()->json();

        $this->assertSame('servicio_restablecido', $datos['status']);
        $this->assertTrue($datos['actions']['close']);
        $this->assertTrue($datos['actions']['propose_closure']);
        $this->assertFalse($datos['actions']['reopen'], 'No está cerrado: no se puede reabrir.');
        $this->assertTrue($datos['closure_requirements']['complete']);

        // Cerrar NO se ofrece como transición suelta: tiene endpoint propio.
        $this->assertNotContains('cerrado', array_column($datos['transitions'], 'code'));

        // Y a quien le falta el permiso, no se le ofrece.
        $soloLectura = $this->usuarioCon([Permissions::TICKET_VIEW]);

        $datos = $this->actingAs($soloLectura)
            ->getJson("/api/support/{$ticket->id}/transitions")->assertOk()->json();

        $this->assertSame([], $datos['transitions']);
        $this->assertFalse($datos['actions']['close']);
    }

    #[Test]
    public function el_endpoint_de_transiciones_anuncia_los_requisitos_que_faltan(): void
    {
        // Para que la interfaz los muestre ANTES de abrir el modal, y nadie se
        // entere de que falta la causa confirmada después de escribir el motivo.
        $ticket = $this->ticket(TicketWorkflow::SERVICIO_RESTABLECIDO, ['solution' => 'AC02']);

        $datos = $this->actingAs($this->supervisor)
            ->getJson("/api/support/{$ticket->id}/transitions")->assertOk()->json();

        $this->assertFalse($datos['closure_requirements']['complete']);
        $this->assertCount(2, $datos['closure_requirements']['missing']);
    }
}
