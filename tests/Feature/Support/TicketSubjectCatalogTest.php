<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SupportTicketHistory;
use App\Models\Tenant;
use App\Models\TicketSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Catálogo de asuntos frecuentes del ticket.
 *
 * El asunto era texto libre: «SIN INTERNET», «sin internet» y «no tiene
 * internet» eran tres asuntos, y filtrar el listado por asunto era imposible.
 *
 * LO QUE SE FIJA AQUÍ
 *
 *  1. Cada ISP administra su lista (`ticket_manage_catalogs`); los demás la
 *     leen. La lista de un ISP no se ve ni se toca desde otro.
 *  2. Elegir un asunto del catálogo copia su nombre al texto del ticket; lo
 *     que mande el navegador en `subject` se ignora.
 *  3. «Otro…» sigue existiendo: `subject_id` null + texto libre.
 *  4. El listado filtra por asunto, y `other` agrupa los de texto libre.
 *  5. Un asunto en uso no se borra (409): se desactiva.
 *  6. Cambiar el asunto queda en el historial.
 */
class TicketSubjectCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $cliente;
    private User $admin;
    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);

        $this->cliente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $this->cliente->id, 'name' => 'Axel', 'last_name' => 'Cano', 'status' => true,
        ]);

        $base = [
            Permissions::TICKET_VIEW, Permissions::TICKET_CREATE,
            Permissions::TICKET_EDIT, Permissions::TICKET_VIEW_HISTORY,
        ];

        $this->admin    = $this->usuarioCon([...$base, Permissions::TICKET_MANAGE_CATALOGS], 'admin');
        $this->operador = $this->usuarioCon($base);
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(array $permisos, string $code = 'staff', ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;

        $rol = Role::create([
            'name' => 'Rol ' . uniqid(), 'code' => $code,
            'permissions' => $permisos, 'tenant_id' => $tenant->id,
        ]);

        return User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $rol->id]);
    }

    private function asunto(string $nombre, array $extra = []): TicketSubject
    {
        return TicketSubject::create($extra + [
            'tenant_id' => $this->tenant->id,
            'name'      => $nombre,
        ]);
    }

    // ── Administración del catálogo ──────────────────────────────────────

    #[Test]
    public function quien_administra_catalogos_crea_asuntos_y_los_demas_solo_los_leen(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/support/subjects', ['name' => '  Sin   internet '])
            ->assertCreated()
            ->assertJsonPath('name', 'Sin internet');

        $this->actingAs($this->operador)
            ->postJson('/api/support/subjects', ['name' => 'Internet lento'])
            ->assertForbidden();

        $this->actingAs($this->operador)
            ->getJson('/api/support/subjects')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Sin internet');
    }

    #[Test]
    public function no_se_repite_un_asunto_aunque_cambien_las_mayusculas(): void
    {
        $this->asunto('Sin internet');

        $this->actingAs($this->admin)
            ->postJson('/api/support/subjects', ['name' => 'SIN INTERNET'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    #[Test]
    public function la_lista_de_un_isp_no_se_ve_ni_se_toca_desde_otro(): void
    {
        $ajeno = $this->asunto('Caída de nodo');

        $otroTenant = Tenant::factory()->create();
        $intruso = $this->usuarioCon([
            Permissions::TICKET_VIEW, Permissions::TICKET_MANAGE_CATALOGS,
        ], 'admin', $otroTenant);

        $this->actingAs($intruso)->getJson('/api/support/subjects')->assertOk()->assertJsonCount(0);
        $this->actingAs($intruso)->putJson("/api/support/subjects/{$ajeno->id}", ['name' => 'X'])->assertNotFound();
        $this->actingAs($intruso)->deleteJson("/api/support/subjects/{$ajeno->id}")->assertNotFound();

        $this->assertSame('Caída de nodo', $ajeno->fresh()->name);
    }

    #[Test]
    public function un_asunto_en_uso_no_se_borra_se_desactiva(): void
    {
        $asunto = $this->asunto('Sin internet');

        SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->cliente->id,
            'subject' => 'Sin internet', 'subject_id' => $asunto->id,
            'status' => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/support/subjects/{$asunto->id}")
            ->assertStatus(409)
            ->assertJsonPath('error', 'subject_in_use');

        $this->actingAs($this->admin)
            ->putJson("/api/support/subjects/{$asunto->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        // Ya no se ofrece al radicar…
        $this->actingAs($this->operador)
            ->getJson('/api/catalogs/ticket')
            ->assertOk()
            ->assertJsonCount(0, 'subjects');

        // …y un asunto sin uso sí se borra.
        $libre = $this->asunto('Traslado');
        $this->actingAs($this->admin)->deleteJson("/api/support/subjects/{$libre->id}")->assertOk();
        $this->assertNull(TicketSubject::find($libre->id));
    }

    // ── Radicar y editar con el catálogo ─────────────────────────────────

    #[Test]
    public function elegir_un_asunto_copia_su_nombre_al_ticket(): void
    {
        $asunto = $this->asunto('Sin internet');

        $this->actingAs($this->operador)
            ->postJson('/api/support', [
                'user_id'    => $this->cliente->id,
                'subject_id' => $asunto->id,
                // Lo que mande el navegador no manda: manda el catálogo.
                'subject'    => 'cualquier cosa',
            ])
            ->assertCreated();

        $ticket = SupportTicket::latest('id')->first();
        $this->assertSame($asunto->id, (int) $ticket->subject_id);
        $this->assertSame('Sin internet', $ticket->subject);
    }

    #[Test]
    public function otro_sigue_aceptando_texto_libre(): void
    {
        $this->actingAs($this->operador)
            ->postJson('/api/support', [
                'user_id' => $this->cliente->id,
                'subject' => 'El cliente pide factura en inglés',
            ])
            ->assertCreated();

        $ticket = SupportTicket::latest('id')->first();
        $this->assertNull($ticket->subject_id);
        $this->assertSame('El cliente pide factura en inglés', $ticket->subject);

        // Sin catálogo y sin texto, no.
        $this->actingAs($this->operador)
            ->postJson('/api/support', ['user_id' => $this->cliente->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject');
    }

    #[Test]
    public function no_se_puede_radicar_con_un_asunto_de_otro_isp_ni_con_uno_desactivado(): void
    {
        $otroTenant = Tenant::factory()->create();
        $ajeno = TicketSubject::create(['tenant_id' => $otroTenant->id, 'name' => 'Ajeno']);
        $viejo = $this->asunto('Viejo', ['is_active' => false]);

        $this->actingAs($this->operador)
            ->postJson('/api/support', ['user_id' => $this->cliente->id, 'subject_id' => $ajeno->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject_id');

        $this->actingAs($this->operador)
            ->postJson('/api/support', ['user_id' => $this->cliente->id, 'subject_id' => $viejo->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject_id');
    }

    #[Test]
    public function reclasificar_al_cerrar_cambia_el_asunto_y_queda_en_el_historial(): void
    {
        $sinInternet = $this->asunto('Sin internet');
        $fibra       = $this->asunto('Daño en fibra');

        $ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->cliente->id,
            'subject' => 'Sin internet', 'subject_id' => $sinInternet->id,
            'status' => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);

        $this->actingAs($this->operador)
            ->putJson("/api/support/{$ticket->id}", ['subject_id' => $fibra->id])
            ->assertOk();

        $ticket->refresh();
        $this->assertSame($fibra->id, (int) $ticket->subject_id);
        $this->assertSame('Daño en fibra', $ticket->subject);

        $evento = SupportTicketHistory::where('support_ticket_id', $ticket->id)
            ->where('event_type', SupportTicketHistory::SUBJECT)
            ->sole();

        $this->assertSame('Sin internet', $evento->old_value);
        $this->assertSame('Daño en fibra', $evento->new_value);
    }

    #[Test]
    public function un_ticket_con_asunto_desactivado_se_puede_volver_a_guardar(): void
    {
        $viejo = $this->asunto('Viejo', ['is_active' => false]);

        $ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->cliente->id,
            'subject' => 'Viejo', 'subject_id' => $viejo->id,
            'status' => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);

        // La pantalla de edición reenvía el formulario entero.
        $this->actingAs($this->operador)
            ->putJson("/api/support/{$ticket->id}", [
                'subject_id'  => $viejo->id,
                'subject'     => 'Viejo',
                'description' => 'Ahora con descripción',
            ])
            ->assertOk();

        $this->assertSame($viejo->id, (int) $ticket->fresh()->subject_id);
    }

    #[Test]
    public function cambiar_solo_el_texto_lo_saca_del_catalogo(): void
    {
        $asunto = $this->asunto('Sin internet');

        $ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->cliente->id,
            'subject' => 'Sin internet', 'subject_id' => $asunto->id,
            'status' => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);

        // Un cliente que sólo conoce el texto (un integrador, la pantalla vieja).
        $this->actingAs($this->operador)
            ->putJson("/api/support/{$ticket->id}", ['subject' => 'Cliente reporta cortes intermitentes'])
            ->assertOk();

        $ticket->refresh();
        $this->assertNull($ticket->subject_id, 'Con otro texto ya no es «Sin internet».');
        $this->assertSame('Cliente reporta cortes intermitentes', $ticket->subject);
    }

    #[Test]
    public function quien_no_puede_editar_no_reclasifica(): void
    {
        $sinInternet = $this->asunto('Sin internet');
        $fibra       = $this->asunto('Daño en fibra');

        $ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->cliente->id,
            'subject' => 'Sin internet', 'subject_id' => $sinInternet->id,
            'status' => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);

        $soloVer = $this->usuarioCon([Permissions::TICKET_VIEW]);

        $this->actingAs($soloVer)
            ->putJson("/api/support/{$ticket->id}", ['subject_id' => $fibra->id])
            ->assertForbidden()
            ->assertJsonPath('required_permission', Permissions::TICKET_EDIT);
    }

    // ── Filtro del listado ───────────────────────────────────────────────

    #[Test]
    public function el_listado_filtra_por_asunto_y_other_agrupa_el_texto_libre(): void
    {
        $sinInternet = $this->asunto('Sin internet');
        $lento       = $this->asunto('Internet lento');

        $crear = fn (string $texto, ?int $id) => SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->cliente->id,
            'subject' => $texto, 'subject_id' => $id,
            'status' => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);

        $crear('Sin internet', $sinInternet->id);
        $crear('Sin internet', $sinInternet->id);
        $crear('Internet lento', $lento->id);
        $crear('SIN INTERNET (ticket viejo)', null);

        $this->actingAs($this->operador)
            ->getJson("/api/support?subject_id={$sinInternet->id}")
            ->assertOk()
            ->assertJsonCount(2);

        $this->actingAs($this->operador)
            ->getJson('/api/support?subject_id=other')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.subject', 'SIN INTERNET (ticket viejo)');

        $this->actingAs($this->operador)
            ->getJson('/api/support?subject_id=all')
            ->assertOk()
            ->assertJsonCount(4);
    }

    #[Test]
    public function los_catalogos_del_ticket_traen_los_asuntos_vigentes_ordenados(): void
    {
        $this->asunto('Traslado', ['sort_order' => 20]);
        $this->asunto('Sin internet', ['sort_order' => 10]);
        $this->asunto('Retirado', ['sort_order' => 5, 'is_active' => false]);

        $this->actingAs($this->operador)
            ->getJson('/api/catalogs/ticket')
            ->assertOk()
            ->assertJsonPath('subjects.0.name', 'Sin internet')
            ->assertJsonPath('subjects.1.name', 'Traslado')
            ->assertJsonCount(2, 'subjects');
    }

    #[Test]
    public function la_migracion_reparte_el_permiso_solo_a_los_administradores(): void
    {
        $admin = Role::create([
            'name' => 'Administrador', 'code' => 'admin',
            'permissions' => [Permissions::TICKET_VIEW], 'tenant_id' => $this->tenant->id,
        ]);
        $staff = Role::create([
            'name' => 'Staff', 'code' => 'staff',
            'permissions' => [Permissions::TICKET_VIEW], 'tenant_id' => $this->tenant->id,
        ]);

        $migracion = require database_path('migrations/2026_10_11_000002_grant_ticket_manage_catalogs_to_admin_roles.php');
        $migracion->up();
        $migracion->up(); // idempotente

        $this->assertSame(
            [Permissions::TICKET_VIEW, Permissions::TICKET_MANAGE_CATALOGS],
            Role::withoutGlobalScopes()->find($admin->id)->permissions
        );
        $this->assertSame([Permissions::TICKET_VIEW], Role::withoutGlobalScopes()->find($staff->id)->permissions);

        $migracion->down();
        $this->assertSame([Permissions::TICKET_VIEW], Role::withoutGlobalScopes()->find($admin->id)->permissions);
    }
}
