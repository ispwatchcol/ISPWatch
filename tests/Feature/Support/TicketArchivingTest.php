<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\ApiClient;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketHistory;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * PR C · Archivar y restaurar expedientes.
 *
 * QUÉ SUSTITUYE
 *
 * El PR A retiró el borrado físico del ticket y dejó un hueco a propósito: no
 * había forma de sacar de la vista un ticket abierto por error. CNO aprobó el
 * 2026-09-11 sustituirlo por archivado reversible y auditado.
 *
 * LO QUE ESTAS PRUEBAS FIJAN
 *
 *   · Archivar NO destruye nada: notas, adjuntos, cargos e historial siguen.
 *   · El borrado físico SIGUE prohibido — el archivado no lo reabre.
 *   · Cuatro barreras, todas en el servidor: motivo, número tecleado, trabajo
 *     vivo y cargos sin anular.
 *   · Un archivado desaparece de la operación y de la API de socios, pero
 *     sigue siendo consultable entero por quien puede restaurarlo.
 */
class TicketArchivingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('s3');

        $this->tenant = Tenant::factory()->create();

        // `CheckPermission` deja pasar SIEMPRE a `role_id == 1`. Con
        // `RefreshDatabase` el primer rol creado se lleva ese id, así que se
        // quema uno: sin esto, cualquier rol de prueba pasaría por el bypass y
        // los casos negativos serían falsos positivos.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);

        $this->customer = $this->clienteDe($this->tenant);
        $this->admin    = $this->usuarioCon($this->permisosDeAdmin(), 'admin');
    }

    // ── Utilidades ───────────────────────────────────────────────────────

    private function clienteDe(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id' => $user->id, 'name' => 'Axel', 'last_name' => 'Cano', 'status' => true,
        ]);

        return $user;
    }

    /** @return array<int, string> */
    private function permisosDeAdmin(): array
    {
        return [
            Permissions::TICKET_VIEW, Permissions::TICKET_CREATE, Permissions::TICKET_EDIT,
            Permissions::TICKET_NOTE, Permissions::TICKET_ATTACH,
            Permissions::TICKET_VIEW_EVIDENCE, Permissions::TICKET_VIEW_HISTORY,
            Permissions::TICKET_TRANSITION, Permissions::TICKET_CLOSE,
            Permissions::TICKET_EXPORT,
            Permissions::TICKET_ARCHIVE, Permissions::TICKET_RESTORE,
        ];
    }

    /**
     * @param  array<int, string>  $permisos
     */
    private function usuarioCon(array $permisos, string $code = 'staff', ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;

        $rol = Role::create([
            'name' => 'Rol ' . $code . ' ' . uniqid(), 'code' => $code,
            'permissions' => $permisos, 'tenant_id' => $tenant->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $tenant->id, 'role_id' => $rol->id,
        ]);
    }

    private function ticket(array $extra = [], ?Tenant $tenant = null, ?User $cliente = null): SupportTicket
    {
        $tenant ??= $this->tenant;

        // `$extra` PRIMERO: la unión de arrays de PHP conserva la clave de la
        // izquierda, así que con los valores por defecto delante un
        // `['status' => 'closed']` se descartaría en silencio.
        return SupportTicket::create($extra + [
            'tenant_id' => $tenant->id,
            'user_id'   => ($cliente ?? $this->customer)->id,
            'subject'   => 'Sin señal desde anoche',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    /** Un ticket cerrado: archivarlo no exige las barreras de trabajo vivo. */
    private function ticketCerrado(): SupportTicket
    {
        return $this->ticket(['status' => 'closed']);
    }

    /** Cuerpo mínimo válido para archivar un ticket ya cerrado. */
    private function cuerpoValido(SupportTicket $ticket, array $extra = []): array
    {
        return $extra + [
            'reason'            => 'Duplicado del ticket anterior del mismo cliente.',
            'confirm_ticket_id' => (string) $ticket->id,
        ];
    }

    private function archivar(SupportTicket $ticket, array $extra = []): SupportTicket
    {
        $this->actingAs($this->admin)
            ->postJson("/api/support/{$ticket->id}/archive", $this->cuerpoValido($ticket, $extra))
            ->assertOk();

        return SupportTicket::withTrashed()->findOrFail($ticket->id);
    }

    /** @return array<int, SupportTicketHistory> */
    private function eventos(SupportTicket $ticket, ?string $tipo = null): array
    {
        return SupportTicketHistory::withoutGlobalScopes()
            ->where('support_ticket_id', $ticket->id)
            ->when($tipo, fn ($q) => $q->where('event_type', $tipo))
            ->get()->all();
    }

    private function factura(SupportTicket $ticket, string $estado): Invoice
    {
        return Invoice::create([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->customer->id,
            'ticket_id'    => $ticket->id,
            'number'       => 'INV-' . $ticket->id . '-' . $estado,
            'issue_date'   => '2026-09-01',
            'due_date'     => '2026-09-10',
            'period_start' => '2026-09-01',
            'period_end'   => '2026-09-30',
            'currency'     => 'COP',
            'subtotal'     => 50000, 'tax' => 0, 'total' => 50000, 'balance_due' => 50000,
            'status'       => $estado,
            'invoice_type' => 'service_charge',
            'carried_in'   => 0, 'carried_out' => 0,
        ]);
    }

    // ── Permisos ─────────────────────────────────────────────────────────

    #[Test]
    public function sin_ticket_archive_no_se_puede_archivar(): void
    {
        $ticket = $this->ticketCerrado();
        $sinPermiso = $this->usuarioCon(
            array_diff($this->permisosDeAdmin(), [Permissions::TICKET_ARCHIVE]),
            'staff',
        );

        $this->actingAs($sinPermiso)
            ->postJson("/api/support/{$ticket->id}/archive", $this->cuerpoValido($ticket))
            ->assertForbidden()
            ->assertJsonPath('required_permission', 'ticket_archive');

        $this->assertNull($ticket->fresh()->deleted_at);
    }

    #[Test]
    public function sin_ticket_restore_no_se_puede_restaurar(): void
    {
        $ticket = $this->archivar($this->ticketCerrado());
        $sinPermiso = $this->usuarioCon(
            array_diff($this->permisosDeAdmin(), [Permissions::TICKET_RESTORE]),
            'staff',
        );

        $this->actingAs($sinPermiso)
            ->postJson("/api/support/{$ticket->id}/restore", ['reason' => 'Se archivó por error de criterio.'])
            ->assertForbidden()
            ->assertJsonPath('required_permission', 'ticket_restore');

        $this->assertNotNull(SupportTicket::withTrashed()->find($ticket->id)->deleted_at);
    }

    #[Test]
    public function el_listado_de_archivados_exige_uno_de_los_dos_permisos(): void
    {
        $this->archivar($this->ticketCerrado());

        // Sin ninguno de los dos: ni lo ve.
        $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW], 'staff'))
            ->getJson('/api/support/archived')
            ->assertForbidden();

        // Con cualquiera de los dos: entra. Es la semántica OR deliberada.
        foreach ([Permissions::TICKET_ARCHIVE, Permissions::TICKET_RESTORE] as $permiso) {
            $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW, $permiso], 'staff'))
                ->getJson('/api/support/archived')
                ->assertOk()
                ->assertJsonCount(1, 'data');
        }
    }

    #[Test]
    public function la_migracion_concede_los_permisos_solo_a_los_roles_admin(): void
    {
        $roles = [
            ['Administrador', 'admin',      [Permissions::VIEW_SUPPORT], true],
            ['Staff',         'staff',      [Permissions::VIEW_SUPPORT], false],
            ['Tecnico',       'technician', [Permissions::VIEW_SUPPORT], false],
            ['Contabilidad',  'accounting', [Permissions::VIEW_BILLING], false],
        ];

        $creados = [];
        foreach ($roles as [$nombre, $code, $permisos, ]) {
            $creados[$nombre] = Role::create([
                'name' => $nombre, 'code' => $code,
                'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
            ]);
        }

        $comodin = Role::create([
            'name' => 'Dueño', 'code' => 'admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);

        $migracion = require database_path(
            'migrations/2026_09_13_000002_grant_ticket_archiving_to_admin_roles.php'
        );
        $migracion->up();

        foreach ($roles as [$nombre, , , $debeTener]) {
            $permisos = Role::withoutGlobalScopes()->find($creados[$nombre]->id)->permissions;

            foreach ([Permissions::TICKET_ARCHIVE, Permissions::TICKET_RESTORE] as $permiso) {
                $debeTener
                    ? $this->assertContains($permiso, $permisos, "{$nombre} debía recibir {$permiso}.")
                    : $this->assertNotContains($permiso, $permisos, "{$nombre} NO debía recibir {$permiso}.");
            }
        }

        // Un rol con comodín ya lo tiene todo; tocarlo sería ruido.
        $this->assertSame(['*'], Role::withoutGlobalScopes()->find($comodin->id)->permissions);
    }

    #[Test]
    public function la_migracion_es_idempotente_y_reversible(): void
    {
        $rol = Role::create([
            'name' => 'Administrador', 'code' => 'admin',
            'permissions' => [Permissions::VIEW_SUPPORT], 'tenant_id' => $this->tenant->id,
        ]);

        $migracion = require database_path(
            'migrations/2026_09_13_000002_grant_ticket_archiving_to_admin_roles.php'
        );

        $migracion->up();
        $primera = Role::withoutGlobalScopes()->find($rol->id)->permissions;

        $migracion->up();
        $this->assertSame($primera, Role::withoutGlobalScopes()->find($rol->id)->permissions);

        $migracion->down();
        $this->assertSame(
            [Permissions::VIEW_SUPPORT],
            Role::withoutGlobalScopes()->find($rol->id)->permissions,
        );
    }

    // ── Motivo obligatorio ───────────────────────────────────────────────

    #[Test]
    public function archivar_sin_motivo_da_422(): void
    {
        $ticket = $this->ticketCerrado();

        $this->actingAs($this->admin)
            ->postJson("/api/support/{$ticket->id}/archive", ['confirm_ticket_id' => (string) $ticket->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertNull($ticket->fresh()->deleted_at);
    }

    #[Test]
    public function un_motivo_demasiado_corto_o_demasiado_largo_da_422(): void
    {
        $ticket = $this->ticketCerrado();

        foreach (['corto', str_repeat('a', 501)] as $motivo) {
            $this->actingAs($this->admin)
                ->postJson("/api/support/{$ticket->id}/archive", [
                    'reason' => $motivo, 'confirm_ticket_id' => (string) $ticket->id,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('reason');
        }

        $this->assertNull($ticket->fresh()->deleted_at);
    }

    #[Test]
    public function restaurar_sin_motivo_da_422(): void
    {
        $ticket = $this->archivar($this->ticketCerrado());

        $this->actingAs($this->admin)
            ->postJson("/api/support/{$ticket->id}/restore", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertNotNull(SupportTicket::withTrashed()->find($ticket->id)->deleted_at);
    }

    // ── Doble confirmación ───────────────────────────────────────────────

    #[Test]
    public function archivar_exige_escribir_el_numero_del_ticket(): void
    {
        $ticket = $this->ticketCerrado();

        // Ausente.
        $this->actingAs($this->admin)
            ->postJson("/api/support/{$ticket->id}/archive", ['reason' => 'Motivo suficientemente largo.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm_ticket_id');

        // Presente pero equivocado: la barrera no es «escribir algo».
        $this->actingAs($this->admin)
            ->postJson("/api/support/{$ticket->id}/archive", [
                'reason' => 'Motivo suficientemente largo.',
                'confirm_ticket_id' => (string) ($ticket->id + 7),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ticket_confirmation_mismatch');

        $this->assertNull($ticket->fresh()->deleted_at);
    }

    // ── Trabajo vivo ─────────────────────────────────────────────────────

    #[Test]
    public function un_ticket_abierto_no_se_archiva_sin_razon_reforzada(): void
    {
        foreach ([SupportTicket::STATUS_OPEN, SupportTicket::STATUS_IN_PROGRESS] as $estado) {
            $ticket = $this->ticket(['status' => $estado]);

            $this->actingAs($this->admin)
                ->postJson("/api/support/{$ticket->id}/archive", $this->cuerpoValido($ticket))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['reason_code', 'acknowledge_active']);

            $this->assertNull($ticket->fresh()->deleted_at);
        }
    }

    #[Test]
    public function un_ticket_abierto_no_se_archiva_por_un_motivo_cualquiera(): void
    {
        $ticket = $this->ticket();

        // «Ya no aplica» describe un ticket que hay que CERRAR, no esconder.
        $this->actingAs($this->admin)
            ->postJson("/api/support/{$ticket->id}/archive", $this->cuerpoValido($ticket, [
                'reason_code' => 'ya_no_aplica', 'acknowledge_active' => true,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason_code');

        $this->assertNull($ticket->fresh()->deleted_at);
    }

    #[Test]
    public function un_ticket_abierto_se_archiva_con_duplicado_y_confirmacion(): void
    {
        $ticket = $this->ticket();

        $archivado = $this->archivar($ticket, [
            'reason_code' => 'duplicate', 'acknowledge_active' => true,
        ]);

        $this->assertNotNull($archivado->deleted_at);

        $evento = $this->eventos($ticket, SupportTicketHistory::ARCHIVED)[0];
        $this->assertSame('duplicate', $evento->metadata['reason_code']);
        $this->assertTrue($evento->metadata['era_activo']);
    }

    #[Test]
    public function un_ticket_cerrado_se_archiva_sin_barreras_extra(): void
    {
        $archivado = $this->archivar($this->ticketCerrado());

        $this->assertNotNull($archivado->deleted_at);
        $this->assertArrayNotHasKey(
            'reason_code',
            $this->eventos($archivado, SupportTicketHistory::ARCHIVED)[0]->metadata,
        );
    }

    // ── Cargos ───────────────────────────────────────────────────────────

    #[Test]
    public function un_ticket_con_factura_sin_anular_no_se_archiva(): void
    {
        foreach (['draft', 'issued', 'paid', 'partial', 'overdue'] as $estado) {
            $ticket = $this->ticketCerrado();
            $this->factura($ticket, $estado);

            $this->actingAs($this->admin)
                ->postJson("/api/support/{$ticket->id}/archive", $this->cuerpoValido($ticket))
                ->assertStatus(422)
                ->assertJsonPath('error', 'ticket_has_active_charge');

            $this->assertNull(
                $ticket->fresh()->deleted_at,
                "Un ticket con factura en `{$estado}` no debe poder archivarse.",
            );
        }
    }

    #[Test]
    public function un_ticket_con_factura_anulada_si_se_archiva(): void
    {
        foreach (['void', 'cancelled'] as $estado) {
            $ticket = $this->ticketCerrado();
            $this->factura($ticket, $estado);

            $this->assertNotNull($this->archivar($ticket)->deleted_at);
        }
    }

    // ── Eventos de auditoría ─────────────────────────────────────────────

    #[Test]
    public function archivar_y_restaurar_dejan_evento_con_motivo_y_actor(): void
    {
        $ticket = $this->ticketCerrado();

        $this->archivar($ticket);

        $archivado = $this->eventos($ticket, SupportTicketHistory::ARCHIVED)[0];
        $this->assertSame('Duplicado del ticket anterior del mismo cliente.', $archivado->metadata['reason']);
        $this->assertSame('closed', $archivado->metadata['status']);
        $this->assertSame($this->admin->id, (int) $archivado->actor_user_id);

        $this->actingAs($this->admin)
            ->postJson("/api/support/{$ticket->id}/restore", ['reason' => 'Se archivó por error de criterio.'])
            ->assertOk();

        $restaurado = $this->eventos($ticket, SupportTicketHistory::RESTORED)[0];
        $this->assertSame('Se archivó por error de criterio.', $restaurado->metadata['reason']);
        // El motivo del archivado anterior se conserva en el evento de vuelta:
        // la fila del ticket lo pierde, el historial no.
        $this->assertSame(
            'Duplicado del ticket anterior del mismo cliente.',
            $restaurado->metadata['archived_reason'],
        );
        $this->assertSame($this->admin->id, (int) $restaurado->actor_user_id);
    }

    #[Test]
    public function el_evento_de_archivado_sigue_siendo_inalterable(): void
    {
        $ticket = $this->archivar($this->ticketCerrado());
        $evento = $this->eventos($ticket, SupportTicketHistory::ARCHIVED)[0];

        $this->expectException(RuntimeException::class);
        $evento->update(['new_value' => 'otra cosa']);
    }

    // ── El archivado desaparece de la operación ──────────────────────────

    #[Test]
    public function un_archivado_no_sale_en_el_listado_ni_en_las_estadisticas(): void
    {
        $visible   = $this->ticketCerrado();
        $archivado = $this->archivar($this->ticketCerrado());

        $listado = $this->actingAs($this->admin)->getJson('/api/support')->assertOk()->json();
        $this->assertSame([$visible->id], array_column($listado, 'id'));

        $stats = $this->actingAs($this->admin)->getJson('/api/support/statistics')->assertOk()->json();
        $this->assertSame(1, $stats['total_tickets']);
        $this->assertNotContains($archivado->id, array_column($stats['recent_tickets'], 'id'));
    }

    #[Test]
    public function un_archivado_no_sale_en_la_api_de_socios(): void
    {
        $visible   = $this->ticketCerrado();
        $archivado = $this->archivar($this->ticketCerrado());

        $cliente = ApiClient::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Integrador', 'is_active' => true,
        ]);
        $token = $cliente->createToken('contrato', ['read:support']);
        // La llave sólo responde desde una IP declarada, y hay que soltar el
        // guard: la sesión del panel usada más arriba lo deja resuelto y la
        // petición del socio se autenticaría con ella.
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();
        $this->app['auth']->forgetGuards();

        $ids = array_column(
            $this->getJson('/api/v1/partner/tickets', [
                'Authorization' => 'Bearer ' . $token->plainTextToken,
            ])->assertOk()->json('data'),
            'id',
        );

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($archivado->id, $ids, 'Un expediente archivado no debe llegar al integrador.');
    }

    #[Test]
    public function un_archivado_no_admite_edicion_ni_notas(): void
    {
        $ticket = $this->archivar($this->ticketCerrado());

        // Fuera de la operación: el 404 sale del scope de SoftDeletes, no de
        // una comprobación que alguien pueda olvidarse de escribir.
        $this->actingAs($this->admin)
            ->putJson("/api/support/{$ticket->id}", ['subject' => 'Otro asunto'])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->postJson("/api/support/{$ticket->id}/message", ['message' => 'Una nota sobre el caso.'])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'open'])
            ->assertNotFound();
    }

    // ── …pero sigue entero y consultable para quien puede restaurarlo ────

    #[Test]
    public function el_expediente_sobrevive_intacto_al_archivado(): void
    {
        $ticket = $this->ticketCerrado();

        $this->actingAs($this->admin)->postJson("/api/support/{$ticket->id}/message", [
            'message' => 'Se revisó la ONU en sitio.', 'is_internal' => true,
        ])->assertCreated();

        $this->actingAs($this->admin)->post("/api/support/{$ticket->id}", [
            '_method' => 'PUT',
            'attachments' => [UploadedFile::fake()->image('evidencia.jpg', 40, 30)],
        ])->assertOk();

        $nota    = SupportTicketMessage::where('ticket_id', $ticket->id)->firstOrFail();
        $adjunto = SupportTicketAttachment::where('ticket_id', $ticket->id)->firstOrFail();
        $factura = $this->factura($ticket, 'void');
        $eventos = count($this->eventos($ticket));

        $this->archivar($ticket);

        // Nada se borró. Ni la fila, ni el archivo del bucket, ni el historial.
        $this->assertDatabaseHas('support_ticket', ['id' => $ticket->id]);
        $this->assertDatabaseHas('support_ticket_message', ['id' => $nota->id]);
        $this->assertDatabaseHas('support_ticket_attachment', ['id' => $adjunto->id]);
        $this->assertDatabaseHas('invoices', ['id' => $factura->id, 'ticket_id' => $ticket->id]);
        Storage::disk('s3')->assertExists($adjunto->file_path);

        // El historial no perdió eventos: ganó uno.
        $this->assertCount($eventos + 1, $this->eventos($ticket));
    }

    #[Test]
    public function quien_puede_restaurar_sigue_viendo_el_expediente_entero(): void
    {
        $ticket = $this->ticketCerrado();

        $this->actingAs($this->admin)->post("/api/support/{$ticket->id}", [
            '_method' => 'PUT',
            'attachments' => [UploadedFile::fake()->image('evidencia.jpg', 40, 30)],
        ])->assertOk();

        $adjunto = SupportTicketAttachment::where('ticket_id', $ticket->id)->firstOrFail();
        $this->archivar($ticket);

        // Detalle, historial, cargos y evidencia: los cuatro puntos de lectura
        // que el diseño obligó a auditar uno por uno.
        $detalle = $this->actingAs($this->admin)->getJson("/api/support/{$ticket->id}")->assertOk();
        $detalle->assertJsonPath('is_archived', true);

        $this->actingAs($this->admin)->getJson("/api/support/{$ticket->id}/history")
            ->assertOk()->assertJsonPath('data.0.event_type', SupportTicketHistory::ARCHIVED);

        $this->actingAs($this->admin)->getJson("/api/support/{$ticket->id}/charges")->assertOk();

        $this->actingAs($this->admin)
            ->get("/api/support/{$ticket->id}/attachments/{$adjunto->id}")
            ->assertOk();
    }

    #[Test]
    public function quien_no_puede_restaurar_recibe_404_y_no_403(): void
    {
        $ticket = $this->archivar($this->ticketCerrado());

        $operador = $this->usuarioCon(
            array_diff($this->permisosDeAdmin(), [Permissions::TICKET_ARCHIVE, Permissions::TICKET_RESTORE]),
            'staff',
        );

        // 404 y no 403: quien no puede ver archivados tampoco debe poder
        // deducir que ese ticket existe. Es la misma elección que el
        // aislamiento por tenant.
        $this->actingAs($operador)->getJson("/api/support/{$ticket->id}")->assertNotFound();
        $this->actingAs($operador)->getJson("/api/support/{$ticket->id}/history")->assertNotFound();
    }

    // ── Restauración ─────────────────────────────────────────────────────

    #[Test]
    public function restaurar_devuelve_el_ticket_a_la_operacion_con_su_estado(): void
    {
        $ticket = $this->ticket(['status' => 'resolved']);
        $this->archivar($ticket);

        $this->actingAs($this->admin)
            ->postJson("/api/support/{$ticket->id}/restore", ['reason' => 'Se archivó por error de criterio.'])
            ->assertOk();

        $vuelto = $ticket->fresh();
        $this->assertNull($vuelto->deleted_at);
        $this->assertSame('resolved', $vuelto->status);

        // La fila deja de afirmar que está archivada; el historial lo conserva.
        $this->assertNull($vuelto->archived_by);
        $this->assertNull($vuelto->archived_reason);

        $ids = array_column($this->actingAs($this->admin)->getJson('/api/support')->json(), 'id');
        $this->assertContains($ticket->id, $ids);
    }

    #[Test]
    public function no_se_archiva_dos_veces_ni_se_restaura_lo_que_no_esta_archivado(): void
    {
        $ticket = $this->ticketCerrado();

        // Restaurar un ticket vivo: no hay nada que restaurar.
        $this->actingAs($this->admin)
            ->postJson("/api/support/{$ticket->id}/restore", ['reason' => 'Motivo suficientemente largo.'])
            ->assertNotFound();

        $this->archivar($ticket);

        // Archivar lo ya archivado: tampoco, y sin registrar un evento de más.
        $this->actingAs($this->admin)
            ->postJson("/api/support/{$ticket->id}/archive", $this->cuerpoValido($ticket))
            ->assertNotFound();

        $this->assertCount(1, $this->eventos($ticket, SupportTicketHistory::ARCHIVED));
    }

    // ── Aislamiento por tenant ───────────────────────────────────────────

    #[Test]
    public function un_isp_no_ve_ni_toca_los_archivados_de_otro(): void
    {
        $otro       = Tenant::factory()->create();
        $suCliente  = $this->clienteDe($otro);
        $suAdmin    = $this->usuarioCon($this->permisosDeAdmin(), 'admin', $otro);
        $suTicket   = $this->ticket(['status' => 'closed'], $otro, $suCliente);

        $this->actingAs($suAdmin)
            ->postJson("/api/support/{$suTicket->id}/archive", $this->cuerpoValido($suTicket))
            ->assertOk();

        $propio = $this->archivar($this->ticketCerrado());

        // El listado de cada uno sólo trae lo suyo.
        $mios = array_column(
            $this->actingAs($this->admin)->getJson('/api/support/archived')->assertOk()->json('data'),
            'id',
        );
        $this->assertSame([$propio->id], $mios);

        // Y el ticket ajeno no se alcanza ni por id directo.
        $this->actingAs($this->admin)->getJson("/api/support/{$suTicket->id}")->assertNotFound();
        $this->actingAs($this->admin)
            ->postJson("/api/support/{$suTicket->id}/restore", ['reason' => 'Intento de cruce de tenants.'])
            ->assertNotFound();
    }

    // ── Listado de archivados ────────────────────────────────────────────

    #[Test]
    public function el_listado_de_archivados_pagina_y_filtra(): void
    {
        $cerrado  = $this->archivar($this->ticketCerrado());
        $resuelto = $this->archivar($this->ticket(['status' => 'resolved', 'subject' => 'Intermitencias en la noche']));

        $respuesta = $this->actingAs($this->admin)->getJson('/api/support/archived')->assertOk();
        $respuesta->assertJsonPath('total', 2);
        // Paginado: la forma de la respuesta la consume la vista de archivados.
        $respuesta->assertJsonStructure(['data', 'current_page', 'last_page', 'total']);

        // El motivo y el autor del archivado viajan en el listado: son lo que
        // hace auditable la decisión sin abrir cada expediente.
        $this->assertNotNull($respuesta->json('data.0.archived_reason'));
        $this->assertSame($this->admin->id, (int) $respuesta->json('data.0.archived_by'));

        $porEstado = $this->actingAs($this->admin)
            ->getJson('/api/support/archived?status=resolved')->assertOk();
        $this->assertSame([$resuelto->id], array_column($porEstado->json('data'), 'id'));

        $porTexto = $this->actingAs($this->admin)
            ->getJson('/api/support/archived?search=Intermitencias')->assertOk();
        $this->assertSame([$resuelto->id], array_column($porTexto->json('data'), 'id'));

        $this->assertNotContains($cerrado->id, array_column($porTexto->json('data'), 'id'));
    }

    // ── El vocabulario del contrato ──────────────────────────────────────

    #[Test]
    public function la_api_habla_de_archivado_y_no_de_eliminado(): void
    {
        $ticket = $this->archivar($this->ticketCerrado());

        $detalle = $this->actingAs($this->admin)->getJson("/api/support/{$ticket->id}")->assertOk()->json();

        // `deleted_at` es un detalle de implementación de Eloquent y no
        // pertenece al contrato: el requerimiento trata el ticket como un
        // expediente que se archiva, no como un registro que se elimina.
        $this->assertArrayNotHasKey('deleted_at', $detalle);
        $this->assertArrayHasKey('archived_at', $detalle);
        $this->assertTrue($detalle['is_archived']);
    }

    // ── El borrado físico NO se reabre ───────────────────────────────────

    #[Test]
    public function el_archivado_no_reabre_el_borrado_fisico(): void
    {
        $ticket = $this->ticketCerrado();

        // La ruta sigue respondiendo 403.
        $this->actingAs($this->admin)
            ->deleteJson("/api/support/{$ticket->id}")
            ->assertForbidden()
            ->assertJsonPath('error', 'ticket_deletion_disabled');

        // Y el modelo sigue lanzando ante `forceDelete()`, archivado o no.
        $archivado = $this->archivar($ticket);

        $this->expectException(RuntimeException::class);
        $archivado->forceDelete();
    }

    #[Test]
    public function la_clave_foranea_del_historial_sigue_en_restrict(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('La regla de la clave foránea sólo se puede leer en PostgreSQL.');
        }

        $reglas = DB::select(
            "select conname, confdeltype from pg_constraint
             where conrelid = 'support_ticket_history'::regclass
               and contype = 'f'
               and pg_get_constraintdef(oid) like '%support_ticket(id)%'"
        );

        $this->assertNotEmpty($reglas, 'Debe existir la foránea del historial contra el ticket.');

        foreach ($reglas as $regla) {
            // 'r' = RESTRICT. El PR A la puso y el PR C no la revierte: con
            // archivado no hay borrado físico, pero la barrera del motor sigue
            // siendo la única que cubre un `where(...)->delete()`.
            $this->assertSame('r', $regla->confdeltype, "La foránea {$regla->conname} debe ser RESTRICT.");
        }
    }
}
