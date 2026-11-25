<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TicketWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Flujo de tickets por ISP: formal o simple (bitácora § 133).
 *
 * El formal es la Solicitud Maestra y NO cambia. El simple deja trabajar con
 * cuatro estados y cerrar diciendo sólo qué se hizo. Estas pruebas fijan las
 * dos mitades: que el simple de verdad simplifica, y que un ISP formal sigue
 * exactamente con las reglas de antes.
 */
class TicketSimpleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        Role::create(['name' => 'Superadmin global', 'code' => 'superadmin', 'permissions' => ['*'], 'tenant_id' => null]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create(['user_id' => $this->customer->id, 'name' => 'Ana', 'last_name' => 'Ruiz', 'status' => true]);
    }

    private function modo(string $modo): void
    {
        $this->tenant->forceFill(['ticket_workflow_mode' => $modo])->save();
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(array $permisos): User
    {
        $rol = Role::create([
            'name' => 'Rol ' . uniqid(), 'code' => 'admin',
            'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
        ]);

        return User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rol->id]);
    }

    private function supervisor(): User
    {
        return $this->usuarioCon([
            Permissions::TICKET_VIEW, Permissions::TICKET_EDIT, Permissions::TICKET_TRANSITION,
            Permissions::TICKET_CLOSE, Permissions::TICKET_DIAGNOSE,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function ticket(string $estado, array $extra = []): SupportTicket
    {
        return SupportTicket::create($extra + [
            'tenant_id' => $this->tenant->id, 'user_id' => $this->customer->id,
            'subject' => 'Sin internet', 'status' => $estado, 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    // ── El modo ──────────────────────────────────────────────────────────

    #[Test]
    public function un_isp_nace_formal_y_lo_ilegible_cuenta_como_formal(): void
    {
        $this->assertSame(TicketWorkflow::MODO_FORMAL, TicketWorkflow::modoDelTenant($this->tenant->id));
        $this->assertSame(TicketWorkflow::MODO_FORMAL, TicketWorkflow::modoDelTenant(null));
        $this->assertSame(TicketWorkflow::MODO_FORMAL, TicketWorkflow::modoDelTenant(999999));

        $this->modo(TicketWorkflow::MODO_SIMPLE);
        $this->assertSame(TicketWorkflow::MODO_SIMPLE, TicketWorkflow::modoDelTenant($this->tenant->id));
    }

    #[Test]
    public function el_administrador_lo_cambia_desde_la_configuracion(): void
    {
        Sanctum::actingAs($this->usuarioCon(['manage_tenant']));

        $this->patchJson('/api/tenant/config', ['ticket_workflow_mode' => 'simple'])->assertOk();
        $this->assertSame('simple', $this->tenant->fresh()->ticket_workflow_mode);

        $this->patchJson('/api/tenant/config', ['ticket_workflow_mode' => 'otro'])->assertUnprocessable();
        $this->assertSame('simple', $this->tenant->fresh()->ticket_workflow_mode);
    }

    // ── Moverse ──────────────────────────────────────────────────────────

    #[Test]
    public function en_el_simple_de_abierto_se_pasa_directo_a_en_proceso(): void
    {
        $this->modo(TicketWorkflow::MODO_SIMPLE);
        $ticket = $this->ticket(TicketWorkflow::RADICADO);

        $this->actingAs($this->supervisor())
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => TicketWorkflow::EN_INTERVENCION])
            ->assertOk();

        $this->assertSame(TicketWorkflow::EN_INTERVENCION, $ticket->fresh()->status);
    }

    #[Test]
    public function en_el_formal_ese_salto_sigue_prohibido(): void
    {
        $ticket = $this->ticket(TicketWorkflow::RADICADO);

        $this->actingAs($this->supervisor())
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => TicketWorkflow::EN_INTERVENCION])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'ticket_transition_not_allowed');

        $this->assertSame(TicketWorkflow::RADICADO, $ticket->fresh()->status);
    }

    // ── Cerrar ───────────────────────────────────────────────────────────

    #[Test]
    public function en_el_simple_se_cierra_desde_abierto_con_solo_decir_que_se_hizo(): void
    {
        $this->modo(TicketWorkflow::MODO_SIMPLE);
        $ticket = $this->ticket(TicketWorkflow::RADICADO, ['solution' => 'AC02']);

        // Sin causa confirmada, sin resultado, sin medición ni justificación.
        $this->actingAs($this->supervisor())
            ->postJson("/api/support/{$ticket->id}/close", ['reason' => 'Se reinició el equipo'])
            ->assertOk();

        $this->assertSame(TicketWorkflow::CERRADO, $ticket->fresh()->status);
    }

    #[Test]
    public function en_el_simple_sin_decir_que_se_hizo_no_se_cierra(): void
    {
        $this->modo(TicketWorkflow::MODO_SIMPLE);
        $ticket = $this->ticket(TicketWorkflow::EN_INTERVENCION);

        $this->actingAs($this->supervisor())
            ->postJson("/api/support/{$ticket->id}/close")
            ->assertUnprocessable()
            ->assertJsonPath('error', 'ticket_closure_requirements_missing')
            ->assertJsonPath('missing', ['solution']);
    }

    #[Test]
    public function en_el_formal_el_mismo_ticket_no_se_puede_cerrar(): void
    {
        $ticket = $this->ticket(TicketWorkflow::RADICADO, ['solution' => 'AC02']);

        $this->actingAs($this->supervisor())
            ->postJson("/api/support/{$ticket->id}/close")
            ->assertUnprocessable()
            ->assertJsonPath('error', 'ticket_cannot_close_from_status');
    }

    #[Test]
    public function en_el_formal_restablecido_sigue_pidiendo_causa_resultado_y_medicion(): void
    {
        $ticket = $this->ticket(TicketWorkflow::SERVICIO_RESTABLECIDO, ['solution' => 'AC02']);

        $respuesta = $this->actingAs($this->supervisor())
            ->postJson("/api/support/{$ticket->id}/close")
            ->assertUnprocessable();

        $this->assertEqualsCanonicalizing(
            ['confirmed_cause', 'result', 'prueba_final'],
            $respuesta->json('missing'),
        );
    }

    #[Test]
    public function en_el_simple_la_solucion_temporal_se_cierra_sin_autorizacion(): void
    {
        $this->modo(TicketWorkflow::MODO_SIMPLE);
        $ticket = $this->ticket(TicketWorkflow::SOLUCION_TEMPORAL, ['solution' => 'AC02']);

        $this->actingAs($this->supervisor())->postJson("/api/support/{$ticket->id}/close")->assertOk();
    }

    #[Test]
    public function el_simple_no_quita_el_permiso_de_cerrar(): void
    {
        $this->modo(TicketWorkflow::MODO_SIMPLE);
        $ticket = $this->ticket(TicketWorkflow::EN_INTERVENCION, ['solution' => 'AC02']);

        $sinCerrar = $this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_TRANSITION]);

        $this->actingAs($sinCerrar)->postJson("/api/support/{$ticket->id}/close")->assertForbidden();

        // Pero puede proponerlo, también desde «En proceso».
        $this->actingAs($sinCerrar)
            ->postJson("/api/support/{$ticket->id}/propose-closure", ['reason' => 'Equipo cambiado y navegando'])
            ->assertOk();

        $this->assertSame(TicketWorkflow::EN_OBSERVACION, $ticket->fresh()->status);
    }

    // ── Lo que ve la pantalla ────────────────────────────────────────────

    #[Test]
    public function transitions_en_el_simple_ofrece_en_proceso_y_cerrar_con_una_sola_casilla(): void
    {
        $this->modo(TicketWorkflow::MODO_SIMPLE);
        $ticket = $this->ticket(TicketWorkflow::RADICADO);

        $datos = $this->actingAs($this->supervisor())
            ->getJson("/api/support/{$ticket->id}/transitions")->assertOk()->json();

        $this->assertSame('simple', $datos['workflow_mode']);
        $this->assertSame('Abierto', $datos['status_label']);
        $this->assertSame('nuevo', $datos['status_group']);
        $this->assertTrue($datos['actions']['close']);
        $this->assertSame([], $datos['close_path']);
        $this->assertFalse($datos['requires_final_test']);

        $this->assertSame(['kind' => 'transition', 'code' => 'en_intervencion', 'label' => 'En proceso'], $datos['next_step']);
        $this->assertSame(['solution'], array_column($datos['checklist'], 'key'));

        $principal = array_values(array_filter($datos['transitions'], fn ($t) => $t['primary']));
        $this->assertSame(['en_intervencion'], array_column($principal, 'code'));
    }

    #[Test]
    public function transitions_en_el_formal_conserva_el_camino_y_la_medicion(): void
    {
        $ticket = $this->ticket(TicketWorkflow::RADICADO);

        $datos = $this->actingAs($this->supervisor())
            ->getJson("/api/support/{$ticket->id}/transitions")->assertOk()->json();

        $this->assertSame('formal', $datos['workflow_mode']);
        $this->assertFalse($datos['actions']['close']);
        $this->assertTrue($datos['requires_final_test']);
        $this->assertSame('en_clasificacion', $datos['next_step']['code']);
        $this->assertSame(
            ['status', 'confirmed_cause', 'solution', 'result', 'prueba_final'],
            array_column($datos['checklist'], 'key'),
        );
    }

    #[Test]
    public function en_proceso_lo_principal_es_cerrar(): void
    {
        $this->modo(TicketWorkflow::MODO_SIMPLE);
        $ticket = $this->ticket(TicketWorkflow::EN_INTERVENCION);

        $datos = $this->actingAs($this->supervisor())
            ->getJson("/api/support/{$ticket->id}/transitions")->assertOk()->json();

        $this->assertSame('close', $datos['next_step']['kind']);
    }

    #[Test]
    public function el_catalogo_dice_el_modo_y_como_se_llama_cada_estado(): void
    {
        $this->modo(TicketWorkflow::MODO_SIMPLE);

        $datos = $this->actingAs($this->supervisor())->getJson('/api/catalogs/ticket')->assertOk()->json();

        $this->assertSame('simple', $datos['workflow_mode']);
        $this->assertSame('Abierto', $datos['status_display']['radicado']['label']);
        $this->assertSame('en_espera', $datos['status_display']['pendiente_material']['group']);

        // `statuses` no cambia: sigue con el nombre del catálogo.
        $radicado = collect($datos['statuses'])->firstWhere('code', 'radicado');
        $this->assertNotSame('Abierto', $radicado['label']);
    }

    #[Test]
    public function todo_codigo_de_la_matriz_tiene_grupo_y_nombre_simple(): void
    {
        $codigos = [...TicketWorkflow::estadosActivos(), ...TicketWorkflow::TERMINALES];

        foreach ($codigos as $codigo) {
            $this->assertArrayHasKey($codigo, TicketWorkflow::GRUPOS, "«{$codigo}» no tiene grupo: no saldría en ninguna pestaña.");
            $this->assertArrayHasKey($codigo, TicketWorkflow::ETIQUETAS_SIMPLES, "«{$codigo}» no tiene nombre en el modo simple.");
        }
    }
}
