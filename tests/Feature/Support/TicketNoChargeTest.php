<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SupportTicketHistory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El ticket de mantenimiento que NO se le cobra al cliente.
 *
 * POR QUÉ HACÍA FALTA, SI EL CARGO YA ERA OPCIONAL
 *
 * El interruptor «Cargo Asociado» del alta venía apagado, y eso no impedía
 * nada: `POST /support/{id}/charge` sigue abierto el resto de la vida del
 * ticket. La visita que el técnico dio por regalada hoy se podía facturar un
 * mes después sin que nadie se enterara. Apagado no es lo mismo que prohibido,
 * y esta marca es lo segundo.
 *
 * Quitarla es posible —hay mantenimientos que sí se cobran— pero exige
 * `ticket_edit` y queda escrito en `support_ticket_history`, que nadie puede
 * editar. Es la diferencia entre una puerta cerrada y una puerta que no existe:
 * se puede abrir, pero no sin que conste quién la abrió.
 */
class TicketNoChargeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $cliente;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        // Se quema el `role_id == 1`, por el que `CheckPermission` deja pasar
        // siempre: sin esto los casos negativos serían falsos positivos.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);

        $this->cliente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $this->cliente->id, 'name' => 'Axel', 'last_name' => 'Cano', 'status' => true,
        ]);

        $this->staff = $this->usuarioCon([
            Permissions::TICKET_VIEW, Permissions::TICKET_CREATE,
            Permissions::TICKET_EDIT, Permissions::TICKET_VIEW_HISTORY,
        ]);
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(array $permisos, string $code = 'staff'): User
    {
        $rol = Role::create([
            'name' => 'Rol ' . uniqid(), 'code' => $code,
            'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $rol->id,
        ]);
    }

    private function ticket(array $extra = []): SupportTicket
    {
        return SupportTicket::create($extra + [
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->cliente->id,
            'subject'   => 'Router quemado por el rayo del domingo',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    /** @return array<string, mixed> */
    private function cargo(): array
    {
        return [
            'items' => [[
                'description' => 'Visita técnica',
                'quantity'    => 1,
                'unit_price'  => 40000,
            ]],
        ];
    }

    #[Test]
    public function el_ticket_puede_nacer_marcado_sin_cobro(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/support', [
                'user_id'          => $this->cliente->id,
                'subject'          => 'Cambio de router en garantía',
                'no_charge'        => true,
                'no_charge_reason' => 'Garantía del equipo',
            ])
            ->assertCreated();

        $ticket = SupportTicket::latest('id')->first();

        $this->assertTrue((bool) $ticket->no_charge);
        $this->assertSame('Garantía del equipo', $ticket->no_charge_reason);
    }

    #[Test]
    public function un_ticket_sin_cobro_no_admite_cargos(): void
    {
        $ticket = $this->ticket(['no_charge' => true]);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/charge", $this->cargo())
            ->assertStatus(422)
            ->assertJsonValidationErrors('no_charge');

        $this->assertDatabaseCount('invoices', 0);
    }

    #[Test]
    public function el_ticket_cobrable_sigue_generando_su_cargo(): void
    {
        // La guarda nueva no puede romper el camino de siempre.
        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/charge", $this->cargo())
            ->assertCreated();

        $this->assertDatabaseCount('invoices', 1);
    }

    #[Test]
    public function quitar_la_marca_vuelve_a_permitir_el_cargo_y_queda_en_el_historial(): void
    {
        $ticket = $this->ticket(['no_charge' => true, 'no_charge_reason' => 'Garantía']);

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", [
                'subject'   => $ticket->subject,
                'no_charge' => false,
            ])
            ->assertOk();

        $this->assertFalse((bool) $ticket->fresh()->no_charge);

        $evento = SupportTicketHistory::withoutGlobalScopes()
            ->where('support_ticket_id', $ticket->id)
            ->where('event_type', SupportTicketHistory::NO_CHARGE)
            ->first();

        $this->assertNotNull($evento, 'Perdonar o dejar de perdonar un cobro tiene que constar.');
        $this->assertSame('sin cobro al cliente', $evento->old_value);
        $this->assertSame($this->staff->id, $evento->actor_user_id);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/charge", $this->cargo())
            ->assertCreated();
    }

    #[Test]
    public function marcar_el_ticket_sin_cobro_exige_poder_editarlo(): void
    {
        $ticket = $this->ticket();

        // Ver el ticket y anotarlo no alcanza: es una decisión sobre el cobro.
        $soloVer = $this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_NOTE]);

        $this->actingAs($soloVer)
            ->putJson("/api/support/{$ticket->id}", [
                'subject'   => $ticket->subject,
                'no_charge' => true,
            ])
            ->assertForbidden()
            ->assertJsonPath('required_permission', Permissions::TICKET_EDIT);

        $this->assertFalse((bool) $ticket->fresh()->no_charge);
    }

    #[Test]
    public function reenviar_el_formulario_sin_tocar_la_marca_no_pide_permisos_extra(): void
    {
        // La pantalla de edición manda el objeto entero. Un ticket sin cobro
        // que se reenvía igual no puede contarse como un cambio.
        $ticket = $this->ticket(['no_charge' => true]);

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", [
                'subject'          => 'Router quemado — asunto corregido',
                'no_charge'        => true,
                'no_charge_reason' => null,
            ])
            ->assertOk();

        $this->assertTrue((bool) $ticket->fresh()->no_charge);

        $this->assertSame(
            0,
            SupportTicketHistory::withoutGlobalScopes()
                ->where('support_ticket_id', $ticket->id)
                ->where('event_type', SupportTicketHistory::NO_CHARGE)
                ->count(),
            'Reenviar el mismo valor no es un cambio y no debe ensuciar el historial.'
        );
    }
}
