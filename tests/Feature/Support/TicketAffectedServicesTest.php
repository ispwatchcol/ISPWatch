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
 * F1-16 de CNO · Servicios afectados y minutos-cliente (§ 16 y § 17).
 *
 * Lo que se fija:
 *  - el incidente masivo registra cuántos servicios afectó la falla y si la
 *    cifra es estimada o confirmada; un ticket individual no la lleva;
 *  - los CONTACTOS (tickets asociados) se cuentan aparte de los servicios
 *    afectados;
 *  - minutos-cliente = servicios afectados × (restablecimiento − radicación),
 *    en el expediente, en el tablero y en la exportación;
 *  - corregir la cifra queda en el historial.
 */
class TicketAffectedServicesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $operador;
    private Sectorial $nodo;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        // Se quema el id 1: `CheckPermission` deja pasar siempre a role_id == 1.
        Role::create(['name' => 'Superadmin global', 'code' => 'superadmin', 'permissions' => ['*'], 'tenant_id' => null]);

        $this->operador = $this->usuarioCon([
            Permissions::TICKET_VIEW, Permissions::TICKET_CREATE, Permissions::TICKET_EDIT,
            Permissions::TICKET_TRANSITION, Permissions::TICKET_EXPORT,
        ]);

        $this->nodo = Sectorial::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Nodo Centro', 'element_type' => 'nodo',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Alta ─────────────────────────────────────────────────────────────

    #[Test]
    public function el_masivo_nace_con_su_cifra_de_servicios_afectados(): void
    {
        $id = $this->actingAs($this->operador)->postJson('/api/support', [
            'subject' => 'Caída del nodo', 'modality' => 'masivo', 'sectorial_id' => $this->nodo->id,
            'affected_services' => 120, 'affected_services_basis' => 'estimado',
        ])->assertCreated()->json('ticket.id');

        $masivo = SupportTicket::find($id);
        $this->assertSame(120, $masivo->affected_services);
        $this->assertSame('estimado', $masivo->affected_services_basis);

        $alta = SupportTicketHistory::where('support_ticket_id', $id)
            ->where('event_type', SupportTicketHistory::CREATED)->first();
        $this->assertSame('120 (estimado)', $alta->metadata['servicios_afectados']);
    }

    #[Test]
    public function la_cifra_exige_decir_si_es_estimada_o_confirmada(): void
    {
        $this->actingAs($this->operador)->postJson('/api/support', [
            'subject' => 'Caída del nodo', 'modality' => 'masivo', 'sectorial_id' => $this->nodo->id,
            'affected_services' => 120,
        ])->assertUnprocessable()->assertJsonValidationErrors('affected_services_basis');

        $this->actingAs($this->operador)->postJson('/api/support', [
            'subject' => 'Caída del nodo', 'modality' => 'masivo', 'sectorial_id' => $this->nodo->id,
            'affected_services' => 120, 'affected_services_basis' => 'aproximado',
        ])->assertUnprocessable()->assertJsonValidationErrors('affected_services_basis');

        $this->actingAs($this->operador)->postJson('/api/support', [
            'subject' => 'Caída del nodo', 'modality' => 'masivo', 'sectorial_id' => $this->nodo->id,
            'affected_services' => -3, 'affected_services_basis' => 'estimado',
        ])->assertUnprocessable()->assertJsonValidationErrors('affected_services');
    }

    #[Test]
    public function un_ticket_individual_no_lleva_servicios_afectados(): void
    {
        $this->actingAs($this->operador)->postJson('/api/support', [
            'subject' => 'Sin internet', 'user_id' => $this->cliente()->id,
            'affected_services' => 1, 'affected_services_basis' => 'confirmado',
        ])->assertUnprocessable()->assertJsonValidationErrors(['affected_services', 'affected_services_basis']);
    }

    // ── Corrección de la cifra ───────────────────────────────────────────

    #[Test]
    public function pasar_la_cifra_de_estimada_a_confirmada_queda_en_el_historial(): void
    {
        $masivo = $this->masivo(['affected_services' => 120, 'affected_services_basis' => 'estimado']);

        $this->actingAs($this->operador)->putJson("/api/support/{$masivo->id}", [
            'affected_services' => 134, 'affected_services_basis' => 'confirmado',
        ])->assertOk();

        $masivo->refresh();
        $this->assertSame(134, $masivo->affected_services);
        $this->assertSame('confirmado', $masivo->affected_services_basis);

        $evento = SupportTicketHistory::where('support_ticket_id', $masivo->id)
            ->where('event_type', SupportTicketHistory::AFFECTED_SERVICES)->sole();
        $this->assertSame('120 (estimado)', $evento->old_value);
        $this->assertSame('134 (confirmado)', $evento->new_value);
        $this->assertSame($this->operador->id, (int) $evento->actor_user_id);

        // Reenviar lo mismo no deja otro evento.
        $this->actingAs($this->operador)->putJson("/api/support/{$masivo->id}", [
            'affected_services' => 134, 'affected_services_basis' => 'confirmado',
        ])->assertOk();
        $this->assertSame(1, SupportTicketHistory::where('event_type', SupportTicketHistory::AFFECTED_SERVICES)->count());
    }

    #[Test]
    public function borrar_la_cifra_borra_tambien_su_naturaleza(): void
    {
        $masivo = $this->masivo(['affected_services' => 120, 'affected_services_basis' => 'estimado']);

        $this->actingAs($this->operador)->putJson("/api/support/{$masivo->id}", ['affected_services' => null])
            ->assertOk();

        $masivo->refresh();
        $this->assertNull($masivo->affected_services);
        $this->assertNull($masivo->affected_services_basis);
    }

    #[Test]
    public function poner_cifra_sin_naturaleza_en_la_edicion_se_rechaza(): void
    {
        $masivo = $this->masivo();

        $this->actingAs($this->operador)->putJson("/api/support/{$masivo->id}", ['affected_services' => 80])
            ->assertUnprocessable()->assertJsonValidationErrors('affected_services_basis');

        // La que ya estaba guardada sí vale: basta con mandar la cantidad.
        $masivo->update(['affected_services' => 50, 'affected_services_basis' => 'estimado']);
        $this->actingAs($this->operador)->putJson("/api/support/{$masivo->id}", ['affected_services' => 80])
            ->assertOk();
        $this->assertSame(80, $masivo->fresh()->affected_services);
    }

    #[Test]
    public function la_edicion_de_un_individual_rechaza_la_cifra_pero_no_el_formulario_vacio(): void
    {
        $ticket = $this->ticket(TicketWorkflow::RADICADO, ['user_id' => $this->cliente()->id]);

        $this->actingAs($this->operador)->putJson("/api/support/{$ticket->id}", [
            'affected_services' => 5, 'affected_services_basis' => 'estimado',
        ])->assertUnprocessable()->assertJsonValidationErrors('affected_services');

        // La pantalla de edición reenvía el formulario entero, con los dos
        // campos vacíos: eso no es un error.
        $this->actingAs($this->operador)->putJson("/api/support/{$ticket->id}", [
            'subject' => 'Sin internet', 'affected_services' => null, 'affected_services_basis' => null,
        ])->assertOk();
    }

    #[Test]
    public function corregir_la_cifra_exige_ticket_edit(): void
    {
        $masivo = $this->masivo(['affected_services' => 120, 'affected_services_basis' => 'estimado']);
        $soloVer = $this->usuarioCon([Permissions::TICKET_VIEW]);

        $this->actingAs($soloVer)->putJson("/api/support/{$masivo->id}", [
            'affected_services' => 10, 'affected_services_basis' => 'confirmado',
        ])->assertForbidden();

        $this->assertSame(120, $masivo->fresh()->affected_services);
    }

    // ── Expediente ───────────────────────────────────────────────────────

    #[Test]
    public function el_expediente_separa_contactos_de_servicios_y_calcula_minutos_cliente(): void
    {
        $creado = Carbon::parse('2026-11-07 10:00:00');
        $masivo = $this->masivo(['affected_services' => 120, 'affected_services_basis' => 'confirmado'], $creado);
        // Restablecido 90 minutos después de radicado.
        DB::table('support_ticket')->where('id', $masivo->id)->update([
            'resolved_at' => $creado->copy()->addMinutes(90)->format('Y-m-d H:i:s'),
            'status_id'   => DB::table('ticket_status')->where('code', TicketWorkflow::SERVICIO_RESTABLECIDO)->value('id'),
        ]);
        // Dos clientes llamaron: son los contactos, no los afectados.
        foreach (['Ana', 'Luis'] as $nombre) {
            $this->ticket(TicketWorkflow::ASOCIADO_INCIDENTE_MASIVO, [
                'user_id' => $this->cliente($nombre)->id, 'parent_ticket_id' => $masivo->id,
            ]);
        }

        $this->actingAs($this->operador)->getJson("/api/support/{$masivo->id}/links")
            ->assertOk()
            ->assertJsonPath('impact.affected_services', 120)
            ->assertJsonPath('impact.basis', 'confirmado')
            ->assertJsonPath('impact.contacts', 2)
            ->assertJsonPath('impact.duration_minutes', 90)
            ->assertJsonPath('impact.customer_minutes', 10800)
            ->assertJsonPath('impact.ongoing', false);
    }

    #[Test]
    public function un_incidente_en_curso_cuenta_hasta_ahora_y_lo_dice(): void
    {
        $creado = Carbon::parse('2026-11-07 10:00:00');
        Carbon::setTestNow($creado->copy()->addMinutes(45));
        $masivo = $this->masivo(['affected_services' => 30, 'affected_services_basis' => 'estimado'], $creado);

        $this->actingAs($this->operador)->getJson("/api/support/{$masivo->id}/links")
            ->assertOk()
            ->assertJsonPath('impact.duration_minutes', 45)
            ->assertJsonPath('impact.customer_minutes', 1350)
            ->assertJsonPath('impact.ongoing', true);
    }

    #[Test]
    public function un_individual_no_tiene_bloque_de_afectacion(): void
    {
        $ticket = $this->ticket(TicketWorkflow::RADICADO, ['user_id' => $this->cliente()->id]);

        $this->actingAs($this->operador)->getJson("/api/support/{$ticket->id}/links")
            ->assertOk()->assertJsonPath('impact', null);
    }

    // ── Tablero y exportación ────────────────────────────────────────────

    #[Test]
    public function el_tablero_suma_servicios_afectados_y_minutos_cliente_de_los_restablecidos(): void
    {
        $base = Carbon::parse('2026-10-20 12:00:00');
        Carbon::setTestNow($base->copy()->addDays(3));

        // Restablecido a los 60 minutos: 100 × 60 = 6.000 minutos-cliente.
        $resuelto = $this->masivo(['affected_services' => 100, 'affected_services_basis' => 'confirmado'], $base);
        DB::table('support_ticket')->where('id', $resuelto->id)->update([
            'resolved_at' => $base->copy()->addMinutes(60)->format('Y-m-d H:i:s'),
            'status_id'   => DB::table('ticket_status')->where('code', TicketWorkflow::SERVICIO_RESTABLECIDO)->value('id'),
        ]);
        // En curso: suma servicios afectados, no minutos-cliente.
        $this->masivo(['affected_services' => 50, 'affected_services_basis' => 'estimado'], $base);
        // Sin cifra: se cuenta aparte, como dato por completar.
        $this->masivo([], $base);

        $incidentes = $this->actingAs($this->operador)
            ->getJson('/api/support/statistics?created_from=2026-10-01')->assertOk()->json('incidents');

        $this->assertSame(3, $incidentes['mass_incidents']);
        $this->assertSame(150, $incidentes['affected_services']);
        $this->assertSame(100, $incidentes['affected_confirmed']);
        $this->assertSame(50, $incidentes['affected_estimated']);
        $this->assertSame(6000, $incidentes['customer_minutes']);
        $this->assertSame(2, $incidentes['ongoing']);
        $this->assertSame(1, $incidentes['without_affected']);
    }

    #[Test]
    public function la_exportacion_trae_afectados_contactos_y_minutos_cliente(): void
    {
        $creado = Carbon::parse('2026-11-07 10:00:00');
        $masivo = $this->masivo(['affected_services' => 40, 'affected_services_basis' => 'confirmado'], $creado);
        DB::table('support_ticket')->where('id', $masivo->id)->update([
            'resolved_at' => $creado->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
            'status_id'   => DB::table('ticket_status')->where('code', TicketWorkflow::SERVICIO_RESTABLECIDO)->value('id'),
        ]);
        $asociado = $this->ticket(TicketWorkflow::ASOCIADO_INCIDENTE_MASIVO, [
            'user_id' => $this->cliente()->id, 'parent_ticket_id' => $masivo->id,
        ]);

        $csv = $this->actingAs($this->operador)->get('/api/support/export')->assertOk()->streamedContent();
        $filas = array_map(
            fn ($l) => str_getcsv($l, ';', '"', ''),
            array_values(array_filter(preg_split('/\r\n|\n/', preg_replace('/^\xEF\xBB\xBF/', '', $csv)), fn ($l) => $l !== '')),
        );

        $cab = array_flip($filas[0]);
        $porId = collect(array_slice($filas, 1))->keyBy(fn ($f) => (int) $f[0]);

        $this->assertSame('40', $porId[$masivo->id][$cab['Servicios afectados']]);
        $this->assertSame('confirmado', $porId[$masivo->id][$cab['Cifra de afectados']]);
        $this->assertSame('1', $porId[$masivo->id][$cab['Contactos asociados']]);
        $this->assertSame('1200', $porId[$masivo->id][$cab['Minutos-cliente']]);
        // Un individual deja las cuatro columnas vacías.
        $this->assertSame('', $porId[$asociado->id][$cab['Servicios afectados']]);
        $this->assertSame('', $porId[$asociado->id][$cab['Minutos-cliente']]);
    }

    // ── Ayudas ───────────────────────────────────────────────────────────

    /** @param  array<int, string>  $permisos */
    private function usuarioCon(array $permisos): User
    {
        $rol = Role::create([
            'name' => 'Rol ' . uniqid(), 'code' => 'staff',
            'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
        ]);

        return User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rol->id]);
    }

    private function cliente(string $nombre = 'Ana'): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        CustomerProfile::create([
            'user_id' => $user->id, 'name' => $nombre, 'last_name' => 'Ruiz', 'status' => true,
        ]);

        return $user;
    }

    /**
     * Ticket escrito directo en base, como en TicketIncidentsTest. `created_at`
     * va por `DB::table` para que el observer no lo reinterprete.
     *
     * @param  array<string, mixed>  $extra
     */
    private function ticket(string $estado = TicketWorkflow::RADICADO, array $extra = [], ?Carbon $creado = null): SupportTicket
    {
        $ticket = SupportTicket::create(['tenant_id' => $this->tenant->id] + $extra + [
            'subject'  => 'Sin señal',
            'status'   => $estado, 'priority' => 'medium', 'category' => 'technical',
        ]);

        if ($creado) {
            DB::table('support_ticket')->where('id', $ticket->id)
                ->update(['created_at' => $creado->copy()->utc()->format('Y-m-d H:i:s')]);
        }

        return $ticket->fresh();
    }

    /** @param  array<string, mixed>  $extra */
    private function masivo(array $extra = [], ?Carbon $creado = null): SupportTicket
    {
        return $this->ticket(TicketWorkflow::EN_DIAGNOSTICO_REMOTO, $extra + [
            'user_id' => null, 'modality' => SupportTicket::MODALITY_MASIVO,
            'sectorial_id' => $this->nodo->id, 'subject' => 'Caída del nodo',
        ], $creado);
    }
}
