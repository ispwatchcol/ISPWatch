<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR B · Capacidades separadas de la operación de tickets.
 *
 * QUÉ SE CIERRA
 *
 * `view_support` era un permiso-paraguas: quien lo tenía podía listar, crear,
 * editar, asignar, cambiar prioridad y categoría, diagnosticar, adjuntar, ver
 * evidencia y leer el historial. Y siete rutas más —notas, transiciones,
 * cargos, estadísticas— no tenían NINGÚN `permission:`: sólo `staff_profile`,
 * que comprueba el código de rol, no una capacidad.
 *
 * LO QUE ESTE PR NO HACE
 *
 * No configura los roles de la sección 18 (Recepción/N1, N2, Técnico de campo,
 * Supervisor, Auditor). Esa matriz está pendiente de confirmación del cliente
 * (**D-09**). Aquí se entrega la herramienta y una transición que no cambia lo
 * que nadie podía hacer ayer.
 */
class TicketGranularPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('s3');

        $this->tenant = Tenant::factory()->create();

        // `CheckPermission` y `CheckStaffProfile` dejan pasar SIEMPRE a
        // `role_id == 1`. Con `RefreshDatabase` el primer rol creado se lleva
        // ese id, así que se quema uno: si no, cualquier rol de prueba pasaría
        // por el bypass y estos tests serían falsos positivos.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);

        $this->customer = $this->clienteDe($this->tenant);
    }

    private function clienteDe(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id' => $user->id, 'name' => 'Axel', 'last_name' => 'Cano', 'status' => true,
        ]);

        return $user;
    }

    /**
     * Usuario con exactamente los permisos indicados.
     *
     * `code` importa: `staff_profile` deja pasar sólo a `admin` y `staff`, y
     * varias rutas lo exigen además del permiso.
     *
     * @param  array<int, string>  $permisos
     */
    private function usuarioCon(array $permisos, string $code = 'staff'): User
    {
        $rol = Role::create([
            'name' => 'Rol ' . $code . ' ' . count($permisos), 'code' => $code,
            'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $rol->id,
        ]);
    }

    /** Todas las capacidades de ticket, para probar el caso negativo quitando una. */
    private function todasMenos(string ...$excluidos): array
    {
        $todas = [
            Permissions::TICKET_VIEW, Permissions::TICKET_CREATE, Permissions::TICKET_EDIT,
            Permissions::TICKET_ASSIGN, Permissions::TICKET_SET_PRIORITY,
            Permissions::TICKET_SET_CATEGORY, Permissions::TICKET_DIAGNOSE,
            Permissions::TICKET_CONFIRM_CAUSE, Permissions::TICKET_NOTE,
            Permissions::TICKET_ATTACH, Permissions::TICKET_VIEW_EVIDENCE,
            Permissions::TICKET_VIEW_HISTORY, Permissions::TICKET_TRANSITION,
            Permissions::TICKET_CLOSE, Permissions::TICKET_EXPORT,
        ];

        return array_values(array_diff($todas, $excluidos));
    }

    private function ticket(array $extra = []): SupportTicket
    {
        return SupportTicket::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->customer->id,
            'subject'   => 'Sin señal desde anoche',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ] + $extra);
    }

    // ── Lectura ──────────────────────────────────────────────────────────

    #[Test]
    public function ticket_view_abre_el_listado_y_el_detalle(): void
    {
        $usuario = $this->usuarioCon([Permissions::TICKET_VIEW]);
        $ticket = $this->ticket();

        $this->actingAs($usuario)->getJson('/api/support')->assertOk();
        $this->actingAs($usuario)->getJson("/api/support/{$ticket->id}")->assertOk();
        $this->actingAs($usuario)->getJson('/api/catalogs/ticket')->assertOk();
    }

    #[Test]
    public function sin_ticket_view_no_se_ve_nada(): void
    {
        // `view_support` ya no sirve para tickets: gobierna instalaciones,
        // sectoriales e inventario, pero no esto.
        $usuario = $this->usuarioCon([Permissions::VIEW_SUPPORT]);
        $ticket = $this->ticket();

        $this->actingAs($usuario)->getJson('/api/support')->assertForbidden();
        $this->actingAs($usuario)->getJson("/api/support/{$ticket->id}")->assertForbidden();
        $this->actingAs($usuario)->getJson('/api/catalogs/ticket')->assertForbidden();
    }

    // ── El permiso de lectura NO permite escribir ────────────────────────

    #[Test]
    public function ticket_view_no_permite_editar(): void
    {
        $usuario = $this->usuarioCon([Permissions::TICKET_VIEW]);
        $ticket = $this->ticket();

        $this->actingAs($usuario)
            ->putJson("/api/support/{$ticket->id}", ['subject' => 'Otro asunto'])
            ->assertForbidden()
            ->assertJsonPath('required_permission', Permissions::TICKET_EDIT);

        $this->assertSame('Sin señal desde anoche', $ticket->fresh()->subject);
    }

    #[Test]
    public function ticket_view_no_permite_crear(): void
    {
        $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW]))
            ->postJson('/api/support', ['subject' => 'Nuevo', 'user_id' => $this->customer->id])
            ->assertForbidden();

        $this->assertSame(0, SupportTicket::count());
    }

    #[Test]
    public function ticket_view_no_permite_anotar(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW]))
            ->postJson("/api/support/{$ticket->id}/message", ['message' => 'Una nota cualquiera'])
            ->assertForbidden();

        $this->assertSame(0, DB::table('support_ticket_message')->count());
    }

    #[Test]
    public function ticket_view_no_permite_adjuntar(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->usuarioCon($this->todasMenos(Permissions::TICKET_ATTACH)))
            ->post("/api/support/{$ticket->id}", [
                '_method' => 'PUT',
                'attachments' => [UploadedFile::fake()->image('evidencia.jpg')],
            ])
            ->assertForbidden()
            ->assertJsonPath('required_permission', Permissions::TICKET_ATTACH);

        $this->assertSame(0, SupportTicketAttachment::count());
    }

    #[Test]
    public function ticket_view_no_permite_transicionar(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW]))
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'in_progress'])
            ->assertForbidden();

        $this->assertSame('open', $ticket->fresh()->status);
    }

    #[Test]
    public function ticket_view_no_permite_ver_evidencia_ni_historial(): void
    {
        $ticket = $this->ticket();

        $adjunto = SupportTicketAttachment::create([
            'ticket_id' => $ticket->id, 'user_id' => $this->customer->id,
            'file_name' => 'sp1.jpg', 'file_path' => "support_attachments/{$ticket->id}/sp1.jpg",
            'file_size' => 10, 'mime_type' => 'image/jpeg',
        ]);
        Storage::disk('s3')->put($adjunto->file_path, 'x');

        $usuario = $this->usuarioCon([Permissions::TICKET_VIEW]);

        $this->actingAs($usuario)
            ->getJson("/api/support/{$ticket->id}/attachments/{$adjunto->id}")->assertForbidden();
        $this->actingAs($usuario)
            ->getJson("/api/support/{$ticket->id}/attachments/{$adjunto->id}/download")->assertForbidden();
        $this->actingAs($usuario)
            ->getJson("/api/support/{$ticket->id}/history")->assertForbidden();
    }

    // ── Cada permiso abre SÓLO su acción ─────────────────────────────────

    #[Test]
    public function cada_campo_del_update_exige_su_propio_permiso(): void
    {
        $tecnico = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $casos = [
            Permissions::TICKET_EDIT          => ['subject' => 'Asunto corregido'],
            Permissions::TICKET_ASSIGN        => ['staff_id' => $tecnico->id],
            Permissions::TICKET_SET_PRIORITY  => ['priority' => 'high'],
            Permissions::TICKET_SET_CATEGORY  => ['category' => 'billing'],
            Permissions::TICKET_DIAGNOSE      => ['symptom' => 'S02'],
            Permissions::TICKET_CONFIRM_CAUSE => ['confirmed_cause' => 'RF'],
        ];

        foreach ($casos as $permiso => $cuerpo) {
            $ticket = $this->ticket();

            // Sin ese permiso concreto —con todos los demás— se rechaza.
            $this->actingAs($this->usuarioCon($this->todasMenos($permiso)))
                ->putJson("/api/support/{$ticket->id}", $cuerpo)
                ->assertForbidden()
                ->assertJsonPath('required_permission', $permiso);

            // Con él, pasa.
            $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW, $permiso]))
                ->putJson("/api/support/{$ticket->id}", $cuerpo)
                ->assertOk();
        }
    }

    #[Test]
    public function confirmar_la_causa_no_lo_cubre_el_permiso_de_diagnosticar(): void
    {
        // El requerimiento separa las dos potestades: diagnosticar es de N2 y
        // confirmar la causa se la da al Supervisor.
        $ticket = $this->ticket();
        $usuario = $this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_DIAGNOSE]);

        $this->actingAs($usuario)
            ->putJson("/api/support/{$ticket->id}", ['suspected_cause' => 'RF'])->assertOk();

        $this->actingAs($usuario)
            ->putJson("/api/support/{$ticket->id}", ['confirmed_cause' => 'CL'])
            ->assertForbidden()
            ->assertJsonPath('required_permission', Permissions::TICKET_CONFIRM_CAUSE);
    }

    #[Test]
    public function cerrar_exige_un_permiso_aparte_de_transicionar(): void
    {
        $ticket = $this->ticket();

        $sinCierre = $this->usuarioCon([
            Permissions::TICKET_VIEW, Permissions::TICKET_TRANSITION,
        ], 'staff');

        // Transicionar a un estado no terminal, sí.
        $this->actingAs($sinCierre)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'in_progress'])
            ->assertOk();

        // Cerrar, no.
        $this->actingAs($sinCierre)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'closed'])
            ->assertForbidden()
            ->assertJsonPath('required_permission', Permissions::TICKET_CLOSE);

        $this->assertSame('in_progress', $ticket->fresh()->status);

        // Con `ticket_close`, sí.
        $conCierre = $this->usuarioCon([
            Permissions::TICKET_VIEW, Permissions::TICKET_TRANSITION, Permissions::TICKET_CLOSE,
        ], 'staff');

        $this->actingAs($conCierre)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'closed'])
            ->assertOk();

        $this->assertSame('closed', $ticket->fresh()->status);
    }

    #[Test]
    public function reenviar_el_formulario_sin_cambios_no_exige_permisos_de_mas(): void
    {
        // La pantalla de edición reenvía el formulario ENTERO. Si cada campo
        // presente exigiera su permiso aunque no cambie, separar los permisos
        // rompería la pantalla para cualquiera que no los tuviera todos.
        $ticket = $this->ticket();

        $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_EDIT]))
            ->putJson("/api/support/{$ticket->id}", [
                'subject'  => 'Otro asunto',
                'priority' => 'medium',     // igual que el actual
                'category' => 'technical',  // igual que el actual
                'status'   => 'open',       // igual que el actual
            ])
            ->assertOk();

        $this->assertSame('Otro asunto', $ticket->fresh()->subject);
    }

    #[Test]
    public function al_crear_el_diagnostico_tambien_exige_su_permiso(): void
    {
        // Si no, quien no puede diagnosticar un ticket existente lo haría
        // colando los campos en el alta.
        $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_CREATE]))
            ->postJson('/api/support', [
                'subject' => 'Nuevo', 'user_id' => $this->customer->id, 'symptom' => 'S02',
            ])
            ->assertForbidden()
            ->assertJsonPath('required_permission', Permissions::TICKET_DIAGNOSE);

        $this->assertSame(0, SupportTicket::count());

        // Sin diagnóstico, el alta pasa con sólo `ticket_create`.
        $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_CREATE]))
            ->postJson('/api/support', ['subject' => 'Nuevo', 'user_id' => $this->customer->id])
            ->assertCreated();
    }

    #[Test]
    public function las_estadisticas_exigen_ticket_export(): void
    {
        $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW], 'staff'))
            ->getJson('/api/support/statistics')->assertForbidden();

        $this->actingAs($this->usuarioCon([Permissions::TICKET_EXPORT], 'staff'))
            ->getJson('/api/support/statistics')->assertOk();
    }

    #[Test]
    public function staff_profile_sigue_siendo_requisito_ademas_del_permiso(): void
    {
        // Tener `ticket_note` no basta si el rol no es de personal: son dos
        // puertas distintas y quitar una ampliaría el acceso.
        $ticket = $this->ticket();

        $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_NOTE], 'technician'))
            ->postJson("/api/support/{$ticket->id}/message", ['message' => 'Nota de prueba larga'])
            ->assertForbidden();

        $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_NOTE], 'staff'))
            ->postJson("/api/support/{$ticket->id}/message", ['message' => 'Nota de prueba larga'])
            ->assertCreated();
    }

    // ── La transición no cambia lo que nadie podía hacer ─────────────────

    #[Test]
    public function el_backfill_preserva_lo_que_cada_rol_ya_podia(): void
    {
        $roles = [
            // [nombre, code, permisos de hoy, ticket_* esperados]
            ['Administrador', 'admin',      [Permissions::VIEW_SUPPORT], 15],
            ['Staff',         'staff',      [Permissions::VIEW_SUPPORT], 15],
            ['Tecnico',       'technician', [Permissions::VIEW_SUPPORT], 11],
            ['Tecnico sin soporte', 'technician', [Permissions::VIEW_CLIENTS], 0],
            ['Contabilidad',  'accounting', [Permissions::VIEW_BILLING], 0],
            ['Cliente',       'customer',   [], 0],
        ];

        $creados = [];

        foreach ($roles as [$nombre, $code, $permisos, $esperados]) {
            $creados[$nombre] = Role::create([
                'name' => $nombre, 'code' => $code,
                'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
            ]);
        }

        $migracion = require database_path(
            'migrations/2026_09_11_000001_backfill_granular_ticket_permissions.php'
        );
        $migracion->up();

        foreach ($roles as [$nombre, , , $esperados]) {
            $permisos = Role::withoutGlobalScopes()->find($creados[$nombre]->id)->permissions;
            $granulares = array_filter($permisos, fn ($p) => str_starts_with($p, 'ticket_'));

            $this->assertCount(
                $esperados,
                $granulares,
                "El rol {$nombre} debía recibir {$esperados} permisos de ticket.",
            );

            // `view_support` NO se retira: sigue gobernando otros módulos.
            if (in_array(Permissions::VIEW_SUPPORT, $permisos, true) || $esperados > 0) {
                $this->assertNotEmpty($permisos);
            }
        }

        // Nadie recibe las capacidades cuya acción todavía no existe.
        foreach (Permissions::TICKET_SIN_ACCION_TODAVIA as $pendiente) {
            foreach ($creados as $nombre => $rol) {
                $this->assertNotContains(
                    $pendiente,
                    Role::withoutGlobalScopes()->find($rol->id)->permissions,
                    "El rol {$nombre} no debe recibir `{$pendiente}`: esa acción no existe todavía.",
                );
            }
        }
    }

    #[Test]
    public function el_backfill_es_idempotente_y_no_toca_el_comodin(): void
    {
        $normal = Role::create([
            'name' => 'Staff', 'code' => 'staff',
            'permissions' => [Permissions::VIEW_SUPPORT], 'tenant_id' => $this->tenant->id,
        ]);

        $comodin = Role::create([
            'name' => 'Dueño', 'code' => 'admin',
            'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);

        $migracion = require database_path(
            'migrations/2026_09_11_000001_backfill_granular_ticket_permissions.php'
        );

        $migracion->up();
        $primera = Role::withoutGlobalScopes()->find($normal->id)->permissions;

        $migracion->up();
        $segunda = Role::withoutGlobalScopes()->find($normal->id)->permissions;

        $this->assertSame($primera, $segunda, 'Reejecutar no debe duplicar nada.');
        $this->assertSame(['*'], Role::withoutGlobalScopes()->find($comodin->id)->permissions);
    }

    #[Test]
    public function el_backfill_se_puede_revertir(): void
    {
        $rol = Role::create([
            'name' => 'Staff', 'code' => 'staff',
            'permissions' => [Permissions::VIEW_SUPPORT], 'tenant_id' => $this->tenant->id,
        ]);

        $migracion = require database_path(
            'migrations/2026_09_11_000001_backfill_granular_ticket_permissions.php'
        );

        $migracion->up();
        $migracion->down();

        $permisos = Role::withoutGlobalScopes()->find($rol->id)->permissions;

        $this->assertSame([Permissions::VIEW_SUPPORT], array_values($permisos));
    }

    // ── Aislamiento entre tenants ────────────────────────────────────────

    #[Test]
    public function el_permiso_no_cruza_de_un_isp_a_otro(): void
    {
        $otro = Tenant::factory()->create();
        $ajeno = SupportTicket::create([
            'tenant_id' => $otro->id, 'user_id' => $this->clienteDe($otro)->id,
            'subject' => 'De otro ISP', 'status' => 'open',
            'priority' => 'medium', 'category' => 'technical',
        ]);

        $usuario = $this->usuarioCon($this->todasMenos());

        // Con todos los permisos del mundo, el scope de tenant sigue mandando.
        $this->actingAs($usuario)->getJson("/api/support/{$ajeno->id}")->assertNotFound();
        $this->actingAs($usuario)
            ->putJson("/api/support/{$ajeno->id}", ['subject' => 'x'])->assertNotFound();
    }

    // ── No regresión ─────────────────────────────────────────────────────

    #[Test]
    public function el_flujo_completo_sigue_funcionando_con_los_permisos_repartidos(): void
    {
        $usuario = $this->usuarioCon($this->todasMenos(), 'staff');

        $creado = $this->actingAs($usuario)->postJson('/api/support', [
            'subject' => 'Humo del PR B', 'user_id' => $this->customer->id,
            'symptom' => 'S02', 'confirmed_cause' => 'RF',
        ])->assertCreated();

        $id = $creado->json('ticket.id');

        $this->actingAs($usuario)->postJson("/api/support/{$id}/message", [
            'message' => 'Nota de la visita técnica', 'is_internal' => true,
        ])->assertCreated();

        $this->actingAs($usuario)->post("/api/support/{$id}", [
            '_method' => 'PUT',
            'attachments' => [UploadedFile::fake()->image('sp1.jpg')],
        ])->assertOk();

        $adjunto = SupportTicketAttachment::where('ticket_id', $id)->firstOrFail();

        $this->actingAs($usuario)->get("/api/support/{$id}/attachments/{$adjunto->id}")->assertOk();
        $this->actingAs($usuario)->getJson("/api/support/{$id}/history")->assertOk();
        $this->actingAs($usuario)->patchJson("/api/support/{$id}/status", ['status' => 'resolved'])->assertOk();

        $detalle = $this->actingAs($usuario)->getJson("/api/support/{$id}")->assertOk()->json();

        $this->assertSame('S02', $detalle['diagnosis']['symptom']['code']);
        $this->assertCount(1, $detalle['messages']);
        $this->assertCount(1, $detalle['attachments']);
        $this->assertSame('resolved', $detalle['status']);
    }

    #[Test]
    public function el_borrado_de_tickets_sigue_bloqueado(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->usuarioCon($this->todasMenos(), 'admin'))
            ->deleteJson("/api/support/{$ticket->id}")
            ->assertForbidden()
            ->assertJsonPath('error', 'ticket_deletion_disabled');

        $this->assertDatabaseHas('support_ticket', ['id' => $ticket->id]);
    }

    #[Test]
    public function el_contrato_de_socios_no_cambia(): void
    {
        $cliente = \App\Models\ApiClient::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Integrador', 'is_active' => true,
        ]);
        $token = $cliente->createToken('contrato', ['read:support']);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        $this->ticket();
        $this->app['auth']->forgetGuards();

        $fila = $this->getJson('/api/v1/partner/tickets', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->assertOk()->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['id', 'customer_id', 'subject', 'description', 'status', 'priority',
             'category', 'resolved_at', 'created_at', 'updated_at'],
            array_keys($fila),
        );
    }

    // ── Ninguna ruta de ticket queda sin permiso ─────────────────────────

    #[Test]
    public function toda_ruta_de_ticket_exige_un_permiso(): void
    {
        // Deny-by-default: si mañana se agrega una ruta de ticket sin
        // `permission:`, este test la encuentra.
        $sinPermiso = [];

        foreach (\Route::getRoutes() as $ruta) {
            $uri = $ruta->uri();

            if (!str_starts_with($uri, 'api/support') && $uri !== 'api/catalogs/ticket') {
                continue;
            }

            // `gatherMiddleware()` devuelve las cadenas crudas —`permission:x`—
            // no el nombre de la clase resuelta.
            $middleware = implode(' ', $ruta->gatherMiddleware());

            if (!str_contains($middleware, 'permission:')) {
                $sinPermiso[] = implode('|', $ruta->methods()) . ' ' . $uri;
            }
        }

        // Los CARGOS son la excepción conocida: son facturación, no operación
        // del ticket, no aparecen en la matriz del requerimiento y siguen con
        // `staff_profile`. Queda documentado como pendiente.
        sort($sinPermiso);

        $this->assertSame(
            ['GET|HEAD api/support/{id}/charges', 'POST api/support/{id}/charge'],
            $sinPermiso,
            'Hay rutas de ticket sin permiso que no son las de cargos.',
        );
    }

    // ── Interfaz ─────────────────────────────────────────────────────────

    #[Test]
    public function la_interfaz_usa_los_permisos_granulares(): void
    {
        $listado = file_get_contents(resource_path('js/pages/Support.vue'));
        $detalle = file_get_contents(resource_path('js/pages/SupportDetail.vue'));
        $router  = file_get_contents(resource_path('js/router/index.js'));
        $sidebar = file_get_contents(resource_path('js/components/Sidebar.vue'));

        $this->assertStringContainsString("can('ticket_create')", $listado);
        $this->assertStringContainsString("can('ticket_edit')", $listado);
        $this->assertStringNotContainsString("can('view_support')", $listado);

        foreach (['ticket_edit', 'ticket_note', 'ticket_view_history', 'ticket_attach'] as $permiso) {
            $this->assertStringContainsString("hasPermission('{$permiso}')", $detalle);
        }

        $this->assertStringContainsString("permission: 'ticket_view'", $router);
        $this->assertStringContainsString("permission: 'ticket_export'", $router);
        $this->assertStringContainsString("hasPermission('ticket_view')", $sidebar);

        // No hay respaldo silencioso a `view_support`: si falta el granular, la
        // acción se oculta y se deja constancia.
        $this->assertStringContainsString('[permisos]', $detalle);
    }
}
