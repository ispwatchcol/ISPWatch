<?php

namespace Tests\Feature\Customers;

use App\Constants\Permissions;
use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Controles y trazabilidad de la eliminación física de clientes.
 *
 * QUÉ SE CIERRA
 *
 * `DELETE /api/customers/{customer}` exigía `edit_internet_service`. Con ese
 * permiso podían borrar clientes **20 roles** —`Administrador`, `Staff`,
 * `Contabilidad` y `Tecnico` de todos los ISP—, y borrar un cliente arrastra en
 * cascada sus facturas, sus pagos, sus créditos y sus documentos. El rol
 * `Tecnico` tiene siete permisos y uno de ellos bastaba.
 *
 * Además la operación **no dejaba ninguna traza**: `destroy()` no escribía en
 * `audit_logs`. Un cliente con miles de facturas podía desaparecer sin que
 * constara quién lo hizo ni por qué.
 *
 * LO QUE ESTE PR NO RESUELVE
 *
 * **P-43 sigue abierta**: facturas, pagos, créditos y arrastres se siguen
 * borrando en cascada. Aquí sólo se restringe quién puede hacerlo y se deja
 * constancia de cuánto se destruyó. Los tests lo afirman explícitamente para
 * que nadie lea esta suite como una garantía de que el dinero sobrevive.
 */
class CustomerDeletionControlsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('s3');

        $this->tenant = Tenant::factory()->create();

        // `CheckPermission` deja pasar SIEMPRE a `role_id == 1` (superadmin
        // global). Con `RefreshDatabase` el primer rol creado se lleva ese id,
        // así que se quema uno de entrada: si no, cualquier rol de prueba
        // pasaría el middleware y los tests de permiso serían falsos positivos.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(array $permisos, string $code = 'staff'): User
    {
        $rol = Role::create([
            'name' => 'Rol ' . $code, 'code' => $code,
            'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $rol->id,
        ]);
    }

    /** Quien sí puede borrar: rol de administrador con el permiso dedicado. */
    private function administrador(): User
    {
        return $this->usuarioCon([
            Permissions::VIEW_CLIENTS,
            Permissions::EDIT_INTERNET_SERVICE,
            Permissions::DELETE_CUSTOMERS,
        ], 'admin');
    }

    private function cliente(?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'user_name' => 'Axel', 'user_lastname' => 'Cano',
        ]);

        CustomerProfile::create([
            'user_id' => $user->id, 'name' => 'Axel', 'last_name' => 'Cano',
            'cedula' => '1234567890', 'status' => true,
        ]);

        return $user;
    }

    /** @return array<string, string> */
    private function cuerpoValido(): array
    {
        return ['reason' => 'Cliente duplicado creado por error en el alta.', 'confirm' => 'ELIMINAR'];
    }

    // ── Permiso ──────────────────────────────────────────────────────────

    #[Test]
    public function un_rol_con_edit_internet_service_ya_no_puede_borrar(): void
    {
        // Éste es el caso real: hasta ahora `edit_internet_service` bastaba, y
        // lo tienen Tecnico, Staff y Contabilidad de todos los ISP.
        $usuario = $this->usuarioCon([
            Permissions::VIEW_CLIENTS,
            Permissions::EDIT_INTERNET_SERVICE,
        ], 'technician');

        $cliente = $this->cliente();

        $this->actingAs($usuario)
            ->deleteJson("/api/customers/{$cliente->id}", $this->cuerpoValido())
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $cliente->id]);
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'customer_deleted')->count());
    }

    #[Test]
    public function un_rol_sin_permisos_tampoco(): void
    {
        $cliente = $this->cliente();

        $this->actingAs($this->usuarioCon([Permissions::VIEW_CLIENTS]))
            ->deleteJson("/api/customers/{$cliente->id}", $this->cuerpoValido())
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $cliente->id]);
    }

    #[Test]
    public function sin_sesion_tampoco(): void
    {
        $cliente = $this->cliente();

        $this->deleteJson("/api/customers/{$cliente->id}", $this->cuerpoValido())
            ->assertUnauthorized();

        $this->assertDatabaseHas('users', ['id' => $cliente->id]);
    }

    #[Test]
    public function el_administrador_con_el_permiso_si_puede(): void
    {
        $cliente = $this->cliente();

        $this->actingAs($this->administrador())
            ->deleteJson("/api/customers/{$cliente->id}", $this->cuerpoValido())
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $cliente->id]);
    }

    #[Test]
    public function la_migracion_concede_el_permiso_solo_a_roles_admin(): void
    {
        $codigos = ['admin', 'staff', 'technician', 'accounting'];
        $roles = [];

        foreach ($codigos as $code) {
            $roles[$code] = Role::create([
                'name' => "Rol {$code}", 'code' => $code,
                'permissions' => [Permissions::EDIT_INTERNET_SERVICE],
                'tenant_id' => $this->tenant->id,
            ]);
        }

        $migracion = require database_path(
            'migrations/2026_08_31_000001_grant_delete_customers_to_admin_roles.php'
        );
        $migracion->up();

        foreach ($codigos as $code) {
            $permisos = Role::withoutGlobalScopes()->find($roles[$code]->id)->permissions;
            $tiene = in_array(Permissions::DELETE_CUSTOMERS, $permisos, true);

            $code === 'admin'
                ? $this->assertTrue($tiene, 'El rol admin debe recibir el permiso.')
                : $this->assertFalse($tiene, "El rol {$code} NO debe recibirlo.");
        }
    }

    // ── Motivo y confirmación ────────────────────────────────────────────

    #[Test]
    public function sin_motivo_no_se_borra(): void
    {
        $cliente = $this->cliente();

        $this->actingAs($this->administrador())
            ->deleteJson("/api/customers/{$cliente->id}", ['confirm' => 'ELIMINAR'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseHas('users', ['id' => $cliente->id]);
    }

    #[Test]
    public function un_motivo_demasiado_corto_se_rechaza(): void
    {
        $cliente = $this->cliente();

        $this->actingAs($this->administrador())
            ->deleteJson("/api/customers/{$cliente->id}", ['reason' => 'error', 'confirm' => 'ELIMINAR'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseHas('users', ['id' => $cliente->id]);
    }

    #[Test]
    public function un_motivo_de_solo_espacios_se_rechaza(): void
    {
        $cliente = $this->cliente();

        // `TrimStrings` deja la cadena vacía, así que no pasa `required`.
        $this->actingAs($this->administrador())
            ->deleteJson("/api/customers/{$cliente->id}", ['reason' => '              ', 'confirm' => 'ELIMINAR'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseHas('users', ['id' => $cliente->id]);
    }

    #[Test]
    public function un_motivo_demasiado_largo_se_rechaza(): void
    {
        $cliente = $this->cliente();

        $this->actingAs($this->administrador())
            ->deleteJson("/api/customers/{$cliente->id}", [
                'reason' => str_repeat('a', 501), 'confirm' => 'ELIMINAR',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    #[Test]
    public function sin_la_confirmacion_explicita_no_se_borra(): void
    {
        $cliente = $this->cliente();

        // El backend no confía en que la interfaz haya pedido escribir
        // «ELIMINAR»: lo exige él.
        $this->actingAs($this->administrador())
            ->deleteJson("/api/customers/{$cliente->id}", ['reason' => 'Motivo suficientemente largo.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm');

        $this->assertDatabaseHas('users', ['id' => $cliente->id]);
    }

    // ── Auditoría ────────────────────────────────────────────────────────

    #[Test]
    public function la_eliminacion_queda_auditada_con_actor_motivo_y_alcance(): void
    {
        $admin = $this->administrador();
        $cliente = $this->cliente();

        $respuesta = $this->actingAs($admin)
            ->deleteJson("/api/customers/{$cliente->id}", $this->cuerpoValido())
            ->assertOk();

        $log = AuditLog::withoutGlobalScopes()->where('action', 'customer_deleted')->firstOrFail();

        $this->assertSame($admin->id, (int) $log->user_id, 'El actor sale de la sesión.');
        $this->assertSame($this->tenant->id, (int) $log->tenant_id);
        $this->assertSame($cliente->id, (int) $log->model_id);
        $this->assertNotNull($log->created_at);

        $this->assertSame('Cliente duplicado creado por error en el alta.', $log->new_values['reason']);
        $this->assertSame(
            $respuesta->json('correlation_id'),
            $log->new_values['correlation_id'],
            'La respuesta debe poder atarse a su fila de auditoría.',
        );

        // El resumen dice de quién se habla y cuánto se destruyó.
        $this->assertSame('Axel Cano', $log->old_values['customer']['name']);
        $this->assertArrayHasKey('invoices', $log->old_values['se_eliminan']);
        $this->assertArrayHasKey('support_ticket', $log->old_values['se_conservan']);
    }

    #[Test]
    public function la_auditoria_no_guarda_secretos_ni_contenido(): void
    {
        $admin = $this->administrador();
        $cliente = $this->cliente();

        $ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $cliente->id,
            'subject' => 'Ticket', 'status' => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);

        SupportTicketMessage::create([
            'ticket_id' => $ticket->id, 'user_id' => $cliente->id,
            'message' => 'CONTENIDO-SECRETO-DE-LA-NOTA', 'is_internal' => true,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/customers/{$cliente->id}", $this->cuerpoValido())->assertOk();

        $serializado = json_encode(
            AuditLog::withoutGlobalScopes()->where('action', 'customer_deleted')->firstOrFail()->toArray()
        );

        // Conteos, no contenido: la auditoría dice el ALCANCE de lo destruido,
        // no conserva una copia de lo destruido.
        foreach (['CONTENIDO-SECRETO-DE-LA-NOTA', 'password', 'token', 'file_path'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $serializado);
        }
    }

    #[Test]
    public function la_auditoria_se_escribe_antes_de_destruir(): void
    {
        $cliente = $this->cliente();

        $this->actingAs($this->administrador())
            ->deleteJson("/api/customers/{$cliente->id}", $this->cuerpoValido())->assertOk();

        $log = AuditLog::withoutGlobalScopes()->where('action', 'customer_deleted')->firstOrFail();

        // Si se hubiera escrito después, el conteo sería 0: el cliente ya no
        // existiría y no habría de dónde sacarlo.
        $this->assertIsInt($log->old_values['se_eliminan']['invoices']);
        $this->assertSame('1234567890', $log->old_values['customer']['cedula']);
    }

    #[Test]
    public function si_la_auditoria_falla_no_se_borra_nada(): void
    {
        $cliente = $this->cliente();

        // Se rompe la tabla de auditoría para forzar el fallo de escritura.
        DB::statement('DROP TABLE audit_logs');

        $this->actingAs($this->administrador())
            ->deleteJson("/api/customers/{$cliente->id}", $this->cuerpoValido())
            ->assertStatus(500)
            ->assertJsonPath('error', 'audit_write_failed');

        $this->assertDatabaseHas('users', ['id' => $cliente->id]);
        $this->assertDatabaseHas('customer_profile', ['user_id' => $cliente->id]);
    }

    // ── Enlaces contractuales ────────────────────────────────────────────

    #[Test]
    public function los_enlaces_de_firma_quedan_desvinculados_y_revocados(): void
    {
        $cliente = $this->cliente();

        $id = DB::table('contract_signature_links')->insertGetId([
            'tenant_id'   => $this->tenant->id,
            'customer_id' => $cliente->id,
            'token_hash'  => hash('sha256', 'token-de-prueba'),
            'expires_at'  => now()->addDays(7),
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->administrador())
            ->deleteJson("/api/customers/{$cliente->id}", $this->cuerpoValido())->assertOk();

        $enlace = DB::table('contract_signature_links')->where('id', $id)->first();

        $this->assertNotNull($enlace, 'El enlace no se borra: es rastro de que se pidió una firma.');
        $this->assertNull($enlace->customer_id, 'No puede quedar apuntando a un cliente inexistente.');
        $this->assertNotNull($enlace->revoked_at, 'Y queda revocado, no sólo desvinculado.');
    }

    #[Test]
    public function no_quedan_enlaces_huerfanos(): void
    {
        $cliente = $this->cliente();

        DB::table('contract_signature_links')->insert([
            'tenant_id' => $this->tenant->id, 'customer_id' => $cliente->id,
            'token_hash' => hash('sha256', 'a'), 'expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->administrador())
            ->deleteJson("/api/customers/{$cliente->id}", $this->cuerpoValido())->assertOk();

        $huerfanos = DB::table('contract_signature_links')
            ->whereNotNull('customer_id')
            ->whereNotIn('customer_id', DB::table('users')->select('id'))
            ->count();

        $this->assertSame(0, $huerfanos);
    }

    // ── El expediente del ticket (garantía de H-6) ───────────────────────

    #[Test]
    public function el_ticket_sus_notas_y_adjuntos_sobreviven(): void
    {
        $cliente = $this->cliente();

        $ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $cliente->id,
            'subject' => 'Sin señal', 'status' => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);

        $nota = SupportTicketMessage::create([
            'ticket_id' => $ticket->id, 'user_id' => $cliente->id,
            'message' => 'Se revisó la ONU.', 'is_internal' => true,
        ]);

        $adjunto = SupportTicketAttachment::create([
            'ticket_id' => $ticket->id, 'user_id' => $cliente->id,
            'file_name' => 'sp1.jpg', 'file_path' => "support_attachments/{$ticket->id}/sp1.jpg",
            'file_size' => 100, 'mime_type' => 'image/jpeg',
        ]);

        Storage::disk('s3')->put($adjunto->file_path, 'contenido');

        $this->actingAs($this->administrador())
            ->deleteJson("/api/customers/{$cliente->id}", $this->cuerpoValido())->assertOk();

        $this->assertDatabaseHas('support_ticket', ['id' => $ticket->id]);
        $this->assertDatabaseHas('support_ticket_message', ['id' => $nota->id]);
        $this->assertDatabaseHas('support_ticket_attachment', ['id' => $adjunto->id]);
        Storage::disk('s3')->assertExists($adjunto->file_path);

        // El historial del ticket también.
        $this->assertGreaterThan(
            0,
            DB::table('support_ticket_history')->where('support_ticket_id', $ticket->id)->count(),
        );

        $this->assertSame('Axel Cano', $nota->fresh()->author_name);
    }

    #[Test]
    public function las_facturas_siguen_borrandose_en_cascada_p43_sigue_abierta(): void
    {
        // ESTE TEST AFIRMA UN DEFECTO, NO UNA GARANTÍA.
        //
        // `invoices.customer_id`, `payments.customer_id` y compañía siguen en
        // `ON DELETE CASCADE`: dar de baja a un cliente borra su histórico de
        // facturación. Este PR NO lo resuelve — sólo restringe quién puede
        // hacerlo y deja constancia de cuánto se destruyó.
        //
        // Se fija por prueba para que nadie lea esta suite como si el dinero
        // sobreviviera, y para que el día que se corrija P-43 este test falle y
        // obligue a actualizarlo.
        $cliente = $this->cliente();

        $facturaId = DB::table('invoices')->insertGetId([
            'tenant_id' => $this->tenant->id, 'customer_id' => $cliente->id,
            'total' => 50000, 'status' => 'pending',
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(15)->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->administrador())
            ->deleteJson("/api/customers/{$cliente->id}", $this->cuerpoValido())->assertOk();

        $this->assertDatabaseMissing('invoices', ['id' => $facturaId]);

        // Pero al menos ahora consta cuántas había.
        $log = AuditLog::withoutGlobalScopes()->where('action', 'customer_deleted')->firstOrFail();
        $this->assertSame(1, $log->old_values['se_eliminan']['invoices']);
    }

    // ── Aislamiento y no regresión ───────────────────────────────────────

    #[Test]
    public function no_se_puede_borrar_un_cliente_de_otro_isp(): void
    {
        $otro = Tenant::factory()->create();
        $ajeno = $this->cliente($otro);

        $this->actingAs($this->administrador())
            ->deleteJson("/api/customers/{$ajeno->id}", $this->cuerpoValido())
            ->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $ajeno->id]);
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'customer_deleted')->count());
    }

    #[Test]
    public function el_borrado_de_tickets_sigue_bloqueado(): void
    {
        $cliente = $this->cliente();

        $ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $cliente->id,
            'subject' => 'T', 'status' => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);

        $this->actingAs($this->administrador())
            ->deleteJson("/api/support/{$ticket->id}")
            ->assertForbidden();
    }

    #[Test]
    public function la_interfaz_exige_permiso_motivo_y_confirmacion(): void
    {
        $listado  = file_get_contents(resource_path('js/pages/Customers.vue'));
        $servicio = file_get_contents(resource_path('js/services/api/customers.js'));

        $this->assertStringContainsString("can('delete_customers')", $listado);

        // Las dos llamadas a `deleteCustomer` (tabla y tarjeta móvil) deben
        // estar gobernadas por el permiso nuevo, no por el de activar/desactivar.
        $this->assertSame(
            2,
            substr_count($listado, "can('delete_customers')"),
            'Ambas vistas deben usar el permiso dedicado.',
        );
        $this->assertSame(
            substr_count($listado, 'deleteCustomer(customer)'),
            substr_count($listado, "can('delete_customers')"),
            'No puede quedar ninguna llamada a deleteCustomer sin ese permiso.',
        );

        $this->assertStringContainsString('requireReason: true', $listado);
        $this->assertStringContainsString("requireText: 'ELIMINAR'", $listado);
        $this->assertStringContainsString('NO se puede deshacer', $listado);
        $this->assertStringContainsString('quedará registrada en la auditoría', $listado);

        $this->assertStringContainsString('delete(id, payload', $servicio);
    }
}
