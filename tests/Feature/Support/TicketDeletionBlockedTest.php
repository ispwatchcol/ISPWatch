<?php

namespace Tests\Feature\Support;

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
 * PR A · El ticket no se puede destruir físicamente.
 *
 * EL AGUJERO QUE ESTOS TESTS CIERRAN
 *
 * `DELETE /api/support/{id}` estaba tras `permission:view_support` —el MISMO
 * permiso que leer— y borraba de verdad. Con `support_ticket_history` colgando
 * del ticket por `ON DELETE CASCADE` desde el PR #3, un clic de cualquier
 * técnico se llevaba el ticket **y su auditoría entera**. La única barrera era
 * un `confirm()` del navegador.
 *
 * Hay tres defensas, y cada una se prueba por separado a propósito: si mañana
 * alguien reactiva el endpoint, las otras dos siguen en pie.
 *
 *   1. La RUTA responde 403 sin tocar la base.
 *   2. El MODELO lanza en `deleting`, así que ningún camino de Eloquent borra
 *      —controlador, comando, job o acción masiva futura—.
 *   3. La BASE DE DATOS rechaza el borrado con `ON DELETE RESTRICT`, que es lo
 *      único que cubre un `where(...)->delete()` sin Eloquent.
 */
class TicketDeletionBlockedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('s3');

        $this->tenant = Tenant::factory()->create();

        $rol = Role::create([
            'name' => 'Administrador', 'code' => 'admin',
            'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $rol->id,
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

    private function ticket(): SupportTicket
    {
        return SupportTicket::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->customer->id,
            'subject'   => 'Sin señal desde anoche',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    /** Un usuario con un rol de permisos limitados que incluye `view_support`. */
    private function usuarioConSoloLectura(): User
    {
        $rol = Role::create([
            'name' => 'Tecnico', 'code' => 'staff',
            'permissions' => ['view_support'], 'tenant_id' => $this->tenant->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $rol->id,
        ]);
    }

    // ── Defensa 1 · la ruta ──────────────────────────────────────────────

    #[Test]
    public function el_endpoint_de_borrado_responde_403_y_el_ticket_sobrevive(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->deleteJson("/api/support/{$ticket->id}")
            ->assertForbidden()
            ->assertJsonPath('error', 'ticket_deletion_disabled');

        $this->assertDatabaseHas('support_ticket', ['id' => $ticket->id]);
    }

    #[Test]
    public function un_usuario_con_view_support_no_puede_borrar(): void
    {
        // Éste es el caso real: `view_support` es un permiso de LECTURA y lo
        // tienen los roles Tecnico y Staff de todos los ISP.
        $tecnico = $this->usuarioConSoloLectura();
        $ticket = $this->ticket();

        $this->actingAs($tecnico)
            ->deleteJson("/api/support/{$ticket->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('support_ticket', ['id' => $ticket->id]);
    }

    #[Test]
    public function ni_siquiera_un_administrador_puede_borrar(): void
    {
        // El rol con `*` pasa cualquier `permission:`. El bloqueo no es de
        // permisos: es que la operación no existe.
        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->deleteJson("/api/support/{$ticket->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('support_ticket', ['id' => $ticket->id]);
    }

    #[Test]
    public function el_rechazo_no_revela_si_el_ticket_existe(): void
    {
        // Se responde 403 sin consultar la base, así que un id inexistente da la
        // misma respuesta. Eso evita usar el endpoint para sondear qué ids hay.
        $inexistente = $this->actingAs($this->staff)->deleteJson('/api/support/999999');
        $existente   = $this->actingAs($this->staff)->deleteJson("/api/support/{$this->ticket()->id}");

        $inexistente->assertForbidden();
        $existente->assertForbidden();
        $this->assertSame($inexistente->json('error'), $existente->json('error'));
    }

    #[Test]
    public function sin_sesion_tampoco(): void
    {
        $ticket = $this->ticket();

        $this->deleteJson("/api/support/{$ticket->id}")->assertUnauthorized();

        $this->assertDatabaseHas('support_ticket', ['id' => $ticket->id]);
    }

    // ── Defensa 2 · el modelo ────────────────────────────────────────────

    #[Test]
    public function el_modelo_lanza_ante_cualquier_borrado_por_eloquent(): void
    {
        $ticket = $this->ticket();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no se pueden eliminar');

        // Esto cubre de una vez el controlador, un comando, un job y cualquier
        // acción masiva futura: todos pasan por aquí.
        $ticket->delete();
    }

    #[Test]
    public function el_borrado_por_eloquent_no_deja_el_ticket_a_medias(): void
    {
        $ticket = $this->ticket();

        try {
            $ticket->delete();
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertDatabaseHas('support_ticket', ['id' => $ticket->id]);
        $this->assertNotEmpty($this->historialDe($ticket));
    }

    // ── Defensa 3 · la base de datos ─────────────────────────────────────

    #[Test]
    public function la_base_de_datos_rechaza_borrar_un_ticket_con_historial(): void
    {
        $ticket = $this->ticket();

        $this->assertNotEmpty(
            $this->historialDe($ticket),
            'El alta del ticket ya debe haber dejado un evento.',
        );

        // Salta el modelo a propósito: `where()->delete()` no dispara Eloquent.
        // Aquí sólo protege la clave foránea.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('support_ticket')->where('id', $ticket->id)->delete();
    }

    #[Test]
    public function el_historial_sigue_intacto_tras_el_intento(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['status' => 'resolved'])->assertOk();

        $antes = count($this->historialDe($ticket));

        $this->actingAs($this->staff)->deleteJson("/api/support/{$ticket->id}")->assertForbidden();

        $this->assertCount($antes, $this->historialDe($ticket));
        $this->assertGreaterThanOrEqual(2, $antes, 'Alta + cambio de estado.');
    }

    /** @return array<int, object> */
    private function historialDe(SupportTicket $ticket): array
    {
        return SupportTicketHistory::withoutGlobalScopes()
            ->where('support_ticket_id', $ticket->id)
            ->get()
            ->all();
    }

    // ── H-3 · los adjuntos no se tocan ───────────────────────────────────

    #[Test]
    public function bloquear_el_borrado_no_toca_los_adjuntos(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->post("/api/support/{$ticket->id}", [
            '_method' => 'PUT',
            'attachments' => [UploadedFile::fake()->image('evidencia.jpg', 40, 30)],
        ])->assertOk();

        $adjunto = SupportTicketAttachment::where('ticket_id', $ticket->id)->firstOrFail();

        $this->actingAs($this->staff)->deleteJson("/api/support/{$ticket->id}")->assertForbidden();

        // La fila sigue.
        $this->assertDatabaseHas('support_ticket_attachment', ['id' => $adjunto->id]);

        // Y el archivo también. El `destroy()` viejo borraba de `public`, un
        // disco donde los adjuntos ya no viven desde el PR #252: no borraba el
        // objeto del bucket y sí la fila que decía dónde estaba.
        Storage::disk('s3')->assertExists($adjunto->file_path);

        // Y se sigue pudiendo descargar.
        $this->actingAs($this->staff)
            ->get("/api/support/{$ticket->id}/attachments/{$adjunto->id}")
            ->assertOk();
    }

    #[Test]
    public function las_notas_siguen_intactas(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/message", [
            'message' => 'Se revisó la ONU en sitio.', 'is_internal' => true,
        ])->assertCreated();

        $this->actingAs($this->staff)->deleteJson("/api/support/{$ticket->id}")->assertForbidden();

        $this->assertSame(1, SupportTicketMessage::where('ticket_id', $ticket->id)->count());
    }

    // ── H-4 · la trazabilidad contable ───────────────────────────────────

    #[Test]
    public function un_ticket_con_cargo_conserva_el_vinculo_con_la_factura(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/charge", [
            'items' => [['description' => 'Cambio de CPE', 'quantity' => 1, 'unit_price' => 120000]],
        ])->assertCreated();

        $factura = Invoice::withoutGlobalScopes()->where('ticket_id', $ticket->id)->firstOrFail();

        $this->actingAs($this->staff)->deleteJson("/api/support/{$ticket->id}")->assertForbidden();

        // `invoices.ticket_id` es `nullOnDelete()`: si el ticket se hubiera
        // borrado, el cargo habría quedado huérfano — plata facturada sin
        // expediente que la justifique.
        $this->assertSame(
            $ticket->id,
            (int) $factura->fresh()->ticket_id,
            'El cargo debe seguir apuntando a su ticket de origen.',
        );
    }

    #[Test]
    public function la_base_de_datos_tambien_protege_un_ticket_con_cargo(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/charge", [
            'items' => [['description' => 'Visita técnica', 'quantity' => 1, 'unit_price' => 50000]],
        ])->assertCreated();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('support_ticket')->where('id', $ticket->id)->delete();
    }

    // ── No regresión ─────────────────────────────────────────────────────

    #[Test]
    public function los_tickets_existentes_siguen_cargando_y_editandose(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->getJson('/api/support')->assertOk();
        $this->actingAs($this->staff)->getJson("/api/support/{$ticket->id}")->assertOk();
        $this->actingAs($this->staff)->getJson("/api/support/{$ticket->id}/history")->assertOk();

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['status' => 'in_progress', 'symptom' => 'S02'])
            ->assertOk();

        $detalle = $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")->assertOk()->json();

        $this->assertSame('in_progress', $detalle['status']);
        $this->assertSame('S02', $detalle['diagnosis']['symptom']['code']);
    }

    #[Test]
    public function un_ticket_anterior_escrito_por_sql_tambien_queda_protegido(): void
    {
        // Fila sin evento de alta, como las que ya existen en producción.
        $id = DB::table('support_ticket')->insertGetId([
            'tenant_id'   => $this->tenant->id,
            'user_id'     => $this->customer->id,
            'subject'     => 'Ticket anterior',
            'status_id'   => DB::table('ticket_status')->where('code', 'open')->value('id'),
            'priority_id' => DB::table('ticket_priority')->where('code', 'medium')->value('id'),
            'category_id' => DB::table('ticket_category')->where('code', 'technical')->value('id'),
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->staff)->deleteJson("/api/support/{$id}")->assertForbidden();
        $this->assertDatabaseHas('support_ticket', ['id' => $id]);

        // Sin historial la clave foránea no lo protege —no hay filas que
        // restringir—, pero el modelo sí. Las tres defensas se complementan.
        $modelo = SupportTicket::find($id);

        $this->expectException(RuntimeException::class);
        $modelo->delete();
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

        // Y no existe ninguna ruta de escritura destructiva para socios.
        $rutas = collect(\Route::getRoutes())->filter(
            fn ($r) => str_contains($r->uri(), 'v1/partner') && in_array('DELETE', $r->methods(), true)
        );

        $this->assertCount(0, $rutas, 'La API de socios es de sólo lectura.');
    }

    // ── Interfaz ─────────────────────────────────────────────────────────

    #[Test]
    public function la_interfaz_ya_no_ofrece_eliminar_un_ticket(): void
    {
        $listado  = file_get_contents(resource_path('js/pages/Support.vue'));
        $servicio = file_get_contents(resource_path('js/services/api/support.js'));

        $this->assertStringNotContainsString('deleteTicket', $listado);
        $this->assertStringNotContainsString('¿Estás seguro de eliminar este ticket?', $listado);
        $this->assertStringNotContainsString('icon-lucide-trash-2', $listado);

        // El cliente de API tampoco expone el método, para que no quede una
        // llamada muerta que alguien reutilice sin leer el backend.
        $this->assertStringNotContainsString('apiClient.delete(`/support/${id}`)', $servicio);
    }
}
