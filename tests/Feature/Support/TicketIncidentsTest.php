<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\Sectorial;
use App\Models\SupportTicket;
use App\Models\SupportTicketHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TicketWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR #6 de CNO · Duplicados, incidentes masivos y reincidencias (§ 16).
 *
 * Lo que se fija:
 *  - § 19.7: se detectan los tickets abiertos del mismo servicio y se puede
 *    vincular un duplicado A SU ORIGINAL;
 *  - § 19.8: se crea un incidente padre (modalidad masiva) y se le asocian
 *    tickets individuales; cuando vuelve el servicio, vuelven los asociados;
 *  - § 16: reincidencia por servicio, síntoma, causa e infraestructura en
 *    ventanas de 7, 30 y 90 días, en el expediente y en el tablero.
 */
class TicketIncidentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        // Se quema el id 1: `CheckPermission` deja pasar siempre a role_id == 1.
        Role::create(['name' => 'Superadmin global', 'code' => 'superadmin', 'permissions' => ['*'], 'tenant_id' => null]);

        $this->operador = $this->usuarioCon([
            Permissions::TICKET_VIEW, Permissions::TICKET_CREATE,
            Permissions::TICKET_TRANSITION, Permissions::TICKET_REOPEN, Permissions::TICKET_EXPORT,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Alta de un incidente masivo ──────────────────────────────────────

    #[Test]
    public function un_ticket_nace_individual_si_no_se_dice_otra_cosa(): void
    {
        $id = $this->actingAs($this->operador)->postJson('/api/support', [
            'subject' => 'Sin internet', 'user_id' => $this->cliente()->id,
        ])->assertCreated()->json('ticket.id');

        $this->assertSame(SupportTicket::MODALITY_INDIVIDUAL, SupportTicket::find($id)->modality);
    }

    #[Test]
    public function un_incidente_masivo_exige_el_elemento_afectado_y_no_lleva_cliente(): void
    {
        $nodo = $this->elemento('Nodo Centro', 'nodo');

        $this->actingAs($this->operador)->postJson('/api/support', [
            'subject' => 'Caída del nodo', 'modality' => 'masivo',
        ])->assertUnprocessable()->assertJsonValidationErrors('sectorial_id');

        // Los clientes llegan como tickets ASOCIADOS, no como dueños del masivo.
        $this->actingAs($this->operador)->postJson('/api/support', [
            'subject' => 'Caída del nodo', 'modality' => 'masivo',
            'sectorial_id' => $nodo->id, 'user_id' => $this->cliente()->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $id = $this->actingAs($this->operador)->postJson('/api/support', [
            'subject' => 'Caída del nodo', 'modality' => 'masivo', 'sectorial_id' => $nodo->id,
        ])->assertCreated()->json('ticket.id');

        $masivo = SupportTicket::find($id);
        $this->assertTrue($masivo->esMasivo());
        $this->assertNull($masivo->user_id);
        $this->assertSame($nodo->id, (int) $masivo->sectorial_id);
    }

    #[Test]
    public function el_alta_no_acepta_un_cliente_ni_un_elemento_de_otro_isp(): void
    {
        $otro = Tenant::factory()->create();
        $ajeno = User::factory()->create(['tenant_id' => $otro->id]);
        $elementoAjeno = Sectorial::create(['tenant_id' => $otro->id, 'name' => 'AP ajeno', 'element_type' => 'sectorial']);

        $this->actingAs($this->operador)->postJson('/api/support', [
            'subject' => 'Sin internet', 'user_id' => $ajeno->id, 'sectorial_id' => $elementoAjeno->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'sectorial_id']);
    }

    // ── Prevención de duplicados (§ 16) ──────────────────────────────────

    #[Test]
    public function al_radicar_se_ven_los_abiertos_del_cliente_y_los_incidentes_de_su_infraestructura(): void
    {
        // Árbol: el AP cuelga del nodo. El cliente está en el AP; la caída es
        // del nodo, y aun así le afecta.
        $nodo = $this->elemento('Nodo Centro', 'nodo');
        $ap = $this->elemento('AP Norte', 'sectorial', $nodo);
        $otroNodo = $this->elemento('Nodo Sur', 'nodo');

        $cliente = $this->cliente($ap);
        $abierto = $this->ticket(TicketWorkflow::EN_DIAGNOSTICO_REMOTO, ['user_id' => $cliente->id]);
        $this->ticket(TicketWorkflow::CERRADO, ['user_id' => $cliente->id]);
        $this->ticket(TicketWorkflow::RADICADO, ['user_id' => $this->cliente()->id]);

        $delNodo = $this->masivo($nodo);
        $this->masivo($otroNodo);
        $this->masivo($nodo, TicketWorkflow::CERRADO);

        $r = $this->actingAs($this->operador)->getJson("/api/support/open-for?user_id={$cliente->id}")->assertOk();

        $this->assertSame([$abierto->id], array_column($r->json('open_tickets'), 'id'));
        $this->assertSame([$delNodo->id], array_column($r->json('active_incidents'), 'id'));
        $this->assertSame('Nodo Centro', $r->json('active_incidents.0.sectorial.name'));
    }

    #[Test]
    public function open_for_no_acepta_un_cliente_de_otro_isp(): void
    {
        $ajeno = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $this->actingAs($this->operador)->getJson("/api/support/open-for?user_id={$ajeno->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    // ── Vincular un duplicado (§ 19.7) ───────────────────────────────────

    #[Test]
    public function pasar_a_duplicado_exige_decir_de_cual(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->operador)->patchJson("/api/support/{$ticket->id}/status", ['status' => 'duplicado'])
            ->assertUnprocessable()->assertJsonValidationErrors('duplicate_of_id');

        $this->assertSame(TicketWorkflow::RADICADO, $ticket->fresh()->status);
    }

    #[Test]
    public function el_duplicado_queda_vinculado_a_su_original_y_consta_en_el_historial(): void
    {
        $cliente = $this->cliente();
        $original = $this->ticket(TicketWorkflow::ASIGNADO, ['user_id' => $cliente->id]);
        $repetido = $this->ticket(TicketWorkflow::RADICADO, ['user_id' => $cliente->id]);

        $this->actingAs($this->operador)->patchJson("/api/support/{$repetido->id}/status", [
            'status' => 'duplicado', 'duplicate_of_id' => $original->id,
        ])->assertOk();

        $repetido->refresh();
        $this->assertSame(TicketWorkflow::DUPLICADO, $repetido->status);
        $this->assertSame($original->id, (int) $repetido->duplicate_of_id);

        $evento = SupportTicketHistory::where('support_ticket_id', $repetido->id)
            ->where('event_type', SupportTicketHistory::DUPLICATE_LINKED)->sole();
        $this->assertSame((string) $original->id, $evento->new_value);
        $this->assertSame($this->operador->id, (int) $evento->actor_user_id);
    }

    #[Test]
    public function no_es_duplicado_un_ticket_de_otro_cliente_ni_de_si_mismo_ni_de_otro_isp(): void
    {
        $ticket = $this->ticket();
        $deOtroCliente = $this->ticket(TicketWorkflow::RADICADO, ['user_id' => $this->cliente()->id]);

        $otro = Tenant::factory()->create();
        $ajeno = SupportTicket::create([
            'tenant_id' => $otro->id, 'user_id' => $ticket->user_id, 'subject' => 'Ajeno',
            'status' => 'radicado', 'priority' => 'medium', 'category' => 'technical',
        ]);

        foreach ([$deOtroCliente->id, $ticket->id, $ajeno->id, 999999] as $objetivo) {
            $this->actingAs($this->operador)->patchJson("/api/support/{$ticket->id}/status", [
                'status' => 'duplicado', 'duplicate_of_id' => $objetivo,
            ])->assertUnprocessable()->assertJsonValidationErrors('duplicate_of_id');
        }

        $this->assertNull($ticket->fresh()->duplicate_of_id);
    }

    #[Test]
    public function no_se_vincula_a_un_ticket_que_ya_es_duplicado(): void
    {
        $cliente = $this->cliente();
        $original = $this->ticket(TicketWorkflow::ASIGNADO, ['user_id' => $cliente->id]);
        $copia = $this->ticket(TicketWorkflow::DUPLICADO, ['user_id' => $cliente->id, 'duplicate_of_id' => $original->id]);
        $tercero = $this->ticket(TicketWorkflow::RADICADO, ['user_id' => $cliente->id]);

        $this->actingAs($this->operador)->patchJson("/api/support/{$tercero->id}/status", [
            'status' => 'duplicado', 'duplicate_of_id' => $copia->id,
        ])->assertUnprocessable()->assertJsonPath('errors.duplicate_of_id.0', "El ticket #{$copia->id} ya es un duplicado del #{$original->id}. Vincula el ticket original.");
    }

    #[Test]
    public function reabrir_un_duplicado_suelta_el_vinculo_y_lo_deja_en_el_evento(): void
    {
        $cliente = $this->cliente();
        $original = $this->ticket(TicketWorkflow::ASIGNADO, ['user_id' => $cliente->id]);
        $copia = $this->ticket(TicketWorkflow::DUPLICADO, ['user_id' => $cliente->id, 'duplicate_of_id' => $original->id]);

        $this->actingAs($this->operador)->postJson("/api/support/{$copia->id}/reopen", [
            'reason' => 'No era la misma falla: este es el router del segundo piso.',
        ])->assertOk();

        $copia->refresh();
        $this->assertSame(TicketWorkflow::REABIERTO, $copia->status);
        $this->assertNull($copia->duplicate_of_id);

        $evento = SupportTicketHistory::where('support_ticket_id', $copia->id)
            ->where('event_type', SupportTicketHistory::REOPENED)->sole();
        $this->assertSame($original->id, (int) $evento->metadata['duplicate_of']);
    }

    // ── Incidente padre (§ 19.8) ─────────────────────────────────────────

    #[Test]
    public function asociar_exige_un_incidente_masivo_activo(): void
    {
        $ticket = $this->ticket();
        $individual = $this->ticket(TicketWorkflow::ASIGNADO, ['user_id' => $this->cliente()->id]);
        $cerrado = $this->masivo($this->elemento('Nodo', 'nodo'), TicketWorkflow::CERRADO);

        $this->actingAs($this->operador)->patchJson("/api/support/{$ticket->id}/status", ['status' => 'asociado_incidente_masivo'])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_ticket_id');

        foreach ([$individual->id, $cerrado->id] as $objetivo) {
            $this->actingAs($this->operador)->patchJson("/api/support/{$ticket->id}/status", [
                'status' => 'asociado_incidente_masivo', 'parent_ticket_id' => $objetivo,
            ])->assertUnprocessable()->assertJsonValidationErrors('parent_ticket_id');
        }

        $this->assertNull($ticket->fresh()->parent_ticket_id);
    }

    #[Test]
    public function se_asocian_varios_tickets_a_un_incidente_padre(): void
    {
        $masivo = $this->masivo($this->elemento('Nodo Centro', 'nodo'));
        $a = $this->ticket(TicketWorkflow::RADICADO, ['user_id' => $this->cliente()->id]);
        $b = $this->ticket(TicketWorkflow::EN_CLASIFICACION, ['user_id' => $this->cliente()->id]);

        foreach ([$a, $b] as $t) {
            $this->actingAs($this->operador)->patchJson("/api/support/{$t->id}/status", [
                'status' => 'asociado_incidente_masivo', 'parent_ticket_id' => $masivo->id,
            ])->assertOk();
        }

        $this->assertSame([$a->id, $b->id], $masivo->incidentChildren()->orderBy('id')->pluck('id')->all());
        $this->assertSame(2, SupportTicketHistory::where('event_type', SupportTicketHistory::INCIDENT_LINKED)->count());

        $links = $this->actingAs($this->operador)->getJson("/api/support/{$masivo->id}/links")->assertOk();
        $this->assertSame([$a->id, $b->id], array_column($links->json('incident_children'), 'id'));
        $this->assertSame('Ana Ruiz', $links->json('incident_children.0.customer'));

        $this->actingAs($this->operador)->getJson("/api/support/{$a->id}/links")
            ->assertOk()->assertJsonPath('parent.id', $masivo->id)->assertJsonPath('parent.children_count', 2);
    }

    #[Test]
    public function un_masivo_no_se_ofrece_como_duplicado_ni_asociado(): void
    {
        $masivo = $this->masivo($this->elemento('Nodo', 'nodo'), TicketWorkflow::EN_CLASIFICACION);
        $ticket = $this->ticket(TicketWorkflow::EN_CLASIFICACION);

        $destinosDe = fn (SupportTicket $t) => collect(
            $this->actingAs($this->operador)->getJson("/api/support/{$t->id}/transitions")->assertOk()->json('transitions')
        )->keyBy('code');

        $delMasivo = $destinosDe($masivo);
        $this->assertFalse($delMasivo->has('duplicado'));
        $this->assertFalse($delMasivo->has('asociado_incidente_masivo'));

        // El individual sí, y la respuesta dice qué vínculo pide cada uno.
        $delIndividual = $destinosDe($ticket);
        $this->assertSame('duplicate_of_id', $delIndividual['duplicado']['requires_link']);
        $this->assertSame('parent_ticket_id', $delIndividual['asociado_incidente_masivo']['requires_link']);
        $this->assertNull($delIndividual['en_diagnostico_remoto']['requires_link']);
    }

    #[Test]
    public function al_restablecerse_el_incidente_se_restablecen_sus_asociados_pero_no_se_cierran(): void
    {
        $masivo = $this->masivo($this->elemento('Nodo Centro', 'nodo'), TicketWorkflow::EN_DIAGNOSTICO_REMOTO);
        $a = $this->ticket(TicketWorkflow::ASOCIADO_INCIDENTE_MASIVO, ['parent_ticket_id' => $masivo->id, 'user_id' => $this->cliente()->id]);
        $b = $this->ticket(TicketWorkflow::ASOCIADO_INCIDENTE_MASIVO, ['parent_ticket_id' => $masivo->id, 'user_id' => $this->cliente()->id]);
        // Éste se sacó del incidente porque resultó ser otra falla: no se arrastra.
        $fuera = $this->ticket(TicketWorkflow::EN_INTERVENCION, ['parent_ticket_id' => $masivo->id, 'user_id' => $this->cliente()->id]);

        $this->actingAs($this->operador)->patchJson("/api/support/{$masivo->id}/status", ['status' => 'servicio_restablecido'])
            ->assertOk()->assertJsonPath('restored_children', 2);

        foreach ([$a, $b] as $t) {
            $t->refresh();
            $this->assertSame(TicketWorkflow::SERVICIO_RESTABLECIDO, $t->status);
            $this->assertNotNull($t->resolved_at);
            $this->assertNull($t->closed_at);
            $this->assertTrue(SupportTicketHistory::where('support_ticket_id', $t->id)
                ->where('event_type', SupportTicketHistory::RESTORED_BY_INCIDENT)->exists());
        }

        $this->assertSame(TicketWorkflow::EN_INTERVENCION, $fuera->fresh()->status);
    }

    // ── Reincidencia (§ 16) ──────────────────────────────────────────────

    #[Test]
    public function la_reincidencia_del_ticket_se_cuenta_por_ventana_y_dimension(): void
    {
        $cliente = $this->cliente();
        $ap = $this->elemento('AP Norte', 'sectorial');
        $ahora = Carbon::parse('2026-10-31 12:00:00');

        $actual = $this->ticket(TicketWorkflow::RADICADO, ['user_id' => $cliente->id, 'symptom' => 'S02', 'sectorial_id' => $ap->id], $ahora);

        $this->ticket(TicketWorkflow::CERRADO, ['user_id' => $cliente->id, 'symptom' => 'S02'], $ahora->copy()->subDays(3));
        $this->ticket(TicketWorkflow::CERRADO, ['user_id' => $cliente->id], $ahora->copy()->subDays(20));
        $this->ticket(TicketWorkflow::CERRADO, ['user_id' => $cliente->id, 'symptom' => 'S02'], $ahora->copy()->subDays(60));
        // Fuera de las tres ventanas.
        $this->ticket(TicketWorkflow::CERRADO, ['user_id' => $cliente->id], $ahora->copy()->subDays(100));
        // Un duplicado no es una repetición de la falla.
        $this->ticket(TicketWorkflow::DUPLICADO, ['user_id' => $cliente->id], $ahora->copy()->subDays(2));
        // Otro cliente en el mismo AP: cuenta para la infraestructura, no para el servicio.
        $this->ticket(TicketWorkflow::CERRADO, ['user_id' => $this->cliente()->id, 'sectorial_id' => $ap->id], $ahora->copy()->subDays(5));
        // Posterior al ticket: no es anterior, no cuenta.
        $this->ticket(TicketWorkflow::RADICADO, ['user_id' => $cliente->id], $ahora->copy()->addDay());

        $r = $this->actingAs($this->operador)->getJson("/api/support/{$actual->id}/links")->assertOk();

        $this->assertSame(['service' => 1, 'symptom' => 1, 'cause' => 0, 'infrastructure' => 1], $r->json('recurrence.windows.7'));
        $this->assertSame(['service' => 2, 'symptom' => 1, 'cause' => 0, 'infrastructure' => 1], $r->json('recurrence.windows.30'));
        $this->assertSame(['service' => 3, 'symptom' => 2, 'cause' => 0, 'infrastructure' => 1], $r->json('recurrence.windows.90'));
        $this->assertCount(3, $r->json('recurrence.previous'));
    }

    #[Test]
    public function el_tablero_da_la_tasa_de_reincidencia_y_los_incidentes(): void
    {
        $ana = $this->cliente();
        $luis = $this->cliente(null, 'Luis');
        $base = Carbon::parse('2026-10-20 12:00:00');

        // Ana: un ticket de septiembre y otro 10 días después → reincide a 30 y 90, no a 7.
        $this->ticket(TicketWorkflow::CERRADO, ['user_id' => $ana->id], Carbon::parse('2026-09-25 12:00:00'));
        $this->ticket(TicketWorkflow::CERRADO, ['user_id' => $ana->id], Carbon::parse('2026-10-05 12:00:00'));
        // Luis: dos tickets con 2 días de diferencia → reincide en las tres.
        $this->ticket(TicketWorkflow::CERRADO, ['user_id' => $luis->id], $base);
        $this->ticket(TicketWorkflow::RADICADO, ['user_id' => $luis->id], $base->copy()->addDays(2));
        // Un masivo con un asociado, y un duplicado: no entran en la tasa.
        $masivo = $this->masivo($this->elemento('Nodo', 'nodo'), TicketWorkflow::EN_DIAGNOSTICO_REMOTO, $base);
        $this->ticket(TicketWorkflow::ASOCIADO_INCIDENTE_MASIVO, ['user_id' => $this->cliente()->id, 'parent_ticket_id' => $masivo->id], $base);
        $this->ticket(TicketWorkflow::DUPLICADO, ['user_id' => $luis->id], $base->copy()->addDay());

        // El tablero mira sólo octubre, pero el ticket de Ana de septiembre
        // cuenta como anterior: la ventana mira hacia atrás fuera del filtro.
        $stats = $this->actingAs($this->operador)->getJson('/api/support/statistics?created_from=2026-10-01')->assertOk()->json();

        $this->assertSame(['mass_incidents' => 1, 'associated_tickets' => 1, 'duplicates' => 1], $stats['incidents']);

        // Base: Ana-oct, Luis×2, el asociado = 4 (sin masivo ni duplicado).
        $this->assertSame(4, $stats['recurrence']['base']);
        $this->assertEquals(['count' => 1, 'rate' => 25.0], $stats['recurrence']['windows']['7']);
        $this->assertEquals(['count' => 2, 'rate' => 50.0], $stats['recurrence']['windows']['30']);
        $this->assertEquals(['count' => 2, 'rate' => 50.0], $stats['recurrence']['windows']['90']);
    }

    #[Test]
    public function la_exportacion_trae_la_modalidad_y_los_vinculos(): void
    {
        $cliente = $this->cliente();
        $masivo = $this->masivo($this->elemento('Nodo', 'nodo'));
        $original = $this->ticket(TicketWorkflow::ASIGNADO, ['user_id' => $cliente->id]);
        $copia = $this->ticket(TicketWorkflow::DUPLICADO, ['user_id' => $cliente->id, 'duplicate_of_id' => $original->id]);
        $asociado = $this->ticket(TicketWorkflow::ASOCIADO_INCIDENTE_MASIVO, ['user_id' => $cliente->id, 'parent_ticket_id' => $masivo->id]);

        $csv = $this->actingAs($this->operador)->get('/api/support/export')->assertOk()->streamedContent();
        $filas = array_map(
            fn ($l) => str_getcsv($l, ';', '"', ''),
            array_values(array_filter(preg_split('/\r\n|\n/', preg_replace('/^\xEF\xBB\xBF/', '', $csv)), fn ($l) => $l !== '')),
        );

        $cab = array_flip($filas[0]);
        $porId = collect(array_slice($filas, 1))->keyBy(fn ($f) => (int) $f[0]);

        $this->assertSame('Masivo', $porId[$masivo->id][$cab['Modalidad']]);
        $this->assertSame('Individual', $porId[$copia->id][$cab['Modalidad']]);
        $this->assertSame((string) $original->id, $porId[$copia->id][$cab['Duplicado de']]);
        $this->assertSame((string) $masivo->id, $porId[$asociado->id][$cab['Incidente padre']]);
        $this->assertSame('', $porId[$original->id][$cab['Incidente padre']]);
    }

    // ── Ayudas ───────────────────────────────────────────────────────────

    /** @param  array<int, string>  $permisos */
    private function usuarioCon(array $permisos): User
    {
        // `staff`: las transiciones viven en el grupo `staff_profile`, que
        // autoriza por código de rol además del permiso.
        $rol = Role::create([
            'name' => 'Rol ' . uniqid(), 'code' => 'staff',
            'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
        ]);

        return User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rol->id]);
    }

    private function cliente(?Sectorial $sectorial = null, string $nombre = 'Ana', string $apellido = 'Ruiz'): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        CustomerProfile::create([
            'user_id' => $user->id, 'name' => $nombre, 'last_name' => $apellido,
            'status' => true, 'sectorial_id' => $sectorial?->id,
        ]);

        return $user;
    }

    private function elemento(string $nombre, string $tipo, ?Sectorial $padre = null): Sectorial
    {
        return Sectorial::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'element_type' => $tipo, 'parent_id' => $padre?->id,
        ]);
    }

    /**
     * Ticket en el estado pedido, escrito directo en base (como en
     * TicketWorkflowTest). `created_at` va por `DB::table` para que el
     * observer del historial no lo reinterprete.
     *
     * @param  array<string, mixed>  $extra
     */
    private function ticket(string $estado = TicketWorkflow::RADICADO, array $extra = [], ?Carbon $creado = null): SupportTicket
    {
        // `tenant_id` PRIMERO: los mutators del diagnóstico (`symptom`)
        // resuelven el código en el ámbito del tenant, en orden de llegada.
        $ticket = SupportTicket::create(['tenant_id' => $this->tenant->id] + $extra + [
            'user_id'   => array_key_exists('user_id', $extra) ? null : $this->clientePorDefecto()->id,
            'subject'   => 'Sin señal',
            'status'    => $estado, 'priority' => 'medium', 'category' => 'technical',
        ]);

        if ($creado) {
            DB::table('support_ticket')->where('id', $ticket->id)
                ->update(['created_at' => $creado->copy()->utc()->format('Y-m-d H:i:s')]);
        }

        return $ticket->fresh();
    }

    private function masivo(Sectorial $elemento, string $estado = TicketWorkflow::RADICADO, ?Carbon $creado = null): SupportTicket
    {
        return $this->ticket($estado, [
            'user_id' => null, 'modality' => SupportTicket::MODALITY_MASIVO,
            'sectorial_id' => $elemento->id, 'subject' => 'Caída de ' . $elemento->name,
        ], $creado);
    }

    private ?User $porDefecto = null;

    private function clientePorDefecto(): User
    {
        return $this->porDefecto ??= $this->cliente();
    }
}
