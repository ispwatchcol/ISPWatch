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
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Abrir un ticket rápido (bitácora § 133): prioridad desde el alta, y un ticket
 * que nace con técnico queda «Asignado» en vez de esperar dos clics más.
 */
class TicketQuickCreateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $customer;
    private User $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        Role::create(['name' => 'Superadmin global', 'code' => 'superadmin', 'permissions' => ['*'], 'tenant_id' => null]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create(['user_id' => $this->customer->id, 'name' => 'Ana', 'last_name' => 'Ruiz', 'status' => true]);

        $this->tecnico = $this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_INTERVENE]);
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(array $permisos): User
    {
        $rol = Role::create([
            'name' => 'Rol ' . uniqid(), 'code' => 'staff',
            'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
        ]);

        return User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rol->id]);
    }

    private function recepcion(array $mas = []): User
    {
        return $this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_CREATE, Permissions::TICKET_EDIT, Permissions::TICKET_ASSIGN, ...$mas]);
    }

    #[Test]
    public function con_tecnico_al_abrir_queda_asignado_y_el_historial_lo_cuenta(): void
    {
        $id = $this->actingAs($this->recepcion())->postJson('/api/support', [
            'user_id'  => $this->customer->id,
            'subject'  => 'Sin internet',
            'staff_id' => $this->tecnico->id,
        ])->assertCreated()->json('ticket.id');

        $ticket = SupportTicket::find($id);
        $this->assertSame(TicketWorkflow::ASIGNADO, $ticket->status);

        // Dos movimientos reales de la matriz: se clasificó y se asignó.
        $pasos = SupportTicketHistory::where('support_ticket_id', $id)
            ->where('event_type', SupportTicketHistory::STATUS)
            ->count();
        $this->assertSame(2, $pasos);
    }

    #[Test]
    public function sin_tecnico_sigue_naciendo_en_la_recepcion(): void
    {
        $id = $this->actingAs($this->recepcion())->postJson('/api/support', [
            'user_id' => $this->customer->id,
            'subject' => 'Sin internet',
        ])->assertCreated()->json('ticket.id');

        $this->assertSame(TicketWorkflow::RADICADO, SupportTicket::find($id)->status);
    }

    #[Test]
    public function en_el_simple_el_tecnico_no_mueve_el_estado(): void
    {
        $this->tenant->forceFill(['ticket_workflow_mode' => 'simple'])->save();

        $id = $this->actingAs($this->recepcion())->postJson('/api/support', [
            'user_id'  => $this->customer->id,
            'subject'  => 'Sin internet',
            'staff_id' => $this->tecnico->id,
        ])->assertCreated()->json('ticket.id');

        $this->assertSame(TicketWorkflow::RADICADO, SupportTicket::find($id)->status);
    }

    #[Test]
    public function asignar_el_primer_tecnico_despues_tambien_lo_deja_asignado(): void
    {
        $ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->customer->id,
            'subject' => 'Lento', 'status' => TicketWorkflow::RADICADO, 'priority' => 'medium', 'category' => 'technical',
        ]);

        $this->actingAs($this->recepcion())
            ->putJson("/api/support/{$ticket->id}", ['staff_id' => $this->tecnico->id])
            ->assertOk();

        $this->assertSame(TicketWorkflow::ASIGNADO, $ticket->fresh()->status);
    }

    #[Test]
    public function una_prioridad_distinta_de_media_exige_el_permiso(): void
    {
        $this->actingAs($this->recepcion())->postJson('/api/support', [
            'user_id'  => $this->customer->id,
            'subject'  => 'Caído',
            'priority' => 'urgent',
        ])->assertForbidden()->assertJsonPath('required_permission', Permissions::TICKET_SET_PRIORITY);

        $this->assertSame(0, SupportTicket::count());

        // Media explícita es lo de siempre: no pide nada nuevo.
        $this->actingAs($this->recepcion())->postJson('/api/support', [
            'user_id'  => $this->customer->id,
            'subject'  => 'Caído',
            'priority' => 'medium',
        ])->assertCreated();
    }

    #[Test]
    public function con_el_permiso_la_prioridad_se_guarda(): void
    {
        $id = $this->actingAs($this->recepcion([Permissions::TICKET_SET_PRIORITY]))->postJson('/api/support', [
            'user_id'  => $this->customer->id,
            'subject'  => 'Caído',
            'priority' => 'high',
        ])->assertCreated()->json('ticket.id');

        $this->assertSame('high', SupportTicket::find($id)->priority);
    }
}
