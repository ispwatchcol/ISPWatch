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
use Tests\TestCase;

/**
 * H-6 · El expediente del ticket sobrevive a la baja de un usuario.
 *
 * QUÉ PASABA
 *
 * `support_ticket_message.user_id` y `support_ticket_attachment.user_id` se
 * declararon en 2025 con `ON DELETE CASCADE` sobre `users`, y
 * `CustomerDeletionService` termina con `$user->delete()`. Dar de baja a un
 * cliente **borraba las notas y los adjuntos** de todos sus tickets.
 *
 * El ticket sobrevivía —su `user_id` ya era `SET NULL`— pero quedaba vaciado
 * por dentro, y el historial del PR #3 apuntaba a filas inexistentes.
 *
 * POR QUÉ AFECTABA AL CLIENTE Y NO SÓLO AL PERSONAL
 *
 * Al crear un ticket, `store()` atribuye los adjuntos a `$data['user_id']`, que
 * es **el cliente**, no quien los sube. Así que la baja de un cliente sí barría
 * evidencia real.
 */
class TicketExpedientSurvivesUserDeletionTest extends TestCase
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
            'user_name' => 'Lucía', 'user_lastname' => 'Bermúdez',
        ]);

        $this->customer = $this->clienteDe($this->tenant, 'Axel', 'Cano');
    }

    private function clienteDe(Tenant $tenant, string $nombre, string $apellido): User
    {
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'user_name' => $nombre, 'user_lastname' => $apellido,
        ]);

        CustomerProfile::create([
            'user_id' => $user->id, 'name' => $nombre, 'last_name' => $apellido, 'status' => true,
        ]);

        return $user;
    }

    private function ticket(?Tenant $tenant = null, ?User $cliente = null): SupportTicket
    {
        $tenant ??= $this->tenant;

        return SupportTicket::create([
            'tenant_id' => $tenant->id,
            'user_id'   => ($cliente ?? $this->customer)->id,
            'subject'   => 'Sin señal desde anoche',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    /** Ticket con nota y adjunto, ambos atribuidos al usuario indicado. */
    private function expedienteDe(SupportTicket $ticket, User $autor): array
    {
        $nota = SupportTicketMessage::create([
            'ticket_id' => $ticket->id, 'user_id' => $autor->id,
            'message' => 'Se revisó la ONU; potencia dentro de rango.', 'is_internal' => true,
        ]);

        $adjunto = SupportTicketAttachment::create([
            'ticket_id' => $ticket->id, 'user_id' => $autor->id,
            'file_name' => 'sp1.jpg', 'file_path' => "support_attachments/{$ticket->id}/sp1.jpg",
            'file_size' => 1024, 'mime_type' => 'image/jpeg',
        ]);

        Storage::disk('s3')->put($adjunto->file_path, 'contenido-de-prueba');

        return [$nota, $adjunto];
    }

    // ── El caso central ──────────────────────────────────────────────────

    #[Test]
    public function borrar_al_cliente_conserva_ticket_nota_y_adjunto(): void
    {
        $ticket = $this->ticket();
        [$nota, $adjunto] = $this->expedienteDe($ticket, $this->customer);

        $this->customer->delete();

        $this->assertDatabaseHas('support_ticket', ['id' => $ticket->id]);
        $this->assertDatabaseHas('support_ticket_message', ['id' => $nota->id]);
        $this->assertDatabaseHas('support_ticket_attachment', ['id' => $adjunto->id]);
    }

    #[Test]
    public function el_autor_queda_en_null_y_el_nombre_congelado_sobrevive(): void
    {
        $ticket = $this->ticket();
        [$nota, $adjunto] = $this->expedienteDe($ticket, $this->customer);

        $this->assertSame('Axel Cano', $nota->fresh()->author_name);

        $this->customer->delete();

        $notaTras    = DB::table('support_ticket_message')->where('id', $nota->id)->first();
        $adjuntoTras = DB::table('support_ticket_attachment')->where('id', $adjunto->id)->first();

        $this->assertNull($notaTras->user_id, 'La clave foránea debe quedar en NULL, no borrar la fila.');
        $this->assertNull($adjuntoTras->user_id);

        $this->assertSame('Axel Cano', $notaTras->author_name, 'El nombre se congeló al escribir.');
        $this->assertSame('Axel Cano', $adjuntoTras->author_name);
    }

    #[Test]
    public function el_archivo_del_bucket_no_se_borra_al_dar_de_baja(): void
    {
        $ticket = $this->ticket();
        [, $adjunto] = $this->expedienteDe($ticket, $this->customer);

        Storage::disk('s3')->assertExists($adjunto->file_path);

        $this->customer->delete();

        // `CustomerDeletionService::collectFilePaths()` sólo recoge documentos de
        // cliente y firmas de instalación: nunca adjuntos de ticket. Antes el
        // efecto era el peor posible — la fila desaparecía y el objeto quedaba
        // huérfano en el bucket.
        Storage::disk('s3')->assertExists($adjunto->file_path);
    }

    #[Test]
    public function el_adjunto_sigue_siendo_previsualizable_y_descargable(): void
    {
        $ticket = $this->ticket();
        [, $adjunto] = $this->expedienteDe($ticket, $this->customer);

        $this->customer->delete();

        $this->actingAs($this->staff)
            ->get("/api/support/{$ticket->id}/attachments/{$adjunto->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->actingAs($this->staff)
            ->get("/api/support/{$ticket->id}/attachments/{$adjunto->id}/download")
            ->assertOk();
    }

    #[Test]
    public function el_detalle_del_ticket_no_falla_con_el_autor_ausente(): void
    {
        $ticket = $this->ticket();
        $this->expedienteDe($ticket, $this->customer);

        $this->customer->delete();

        $detalle = $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")->assertOk()->json();

        $this->assertCount(1, $detalle['messages']);
        $this->assertCount(1, $detalle['attachments']);

        // El nombre congelado gana sobre el «Usuario eliminado» genérico.
        $this->assertSame('Axel Cano', $detalle['messages'][0]['author_label']);
        $this->assertSame('Axel Cano', $detalle['attachments'][0]['author_label']);
        $this->assertNull($detalle['messages'][0]['user']);
    }

    #[Test]
    public function el_listado_de_tickets_tampoco_falla(): void
    {
        $ticket = $this->ticket();
        $this->expedienteDe($ticket, $this->customer);

        $this->customer->delete();

        $tickets = $this->actingAs($this->staff)->getJson('/api/support')->assertOk()->json();

        $this->assertCount(1, $tickets);
        $this->assertNull($tickets[0]['user_id'], 'El ticket conserva su expediente sin cliente.');
    }

    #[Test]
    public function sin_nombre_congelado_se_dice_usuario_eliminado_sin_romper(): void
    {
        $ticket = $this->ticket();

        // Fila anterior al snapshot, escrita por SQL directo.
        $id = DB::table('support_ticket_message')->insertGetId([
            'ticket_id' => $ticket->id, 'user_id' => $this->customer->id,
            'author_name' => null,
            'message' => 'Nota anterior al PR', 'is_internal' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->customer->delete();

        $detalle = $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")->assertOk()->json();

        $nota = collect($detalle['messages'])->firstWhere('id', $id);

        $this->assertSame('Usuario eliminado', $nota['author_label']);
    }

    // ── El resto del expediente ──────────────────────────────────────────

    #[Test]
    public function el_historial_del_ticket_permanece(): void
    {
        $ticket = $this->ticket();
        $this->expedienteDe($ticket, $this->customer);

        $antes = SupportTicketHistory::withoutGlobalScopes()
            ->where('support_ticket_id', $ticket->id)->count();

        $this->assertGreaterThan(0, $antes);

        $this->customer->delete();

        $this->assertSame(
            $antes,
            SupportTicketHistory::withoutGlobalScopes()->where('support_ticket_id', $ticket->id)->count(),
        );
    }

    #[Test]
    public function el_diagnostico_se_conserva(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->putJson("/api/support/{$ticket->id}", [
            'symptom' => 'S02', 'confirmed_cause' => 'RF', 'result' => 'R02',
        ])->assertOk();

        $this->customer->delete();

        $diagnostico = $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")->assertOk()->json('diagnosis');

        $this->assertSame('S02', $diagnostico['symptom']['code']);
        $this->assertSame('RF', $diagnostico['confirmed_cause']['code']);
    }

    #[Test]
    public function borrar_al_autor_del_personal_conserva_la_nota_y_el_cargo(): void
    {
        // El cargo se prueba borrando al PERSONAL y no al cliente porque
        // `invoices.customer_id` sigue en CASCADE: dar de baja a un cliente
        // borra sus facturas. Es un problema real y distinto, fuera del alcance
        // de este PR y documentado en DISENO_PERMISOS_Y_ARCHIVADO.md.
        $ticket = $this->ticket();
        [$nota] = $this->expedienteDe($ticket, $this->staff);

        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/charge", [
            'items' => [['description' => 'Cambio de CPE', 'quantity' => 1, 'unit_price' => 120000]],
        ])->assertCreated();

        $factura = Invoice::withoutGlobalScopes()->where('ticket_id', $ticket->id)->firstOrFail();

        $this->staff->delete();

        $this->assertDatabaseHas('support_ticket_message', ['id' => $nota->id]);
        $this->assertSame('Lucía Bermúdez', $nota->fresh()->author_name);

        $this->assertSame(
            $ticket->id,
            (int) $factura->fresh()->ticket_id,
            'El cargo sigue vinculado a su ticket de origen.',
        );
    }

    // ── Aislamiento entre tenants ────────────────────────────────────────

    #[Test]
    public function dar_de_baja_a_un_cliente_no_toca_el_expediente_de_otro_isp(): void
    {
        $otro = Tenant::factory()->create();
        $clienteAjeno = $this->clienteDe($otro, 'Elsa', 'Quintero');
        $ticketAjeno = $this->ticket($otro, $clienteAjeno);
        [$notaAjena, $adjuntoAjeno] = $this->expedienteDe($ticketAjeno, $clienteAjeno);

        $ticketPropio = $this->ticket();
        [$notaPropia] = $this->expedienteDe($ticketPropio, $this->customer);

        $this->customer->delete();

        $this->assertDatabaseHas('support_ticket_message', [
            'id' => $notaAjena->id, 'user_id' => $clienteAjeno->id,
        ]);
        $this->assertDatabaseHas('support_ticket_attachment', ['id' => $adjuntoAjeno->id]);
        $this->assertDatabaseHas('support_ticket_message', ['id' => $notaPropia->id]);
    }

    // ── No regresión ─────────────────────────────────────────────────────

    #[Test]
    public function el_snapshot_se_llena_solo_al_crear_por_el_endpoint(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/message", [
            'message' => 'Nota desde el panel', 'is_internal' => true,
        ])->assertCreated();

        $this->assertSame(
            'Lucía Bermúdez',
            SupportTicketMessage::where('ticket_id', $ticket->id)->value('author_name'),
        );

        $this->actingAs($this->staff)->post("/api/support/{$ticket->id}", [
            '_method' => 'PUT',
            'attachments' => [UploadedFile::fake()->image('evidencia.jpg')],
        ])->assertOk();

        $this->assertSame(
            'Lucía Bermúdez',
            SupportTicketAttachment::where('ticket_id', $ticket->id)
                ->where('file_name', 'evidencia.jpg')->value('author_name'),
        );
    }

    #[Test]
    public function el_snapshot_no_guarda_datos_sensibles(): void
    {
        $ticket = $this->ticket();
        [$nota, $adjunto] = $this->expedienteDe($ticket, $this->customer);

        // Sólo el nombre visible: ni correo, ni teléfono, ni documento. El
        // expediente necesita saber quién escribió, no conservar la ficha de
        // alguien que pidió su baja.
        foreach ([$nota->fresh()->author_name, $adjunto->fresh()->author_name] as $valor) {
            $this->assertStringNotContainsString('@', (string) $valor);
            $this->assertSame('Axel Cano', $valor);
        }
    }

    #[Test]
    public function el_borrado_del_ticket_sigue_bloqueado(): void
    {
        // El PR A no se toca: las tres defensas siguen en pie.
        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->deleteJson("/api/support/{$ticket->id}")
            ->assertForbidden();

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

        $ticket = $this->ticket();
        $this->expedienteDe($ticket, $this->customer);
        $this->customer->delete();

        $this->app['auth']->forgetGuards();

        $fila = $this->getJson('/api/v1/partner/tickets', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->assertOk()->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['id', 'customer_id', 'subject', 'description', 'status', 'priority',
             'category', 'resolved_at', 'created_at', 'updated_at'],
            array_keys($fila),
        );

        // El nombre congelado es del panel: no se filtra al integrador.
        $this->assertArrayNotHasKey('author_name', $fila);
        $this->assertArrayNotHasKey('author_label', $fila);
    }

    // ── Interfaz ─────────────────────────────────────────────────────────

    #[Test]
    public function la_interfaz_no_accede_al_usuario_del_autor(): void
    {
        $fuente = file_get_contents(resource_path('js/pages/SupportDetail.vue'));

        $this->assertStringContainsString('message.author_label', $fuente);
        $this->assertStringContainsString('attachment.author_label', $fuente);
        $this->assertStringNotContainsString('message.user?.user_name', $fuente);

        // El cliente del ticket también puede faltar: `support_ticket.user_id`
        // es SET NULL desde 2024.
        $this->assertStringContainsString('nombreDelCliente', $fuente);
        $this->assertStringContainsString('Cliente eliminado', $fuente);
    }
}
