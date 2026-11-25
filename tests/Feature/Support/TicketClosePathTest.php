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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «No me permite cerrar un ticket» (2026-11-25).
 *
 * El cierre funcionaba, pero el botón sólo existe en cinco estados. Desde
 * «Radicado» hacían falta cuatro transiciones y la pantalla no lo decía: listaba
 * lo que le faltaba al expediente y no ofrecía ningún botón de cierre. Ahora
 * `GET /transitions` dice POR QUÉ no se puede cerrar (`close_blocked_by`) y POR
 * DÓNDE se llega (`close_path`), y estas pruebas fijan que ese camino es de
 * verdad recorrible con los endpoints de siempre hasta cerrar.
 */
class TicketClosePathTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        // `role_id == 1` pasa siempre `CheckPermission`: se quema el id.
        Role::create(['name' => 'Superadmin global', 'code' => 'superadmin', 'permissions' => ['*'], 'tenant_id' => null]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create(['user_id' => $this->customer->id, 'name' => 'Ana', 'last_name' => 'Ruiz', 'status' => true]);
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
            Permissions::TICKET_CLOSE, Permissions::TICKET_DIAGNOSE, Permissions::TICKET_CONFIRM_CAUSE,
        ]);
    }

    private function ticket(string $estado): SupportTicket
    {
        return SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->customer->id,
            'subject' => 'Sin señal', 'status' => $estado, 'priority' => 'medium', 'category' => 'technical',
            'confirmed_cause' => 'RF', 'solution' => 'AC02', 'result' => 'R01',
        ]);
    }

    // ── El camino ─────────────────────────────────────────────────────────

    #[Test]
    public function el_camino_sigue_el_recorrido_normal_y_no_los_atajos(): void
    {
        $this->assertSame(
            [TicketWorkflow::EN_CLASIFICACION, TicketWorkflow::ASIGNADO, TicketWorkflow::EN_INTERVENCION, TicketWorkflow::SERVICIO_RESTABLECIDO],
            TicketWorkflow::caminoAlCierre(TicketWorkflow::RADICADO),
            'Desde Radicado la matriz permite «asociado a incidente masivo» (cerrable en un paso): no es lo que pasó.',
        );

        $this->assertSame(
            [TicketWorkflow::ASIGNADO, TicketWorkflow::EN_INTERVENCION, TicketWorkflow::SERVICIO_RESTABLECIDO],
            TicketWorkflow::caminoAlCierre(TicketWorkflow::OPEN),
            'Un ticket legacy no se manda por diagnóstico remoto: contaría como resolución remota.',
        );

        // Ya en diagnóstico remoto, restablecer desde ahí SÍ es lo que pasó.
        $this->assertSame([TicketWorkflow::SERVICIO_RESTABLECIDO], TicketWorkflow::caminoAlCierre(TicketWorkflow::EN_DIAGNOSTICO_REMOTO));
        $this->assertSame([], TicketWorkflow::caminoAlCierre(TicketWorkflow::SERVICIO_RESTABLECIDO));
        $this->assertNull(TicketWorkflow::caminoAlCierre(TicketWorkflow::CERRADO));
    }

    #[Test]
    public function cada_estado_no_terminal_tiene_camino_al_cierre(): void
    {
        foreach (TicketWorkflow::estadosActivos() as $estado) {
            $this->assertNotNull(
                TicketWorkflow::caminoAlCierre($estado),
                "Desde «{$estado}» la pantalla no podría decir cómo se llega al cierre.",
            );
        }
    }

    // ── El endpoint ───────────────────────────────────────────────────────

    #[Test]
    public function desde_radicado_dice_por_que_no_y_por_donde_y_el_camino_se_recorre_hasta_cerrar(): void
    {
        $yo = $this->supervisor();
        $ticket = $this->ticket(TicketWorkflow::RADICADO);

        $datos = $this->actingAs($yo)->getJson("/api/support/{$ticket->id}/transitions")->assertOk()->json();

        $this->assertFalse($datos['actions']['close']);
        $this->assertSame('status', $datos['close_blocked_by']);
        $this->assertTrue($datos['can_walk_close_path']);
        $camino = array_column($datos['close_path'], 'code');
        $this->assertSame(TicketWorkflow::caminoAlCierre(TicketWorkflow::RADICADO), $camino);
        $this->assertNotEmpty($datos['close_path'][0]['label']);

        // Lo que hace «Avanzar hasta…» en la pantalla: una transición por paso.
        foreach ($camino as $paso) {
            $this->actingAs($yo)->patchJson("/api/support/{$ticket->id}/status", ['status' => $paso])->assertOk();
        }

        $datos = $this->actingAs($yo)->getJson("/api/support/{$ticket->id}/transitions")->assertOk()->json();
        $this->assertTrue($datos['actions']['close']);
        $this->assertNull($datos['close_blocked_by']);
        $this->assertSame([], $datos['close_path']);

        // Y se cierra (con la justificación de la medición final, regla 5).
        $this->actingAs($yo)->postJson("/api/support/{$ticket->id}/close", [
            'final_test_waiver_reason' => $this->primeraRazonDeExencion(),
            'final_test_waiver_note'   => 'El cliente no tenía equipo para medir.',
        ])->assertOk();

        $this->assertSame(TicketWorkflow::CERRADO, $ticket->fresh()->status);
    }

    #[Test]
    public function sin_permiso_de_cierre_lo_dice_en_vez_de_esconder_el_boton(): void
    {
        $tecnico = $this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_TRANSITION]);
        $ticket = $this->ticket(TicketWorkflow::SERVICIO_RESTABLECIDO);

        $datos = $this->actingAs($tecnico)->getJson("/api/support/{$ticket->id}/transitions")->assertOk()->json();

        $this->assertFalse($datos['actions']['close']);
        $this->assertSame('permission', $datos['close_blocked_by']);
        $this->assertSame(Permissions::TICKET_CLOSE, $datos['close_permission']);
        $this->assertTrue($datos['actions']['propose_closure'], 'Le queda proponer el cierre.');
    }

    #[Test]
    public function sin_permiso_de_mover_el_estado_no_se_ofrece_recorrer_el_camino(): void
    {
        $soloVer = $this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_CLOSE]);
        $ticket = $this->ticket(TicketWorkflow::ASIGNADO);

        $datos = $this->actingAs($soloVer)->getJson("/api/support/{$ticket->id}/transitions")->assertOk()->json();

        $this->assertSame('status', $datos['close_blocked_by']);
        $this->assertNotEmpty($datos['close_path']);
        $this->assertFalse($datos['can_walk_close_path']);
    }

    #[Test]
    public function un_ticket_cerrado_no_ofrece_camino(): void
    {
        $ticket = $this->ticket(TicketWorkflow::CERRADO);

        $datos = $this->actingAs($this->supervisor())->getJson("/api/support/{$ticket->id}/transitions")->assertOk()->json();

        $this->assertSame('terminal', $datos['close_blocked_by']);
        $this->assertSame([], $datos['close_path']);
        $this->assertFalse($datos['can_walk_close_path']);
    }

    private function primeraRazonDeExencion(): string
    {
        return array_key_first(\App\Support\TicketMeasurements::razonesSinPruebaFinal());
    }
}
