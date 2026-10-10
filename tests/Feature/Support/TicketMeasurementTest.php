<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SupportTicketHistory;
use App\Models\Tenant;
use App\Models\TicketIntervention;
use App\Models\TicketMeasurement;
use App\Models\User;
use App\Support\TicketMeasurements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR F2 · Pruebas técnicas estructuradas (§ 12, § 13 y § 15.5).
 *
 * LO QUE ESTE ARCHIVO PROTEGE
 *
 * El § 12 pide que cada medición guarde sus seis datos y que «los resultados no
 * queden únicamente en observaciones». El § 13 pide poder comparar antes y
 * después. Pero la pieza que de verdad cambia el comportamiento del sistema es
 * la regla 5 del § 15:
 *
 *     «Exigir prueba final o justificación de por qué no fue posible.»
 *
 * Es la única regla del § 15 con una **O**: se cumple de dos maneras. La mitad
 * de estos tests existe para que ninguna de las dos se pueda saltar, y para que
 * la otra no se vuelva obligatoria por descuido — un ticket con medición final
 * no debe tener que justificar nada.
 */
class TicketMeasurementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $customer;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        // `CheckPermission` deja pasar SIEMPRE a `role_id == 1`. Con
        // `RefreshDatabase` el primer rol se queda ese id, así que se quema uno:
        // sin esto los casos negativos serían falsos positivos.
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
            Permissions::TICKET_EDIT,
            Permissions::TICKET_DIAGNOSE,
            Permissions::TICKET_CONFIRM_CAUSE,
            Permissions::TICKET_TRANSITION,
            Permissions::TICKET_CLOSE,
            Permissions::TICKET_VIEW_HISTORY,
        ];
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(array $permisos, string $code = 'admin', ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;

        $rol = Role::create([
            'name'        => 'Rol ' . $code . ' ' . count($permisos) . ' ' . uniqid(),
            'code'        => $code,
            'permissions' => $permisos,
            'tenant_id'   => $tenant->id,
        ]);

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
            'subject'   => 'Intermitencia desde anoche',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    /** @return array<string, mixed> */
    private function medicion(array $extra = []): array
    {
        // `$extra` primero: en PHP `$a + $b` conserva las claves de `$a`.
        return $extra + [
            'test_type'   => 'RSSI',
            'value'       => '-76',
            'unit'        => 'dBm',
            'measured_at' => '2026-09-25T08:00:00',
            'source'      => 'CPE',
            'phase'       => TicketMeasurements::FASE_INICIAL,
        ];
    }

    private function registrar(SupportTicket $ticket, array $extra = [], ?User $como = null): int
    {
        return $this->actingAs($como ?? $this->staff)
            ->postJson("/api/support/{$ticket->id}/measurements", $this->medicion($extra))
            ->assertCreated()
            ->json('measurement.id');
    }

    /** Lleva el ticket a un estado desde el que se puede proponer y cerrar. */
    private function prepararParaCerrar(SupportTicket $ticket): void
    {
        $this->actingAs($this->staff)->putJson("/api/support/{$ticket->id}", [
            'confirmed_cause' => 'CL', 'solution' => 'AC05', 'result' => 'R02',
        ])->assertOk();

        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'en_diagnostico_remoto'])
            ->assertOk();

        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'servicio_restablecido'])
            ->assertOk();
    }

    // ── Las seis columnas del § 12 ───────────────────────────────────────

    #[Test]
    public function una_medicion_guarda_los_seis_datos_del_documento(): void
    {
        $ticket = $this->ticket();
        $id     = $this->registrar($ticket);

        $m = TicketMeasurement::find($id);

        $this->assertSame('RSSI', $m->test_type);
        $this->assertSame('-76', $m->value);
        $this->assertSame('dBm', $m->unit);
        $this->assertNotNull($m->measured_at);
        $this->assertSame('CPE', $m->source);
        $this->assertSame('inicial', $m->phase);

        // Y quién la tomó, congelado.
        $this->assertSame($this->staff->id, (int) $m->recorded_by);
        $this->assertSame('Juan Pérez', $m->recorded_by_name);
    }

    #[Test]
    public function el_valor_admite_texto_no_solo_numeros(): void
    {
        // El § 13 mezcla los dos tipos en la misma frase: «PPPoE conectado;
        // RSSI –76 dBm; CCQ 54 %». Forzar un decimal perdería la mitad.
        $ticket = $this->ticket();

        $id = $this->registrar($ticket, [
            'test_type' => 'PPPoE', 'value' => 'conectado', 'unit' => null,
        ]);

        $this->assertSame('conectado', TicketMeasurement::find($id)->value);
        $this->assertNull(TicketMeasurement::find($id)->unit);
    }

    #[Test]
    public function las_tres_fases_del_documento_se_aceptan_y_nada_mas(): void
    {
        $ticket = $this->ticket();

        foreach (TicketMeasurements::FASES as $fase) {
            $this->actingAs($this->staff)
                ->postJson("/api/support/{$ticket->id}/measurements", $this->medicion(['phase' => $fase]))
                ->assertCreated()
                ->assertJsonPath('measurement.phase', $fase);
        }

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/measurements", $this->medicion(['phase' => 'intermedia']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phase');
    }

    #[Test]
    public function el_tipo_de_prueba_es_texto_libre_sin_codigos_inventados(): void
    {
        // El § 12 enumera las mediciones en prosa sin asignarles código, y el
        // cliente cerró en D-06 que esa clase de listas se queda como
        // referencia. Cualquier texto razonable debe aceptarse.
        $ticket = $this->ticket();

        foreach (['RSSI', 'Potencia óptica RX', 'Algo que el documento no nombra'] as $tipo) {
            $this->actingAs($this->staff)
                ->postJson("/api/support/{$ticket->id}/measurements", $this->medicion(['test_type' => $tipo]))
                ->assertCreated();
        }

        $this->assertSame(3, TicketMeasurement::where('support_ticket_id', $ticket->id)->count());
    }

    #[Test]
    public function faltar_cualquiera_de_los_seis_se_rechaza(): void
    {
        $ticket = $this->ticket();

        foreach (['test_type', 'value', 'measured_at', 'source', 'phase'] as $campo) {
            $cuerpo = $this->medicion();
            unset($cuerpo[$campo]);

            $this->actingAs($this->staff)
                ->postJson("/api/support/{$ticket->id}/measurements", $cuerpo)
                ->assertStatus(422)
                ->assertJsonValidationErrors($campo);
        }

        // `unit` sí es opcional: «conectado» no tiene unidad.
        $cuerpo = $this->medicion();
        unset($cuerpo['unit']);
        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/measurements", $cuerpo)
            ->assertCreated();
    }

    // ── Enlace con la intervención ───────────────────────────────────────

    #[Test]
    public function una_medicion_puede_colgar_de_una_intervencion_del_ticket(): void
    {
        $ticket = $this->ticket();

        $intervencionId = $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/interventions", [
                'kind' => TicketIntervention::KIND_PRESENCIAL,
                'technician_id' => $this->staff->id,
                'started_at' => '2026-09-25T08:00:00',
            ])->assertCreated()->json('intervention.id');

        $id = $this->registrar($ticket, ['intervention_id' => $intervencionId]);

        $this->assertSame($intervencionId, (int) TicketMeasurement::find($id)->intervention_id);
    }

    #[Test]
    public function una_medicion_sin_intervencion_es_valida(): void
    {
        // El diagnóstico remoto inicial se toma antes de que exista ninguna
        // visita: `intervention_id` NULL es el caso normal, no un hueco.
        $ticket = $this->ticket();
        $id     = $this->registrar($ticket);

        $this->assertNull(TicketMeasurement::find($id)->intervention_id);
    }

    #[Test]
    public function no_se_puede_colgar_de_la_intervencion_de_otro_ticket(): void
    {
        $mio  = $this->ticket();
        $otro = $this->ticket();

        $ajena = $this->actingAs($this->staff)
            ->postJson("/api/support/{$otro->id}/interventions", [
                'kind' => TicketIntervention::KIND_REMOTO,
                'technician_id' => $this->staff->id,
                'started_at' => '2026-09-25T08:00:00',
            ])->assertCreated()->json('intervention.id');

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$mio->id}/measurements", $this->medicion(['intervention_id' => $ajena]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'intervention_not_in_ticket');

        $this->assertSame(0, TicketMeasurement::where('support_ticket_id', $mio->id)->count());
    }

    #[Test]
    public function la_base_bloquea_una_medicion_cruzada_entre_tickets(): void
    {
        // A diferencia de la FK compuesta del PR F1 sobre adjuntos —que sólo
        // existe en PostgreSQL porque allí hubo que añadirla con ALTER TABLE—
        // ésta se declara al crear la tabla y funciona en los dos motores.
        $mio  = $this->ticket();
        $otro = $this->ticket();

        $ajena = $this->actingAs($this->staff)
            ->postJson("/api/support/{$otro->id}/interventions", [
                'kind' => TicketIntervention::KIND_REMOTO,
                'technician_id' => $this->staff->id,
                'started_at' => '2026-09-25T08:00:00',
            ])->assertCreated()->json('intervention.id');

        $id = $this->registrar($mio);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('ticket_measurement')->where('id', $id)->update(['intervention_id' => $ajena]);
    }

    // ── Comparación inicial / final (§ 13) ───────────────────────────────

    #[Test]
    public function la_comparacion_empareja_inicial_y_final_por_tipo(): void
    {
        $ticket = $this->ticket();

        $this->registrar($ticket, ['test_type' => 'RSSI', 'value' => '-76', 'unit' => 'dBm', 'phase' => 'inicial']);
        $this->registrar($ticket, ['test_type' => 'RSSI', 'value' => '-64', 'unit' => 'dBm', 'phase' => 'final',
            'measured_at' => '2026-09-25T10:00:00']);
        $this->registrar($ticket, ['test_type' => 'CCQ', 'value' => '54', 'unit' => '%', 'phase' => 'inicial']);

        $comparacion = collect(
            $this->actingAs($this->staff)->getJson("/api/support/{$ticket->id}/measurements")
                ->assertOk()->json('comparison')
        )->keyBy('test_type');

        $this->assertSame('-76 dBm', $comparacion['RSSI']['initial']);
        $this->assertSame('-64 dBm', $comparacion['RSSI']['final']);
        $this->assertTrue($comparacion['RSSI']['complete']);

        // CCQ sólo tiene inicial: se muestra incompleto en vez de esconderse.
        $this->assertSame('54 %', $comparacion['CCQ']['initial']);
        $this->assertNull($comparacion['CCQ']['final']);
        $this->assertFalse($comparacion['CCQ']['complete']);
    }

    #[Test]
    public function la_comparacion_toma_la_ultima_medicion_de_cada_fase(): void
    {
        $ticket = $this->ticket();

        $this->registrar($ticket, ['test_type' => 'latencia', 'value' => '104', 'unit' => 'ms',
            'phase' => 'inicial', 'measured_at' => '2026-09-25T08:00:00']);
        $this->registrar($ticket, ['test_type' => 'latencia', 'value' => '98', 'unit' => 'ms',
            'phase' => 'inicial', 'measured_at' => '2026-09-25T09:00:00']);

        $comparacion = collect(
            $this->actingAs($this->staff)->getJson("/api/support/{$ticket->id}/measurements")
                ->assertOk()->json('comparison')
        )->keyBy('test_type');

        // Si se volvió a medir, la buena es la de después.
        $this->assertSame('98 ms', $comparacion['latencia']['initial']);
    }

    #[Test]
    public function el_seguimiento_aparece_sin_romper_el_par_antes_despues(): void
    {
        $ticket = $this->ticket();

        $this->registrar($ticket, ['test_type' => 'CCQ', 'value' => '54', 'unit' => '%', 'phase' => 'inicial']);
        $this->registrar($ticket, ['test_type' => 'CCQ', 'value' => '71', 'unit' => '%', 'phase' => 'seguimiento',
            'measured_at' => '2026-09-25T09:00:00']);
        $this->registrar($ticket, ['test_type' => 'CCQ', 'value' => '93', 'unit' => '%', 'phase' => 'final',
            'measured_at' => '2026-09-25T10:00:00']);

        $fila = collect(
            $this->actingAs($this->staff)->getJson("/api/support/{$ticket->id}/measurements")
                ->assertOk()->json('comparison')
        )->firstWhere('test_type', 'CCQ');

        $this->assertSame('54 %', $fila['initial']);
        $this->assertSame('71 %', $fila['follow_up']);
        $this->assertSame('93 %', $fila['final']);
        $this->assertTrue($fila['complete']);
    }

    // ── Regla 5 del § 15: prueba final O justificación ───────────────────

    #[Test]
    public function no_se_cierra_sin_prueba_final_ni_justificacion(): void
    {
        $ticket = $this->ticket();
        $this->prepararParaCerrar($ticket);

        $respuesta = $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/close", [])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ticket_closure_requirements_missing');

        $this->assertContains('prueba_final', $respuesta->json('missing'));
    }

    #[Test]
    public function con_prueba_final_el_cierre_pasa_sin_pedir_justificacion(): void
    {
        $ticket = $this->ticket();
        $this->prepararParaCerrar($ticket);

        $this->registrar($ticket, ['phase' => TicketMeasurements::FASE_FINAL, 'value' => '-64']);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/close", [])
            ->assertOk();

        $ticket->refresh();
        $this->assertSame('cerrado', $ticket->status);
        $this->assertNull($ticket->final_test_waiver_reason, 'Con medición final no hay nada que justificar.');
    }

    #[Test]
    public function sin_prueba_final_la_justificacion_completa_permite_cerrar(): void
    {
        $ticket = $this->ticket();
        $this->prepararParaCerrar($ticket);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/close", [
                'final_test_waiver_reason' => 'cliente_no_permitio',
                'final_test_waiver_note'   => 'El abonado se retiró del domicilio antes de terminar.',
            ])
            ->assertOk();

        $ticket->refresh();
        $this->assertSame('cerrado', $ticket->status);
        $this->assertSame('cliente_no_permitio', $ticket->final_test_waiver_reason);
        $this->assertSame('El abonado se retiró del domicilio antes de terminar.', $ticket->final_test_waiver_note);
    }

    #[Test]
    public function la_razon_sola_no_basta(): void
    {
        // El § 13 pide «seleccionar una razón Y escribir la justificación».
        $ticket = $this->ticket();
        $this->prepararParaCerrar($ticket);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/close", [
                'final_test_waiver_reason' => 'no_fue_posible_contactar',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('final_test_waiver_note');

        $this->assertSame('servicio_restablecido', $ticket->fresh()->status);
    }

    #[Test]
    public function una_razon_fuera_de_la_lista_se_rechaza(): void
    {
        $ticket = $this->ticket();
        $this->prepararParaCerrar($ticket);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/close", [
                'final_test_waiver_reason' => 'me_dio_pereza',
                'final_test_waiver_note'   => 'Una justificación suficientemente larga.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('final_test_waiver_reason');
    }

    #[Test]
    public function la_justificacion_corta_se_rechaza(): void
    {
        $ticket = $this->ticket();
        $this->prepararParaCerrar($ticket);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/close", [
                'final_test_waiver_reason' => 'otro',
                'final_test_waiver_note'   => 'no se',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('final_test_waiver_note');
    }

    #[Test]
    public function la_exencion_deja_su_propio_evento_en_el_historial(): void
    {
        $ticket = $this->ticket();
        $this->prepararParaCerrar($ticket);

        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/close", [
            'final_test_waiver_reason' => 'equipo_sin_energia',
            'final_test_waiver_note'   => 'No había energía comercial en el sector al cerrar.',
        ])->assertOk();

        $evento = SupportTicketHistory::where('support_ticket_id', $ticket->id)
            ->where('event_type', SupportTicketHistory::FINAL_TEST_WAIVED)
            ->firstOrFail();

        $this->assertSame($this->staff->id, (int) $evento->actor_user_id);
        $this->assertSame('final_test_waiver_reason', $evento->field);
        $this->assertSame('equipo_sin_energia', $evento->new_value);
        $this->assertSame('Equipo apagado o sin energía', $evento->metadata['reason_label']);
        $this->assertStringContainsString('energía comercial', $evento->metadata['note']);
    }

    #[Test]
    public function proponer_el_cierre_tambien_exige_la_regla_5(): void
    {
        // El § 18 le da al Técnico de campo «pruebas finales y propuesta de
        // cierre» en la misma frase: es el mismo momento del trabajo.
        $ticket = $this->ticket();
        $this->prepararParaCerrar($ticket);

        $respuesta = $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/propose-closure", [
                'reason' => 'Servicio estable tras la atención.',
            ])
            ->assertStatus(422);

        $this->assertContains('prueba_final', $respuesta->json('missing'));

        $this->registrar($ticket, ['phase' => TicketMeasurements::FASE_FINAL]);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/propose-closure", [
                'reason' => 'Servicio estable tras la atención.',
            ])
            ->assertOk();
    }

    #[Test]
    public function el_cierre_excepcional_sigue_pudiendo_saltarse_la_regla_5(): void
    {
        // §15.1 contempla la excepción «autorizada y justificada», y el cierre
        // excepcional registra QUÉ requisito faltó. La regla 5 entra en esa
        // lista como cualquier otra.
        $supervisor = $this->usuarioCon(array_merge($this->permisosDeCampo(), [
            Permissions::TICKET_CLOSE_OVERRIDE,
        ]));

        $ticket = $this->ticket();
        $this->prepararParaCerrar($ticket);

        $this->actingAs($supervisor)
            ->postJson("/api/support/{$ticket->id}/close-exception", [
                'reason' => 'Autorizado por el supervisor: el abonado se mudó del inmueble.',
            ])
            ->assertOk();

        $evento = SupportTicketHistory::where('support_ticket_id', $ticket->id)
            ->where('event_type', SupportTicketHistory::CLOSED_EXCEPTION)
            ->firstOrFail();

        $this->assertContains(
            'Medición final o justificación de por qué no fue posible (regla 5 del § 15)',
            $evento->metadata['requisitos_incumplidos'],
        );
    }

    // ── Ticket cerrado y archivado ───────────────────────────────────────

    #[Test]
    public function un_ticket_cerrado_no_admite_mediciones_nuevas(): void
    {
        $ticket = $this->ticket();
        $this->prepararParaCerrar($ticket);
        $this->registrar($ticket, ['phase' => TicketMeasurements::FASE_FINAL]);
        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/close", [])->assertOk();

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/measurements", $this->medicion())
            ->assertStatus(422)
            ->assertJsonPath('error', 'ticket_already_closed');
    }

    #[Test]
    public function un_ticket_archivado_no_admite_mediciones(): void
    {
        $ticket = $this->ticket();
        $ticket->delete();

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/measurements", $this->medicion())
            ->assertNotFound();

        $this->assertSame(0, TicketMeasurement::count());
    }

    #[Test]
    public function las_mediciones_de_un_ticket_archivado_siguen_consultandose(): void
    {
        $ticket = $this->ticket();
        $this->registrar($ticket);
        $ticket->delete();

        $archivista = $this->usuarioCon([
            Permissions::TICKET_VIEW, Permissions::TICKET_ARCHIVE, Permissions::TICKET_RESTORE,
        ]);

        $this->actingAs($archivista)
            ->getJson("/api/support/{$ticket->id}/measurements")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ── Corrección, sin borrado ──────────────────────────────────────────

    #[Test]
    public function una_medicion_se_puede_corregir_mientras_el_ticket_siga_abierto(): void
    {
        $ticket = $this->ticket();
        $id     = $this->registrar($ticket);

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}/measurements/{$id}", ['value' => '-67'])
            ->assertOk();

        $this->assertSame('-67', TicketMeasurement::find($id)->value);

        $evento = SupportTicketHistory::where('support_ticket_id', $ticket->id)
            ->where('event_type', SupportTicketHistory::MEASUREMENT_UPDATED)
            ->firstOrFail();

        $this->assertSame('-76 dBm', $evento->old_value);
        $this->assertSame('-67 dBm', $evento->new_value);
    }

    #[Test]
    public function ninguna_ruta_permite_borrar_una_medicion(): void
    {
        $ticket = $this->ticket();
        $id     = $this->registrar($ticket);

        $this->actingAs($this->staff)
            ->deleteJson("/api/support/{$ticket->id}/measurements/{$id}")
            ->assertStatus(405);

        $this->assertSame(1, TicketMeasurement::count());
    }

    #[Test]
    public function el_modelo_prohibe_el_borrado_fisico(): void
    {
        $ticket = $this->ticket();
        $id     = $this->registrar($ticket);

        $this->assertFalse(
            \Schema::hasColumn('ticket_measurement', 'deleted_at'),
            'Sin borrado blando: poder esconder una medición vaciaría la regla 5 del § 15.',
        );

        $this->expectException(\RuntimeException::class);
        TicketMeasurement::find($id)->delete();
    }

    #[Test]
    public function registrar_una_medicion_deja_evento_con_el_valor_legible(): void
    {
        $ticket = $this->ticket();
        $this->registrar($ticket);

        $evento = SupportTicketHistory::where('support_ticket_id', $ticket->id)
            ->where('event_type', SupportTicketHistory::MEASUREMENT_RECORDED)
            ->firstOrFail();

        $this->assertSame('RSSI', $evento->field);
        $this->assertSame('-76 dBm', $evento->new_value);
        $this->assertSame('inicial', $evento->metadata['phase']);
    }

    // ── Permisos y aislamiento ───────────────────────────────────────────

    #[Test]
    public function sin_ticket_intervene_no_se_registra_una_medicion(): void
    {
        $sinPermiso = $this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_EDIT]);
        $ticket     = $this->ticket();

        $this->actingAs($sinPermiso)
            ->postJson("/api/support/{$ticket->id}/measurements", $this->medicion())
            ->assertForbidden();

        $this->assertSame(0, TicketMeasurement::count());
    }

    #[Test]
    public function con_ticket_view_se_pueden_leer_las_mediciones(): void
    {
        $ticket = $this->ticket();
        $this->registrar($ticket);

        $lector = $this->usuarioCon([Permissions::TICKET_VIEW]);

        $this->actingAs($lector)
            ->getJson("/api/support/{$ticket->id}/measurements")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function sin_sesion_no_se_llega_a_las_mediciones(): void
    {
        $ticket = $this->ticket();

        $this->getJson("/api/support/{$ticket->id}/measurements")->assertUnauthorized();
        $this->postJson("/api/support/{$ticket->id}/measurements", $this->medicion())->assertUnauthorized();
    }

    #[Test]
    public function no_se_mide_el_ticket_de_otro_isp(): void
    {
        $otro  = Tenant::factory()->create();
        $ajeno = $this->ticket($otro, $this->clienteDe($otro));

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ajeno->id}/measurements", $this->medicion())
            ->assertNotFound();

        $this->actingAs($this->staff)
            ->getJson("/api/support/{$ajeno->id}/measurements")
            ->assertNotFound();
    }

    // ── Vocabulario expuesto ─────────────────────────────────────────────

    #[Test]
    public function el_endpoint_de_catalogos_publica_fases_razones_y_sugerencias(): void
    {
        $datos = $this->actingAs($this->staff)
            ->getJson('/api/catalogs/ticket')->assertOk()->json();

        $this->assertSame(
            ['inicial', 'seguimiento', 'final'],
            collect($datos['measurement_phases'])->pluck('code')->all(),
        );

        $this->assertSame(
            ['cliente_no_permitio', 'no_fue_posible_contactar', 'equipo_sin_energia', 'pendiente_tercero', 'otro'],
            collect($datos['final_test_waiver_reasons'])->pluck('code')->all(),
        );

        // Sugerencias del § 12, agrupadas por tecnología. NO son códigos.
        $this->assertArrayHasKey('Radio', $datos['measurement_suggestions']);
        $this->assertContains('RSSI', $datos['measurement_suggestions']['Radio']);
        $this->assertContains('LOS', $datos['measurement_suggestions']['FTTH']);
        $this->assertContains('latencia', $datos['measurement_suggestions']['Común a cualquier tecnología']);
    }

    #[Test]
    public function las_claves_anteriores_del_endpoint_no_cambian(): void
    {
        $datos = $this->actingAs($this->staff)
            ->getJson('/api/catalogs/ticket')->assertOk()->json();

        foreach (['statuses', 'priorities', 'categories', 'symptoms', 'causes', 'actions', 'results', 'versions'] as $clave) {
            $this->assertArrayHasKey($clave, $datos, "El PR F2 es aditivo: no puede quitar `{$clave}`.");
        }

        $this->assertSame(['code', 'label'], array_keys($datos['statuses'][0]));
    }

    // ── Interfaz ─────────────────────────────────────────────────────────
    //
    // El proyecto no tiene runner de JavaScript; la interfaz se verifica sobre
    // el fuente, como ya hace `VersionConsistencyTest`.

    #[Test]
    public function la_interfaz_no_ofrece_borrar_una_medicion(): void
    {
        $componente = file_get_contents(resource_path('js/components/TicketMeasurements.vue'));
        $servicio   = file_get_contents(resource_path('js/services/api/support.js'));

        $this->assertStringNotContainsString('apiClient.delete(`/support/${ticketId}/measurements', $servicio);
        $this->assertStringNotContainsString('Eliminar medición', $componente);
        $this->assertStringContainsString('Corregir', $componente);
    }

    #[Test]
    public function la_interfaz_pinta_la_comparacion_inicial_final(): void
    {
        $componente = file_get_contents(resource_path('js/components/TicketMeasurements.vue'));

        foreach (['Inicial', 'Seguimiento', 'Final', 'sin medición final'] as $texto) {
            $this->assertStringContainsString($texto, $componente);
        }
    }

    #[Test]
    public function la_interfaz_no_escribe_el_vocabulario_a_mano(): void
    {
        // Las fases y las razones salen del endpoint de catalogos, no de un mapa
        // duplicado en el componente. Es la leccion de la R2.
        $componente = file_get_contents(resource_path('js/components/TicketMeasurements.vue'));
        $detalle    = file_get_contents(resource_path('js/pages/SupportDetail.vue'));

        $this->assertStringContainsString('measurementPhases', $componente);
        $this->assertStringContainsString('finalTestWaiverReasons', $detalle);

        // Y las sugerencias del § 12 alimentan un datalist, no una lista cerrada.
        $this->assertStringContainsString('<datalist', $componente);
        $this->assertStringNotContainsString('RSSI, SNR, CCQ', $componente);
    }

    #[Test]
    public function el_modal_de_cierre_pide_razon_y_justificacion(): void
    {
        $detalle = file_get_contents(resource_path('js/pages/SupportDetail.vue'));

        $this->assertStringContainsString('pideJustificacionDePruebaFinal', $detalle);
        $this->assertStringContainsString('final_test_waiver_reason', $detalle);
        $this->assertStringContainsString('final_test_waiver_note', $detalle);
        $this->assertStringContainsString('Entre 10 y 500 caracteres', $detalle);
    }

    #[Test]
    public function el_componente_cubre_carga_vacio_y_error(): void
    {
        $componente = file_get_contents(resource_path('js/components/TicketMeasurements.vue'));

        $this->assertStringContainsString('Cargando mediciones', $componente);
        $this->assertStringContainsString('No hay mediciones registradas', $componente);
        $this->assertStringContainsString('Reintentar', $componente);
    }

    // ── No regresión ─────────────────────────────────────────────────────

    #[Test]
    public function un_ticket_antiguo_sin_mediciones_sigue_funcionando(): void
    {
        $id = DB::table('support_ticket')->insertGetId([
            'tenant_id'   => $this->tenant->id,
            'user_id'     => $this->customer->id,
            'subject'     => 'Ticket anterior al PR F2',
            'status_id'   => DB::table('ticket_status')->where('code', 'open')->value('id'),
            'priority_id' => DB::table('ticket_priority')->where('code', 'medium')->value('id'),
            'category_id' => DB::table('ticket_category')->where('code', 'technical')->value('id'),
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->staff)->getJson("/api/support/{$id}")->assertOk();

        $respuesta = $this->actingAs($this->staff)
            ->getJson("/api/support/{$id}/measurements")->assertOk();

        $respuesta->assertJsonCount(0, 'data');
        $this->assertSame([], $respuesta->json('comparison'));
        $this->assertFalse($respuesta->json('final_test.present'));
        $this->assertNull($respuesta->json('final_test.waiver'));
    }

    #[Test]
    public function el_contrato_de_socios_no_expone_mediciones(): void
    {
        $cliente = \App\Models\ApiClient::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Integrador', 'is_active' => true,
        ]);
        $token = $cliente->createToken('contrato', ['read:support']);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        $ticket = $this->ticket();
        $this->registrar($ticket);

        $this->app['auth']->forgetGuards();

        $fila = $this->getJson('/api/v1/partner/tickets', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->assertOk()->json('data.0');

        foreach (['measurements', 'interventions', 'diagnosis', 'final_test_waiver_reason'] as $clave) {
            $this->assertArrayNotHasKey($clave, $fila, "`{$clave}` no forma parte del contrato de socios.");
        }
    }
}
