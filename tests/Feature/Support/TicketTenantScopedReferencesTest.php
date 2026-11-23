<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\Sectorial;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P-121 · Cliente, técnico y sectorial de un ticket tienen que ser del ISP del
 * ticket.
 *
 * `store` y `update` validaban `user_id`, `staff_id` y `sectorial_id` con un
 * `exists` sin acotar: bastaba con mandar a mano el id de otro operador. Aquí se
 * prueba el cierre de esa puerta y, sobre todo, lo que NO debe romper: un ticket
 * viejo que ya trae un id ajeno sigue guardándose si nadie toca ese campo.
 */
class TicketTenantScopedReferencesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otro;
    private User $operador;
    private User $cliente;
    private User $tecnico;
    private Sectorial $sectorial;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('s3');

        $this->tenant = Tenant::factory()->create();
        $this->otro   = Tenant::factory()->create();

        // Se quema el role_id 1: `CheckPermission` lo deja pasar siempre.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);

        $this->operador = $this->personal($this->tenant, [
            Permissions::TICKET_VIEW, Permissions::TICKET_CREATE,
            Permissions::TICKET_EDIT, Permissions::TICKET_ASSIGN,
        ]);
        $this->cliente   = $this->clienteDe($this->tenant);
        $this->tecnico   = $this->personal($this->tenant, [Permissions::TICKET_INTERVENE]);
        $this->sectorial = $this->sectorialDe($this->tenant);
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

    /** @param array<int, string> $permisos */
    private function personal(Tenant $tenant, array $permisos): User
    {
        $rol = Role::create([
            'name' => 'Rol ' . uniqid(), 'code' => 'staff',
            'permissions' => $permisos, 'tenant_id' => $tenant->id,
        ]);

        return User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $rol->id]);
    }

    private function sectorialDe(Tenant $tenant): Sectorial
    {
        return Sectorial::create([
            'tenant_id' => $tenant->id, 'name' => 'AP ' . uniqid(), 'element_type' => 'sectorial',
        ]);
    }

    private function ticket(array $extra = []): SupportTicket
    {
        return SupportTicket::create($extra + [
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->cliente->id,
            'staff_id'  => $this->tecnico->id,
            'subject'   => 'Sin señal',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    /** @return array<string, mixed> */
    private function alta(array $extra = []): array
    {
        return $extra + [
            'subject'      => 'Sin internet',
            'user_id'      => $this->cliente->id,
            'staff_id'     => $this->tecnico->id,
            'sectorial_id' => $this->sectorial->id,
        ];
    }

    // ── Alta ─────────────────────────────────────────────────────────────

    #[Test]
    public function el_alta_con_referencias_propias_pasa(): void
    {
        $this->actingAs($this->operador)->postJson('/api/support', $this->alta())->assertSuccessful();

        $this->assertSame(1, SupportTicket::count());
    }

    #[Test]
    public function el_alta_rechaza_cliente_tecnico_o_sectorial_de_otro_isp(): void
    {
        $casos = [
            'user_id'      => $this->clienteDe($this->otro)->id,
            'staff_id'     => $this->personal($this->otro, [])->id,
            'sectorial_id' => $this->sectorialDe($this->otro)->id,
        ];

        foreach ($casos as $campo => $idAjeno) {
            $this->actingAs($this->operador)
                ->postJson('/api/support', $this->alta([$campo => $idAjeno]))
                ->assertStatus(422)
                ->assertJsonValidationErrors([$campo]);
        }

        $this->assertSame(0, SupportTicket::count());
    }

    // ── Edición ──────────────────────────────────────────────────────────

    #[Test]
    public function la_edicion_rechaza_asignar_tecnico_o_sectorial_de_otro_isp(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->operador)
            ->putJson("/api/support/{$ticket->id}", ['staff_id' => $this->personal($this->otro, [])->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['staff_id']);

        $this->actingAs($this->operador)
            ->putJson("/api/support/{$ticket->id}", ['sectorial_id' => $this->sectorialDe($this->otro)->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sectorial_id']);

        $ticket->refresh();
        $this->assertSame($this->tecnico->id, (int) $ticket->staff_id);
        $this->assertNull($ticket->sectorial_id);
    }

    #[Test]
    public function la_edicion_reasigna_dentro_del_mismo_isp(): void
    {
        $ticket = $this->ticket();
        $otroTecnico = $this->personal($this->tenant, [Permissions::TICKET_INTERVENE]);

        $this->actingAs($this->operador)
            ->putJson("/api/support/{$ticket->id}", ['staff_id' => $otroTecnico->id])
            ->assertOk();

        $this->assertSame($otroTecnico->id, (int) $ticket->refresh()->staff_id);
    }

    #[Test]
    public function un_ticket_viejo_con_tecnico_ajeno_se_sigue_guardando_si_no_se_toca(): void
    {
        $ajeno = $this->personal($this->otro, []);

        // Dato heredado de antes del arreglo: se escribe directo, como quedó.
        $ticket = $this->ticket();
        DB::table((new SupportTicket)->getTable())->where('id', $ticket->id)->update(['staff_id' => $ajeno->id]);

        // La pantalla de edición reenvía el formulario entero.
        $this->actingAs($this->operador)
            ->putJson("/api/support/{$ticket->id}", [
                'staff_id'    => $ajeno->id,
                'description' => 'Se reprogramó la visita.',
            ])
            ->assertOk();

        $ticket->refresh();
        $this->assertSame('Se reprogramó la visita.', $ticket->description);
        $this->assertSame($ajeno->id, (int) $ticket->staff_id);
    }

    #[Test]
    public function el_valor_heredado_no_abre_la_puerta_a_otro_id_ajeno(): void
    {
        $ajeno      = $this->personal($this->otro, []);
        $otroAjeno  = $this->personal($this->otro, []);

        $ticket = $this->ticket();
        DB::table((new SupportTicket)->getTable())->where('id', $ticket->id)->update(['staff_id' => $ajeno->id]);

        // El `OR id = actual` sólo deja pasar ESE id, no cualquiera del otro ISP.
        $this->actingAs($this->operador)
            ->putJson("/api/support/{$ticket->id}", ['staff_id' => $otroAjeno->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['staff_id']);

        $this->assertSame($ajeno->id, (int) $ticket->refresh()->staff_id);
    }
}
