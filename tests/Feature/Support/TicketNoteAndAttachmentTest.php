<?php

namespace Tests\Feature\Support;

use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
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
 * Endurecimiento posterior al PR #2: notas de bitácora y adjuntos.
 *
 * El humo en producción sacó dos fallos que no tenían nada que ver con el
 * diagnóstico y que llevaban ahí desde antes:
 *
 *   A. Guardar una nota devolvía 422. La interfaz mandaba el AUTOR desde el
 *      cliente (`user_id`), leído de `localStorage`, donde la sesión sólo está
 *      si se marcó «recordarme». Sin eso caía a `user_id: 1`, que no existe, y
 *      `exists:users,id` rechazaba la petición.
 *
 *   B. La vista previa de una imagen salía rota. Los adjuntos se guardaban en
 *      el disco local y se servían por `asset('storage/…')`, que en App Platform
 *      no funciona: no hay `storage:link` en el despliegue y el sistema de
 *      archivos es efímero y por instancia.
 *
 * Los dos tenían además el mismo problema de fondo: se confiaba en el cliente
 * para algo que decide el servidor —quién firma la nota, quién puede ver el
 * archivo—.
 */
class TicketNoteAndAttachmentTest extends TestCase
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

        $role = Role::create([
            'name' => 'Administrador', 'code' => 'admin',
            'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $role->id,
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

    // ═════════════════════════════════════════════════════════════════════
    // A · Notas de la bitácora
    // ═════════════════════════════════════════════════════════════════════

    #[Test]
    public function guardar_una_nota_con_el_payload_real_de_la_interfaz_funciona(): void
    {
        $ticket = $this->ticket();

        // REPRODUCCIÓN DEL 422 DE PRODUCCIÓN.
        //
        // SupportDetail.vue mandaba el autor desde el cliente, leyéndolo de
        // `localStorage.userData`. Pero el store de sesión sólo escribe ahí si se
        // marcó «recordarme»; si no, la sesión vive en `sessionStorage`. Sin ese
        // dato, `currentUserId` caía al literal `1` — y en producción el usuario
        // 1 no existe, así que `exists:users,id` devolvía 422.
        //
        // El id se calcula para que NO exista, que es la condición real. Ponerlo
        // a 1 a secas haría pasar el test por casualidad: con `RefreshDatabase`
        // los ids empiezan en 1 y coincidiría con el propio staff.
        $idInexistente = ((int) User::max('id')) + 1000;

        $respuesta = $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/message", [
            'message'     => 'Se revisó la ONU en sitio; potencia dentro de rango.',
            'is_internal' => true,
            'user_id'     => $idInexistente,
        ]);

        $respuesta->assertCreated();

        $nota = SupportTicketMessage::where('ticket_id', $ticket->id)->first();

        $this->assertNotNull($nota, 'La nota debe guardarse.');
        $this->assertSame(
            $this->staff->id,
            (int) $nota->user_id,
            'El autor sale de la SESIÓN, nunca del `user_id` que mande el cliente.',
        );
    }

    #[Test]
    public function el_cliente_no_puede_firmar_una_nota_en_nombre_de_otro(): void
    {
        $otroUsuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/message", [
            'message' => 'Nota con autoría falsificada',
            'user_id' => $otroUsuario->id,
        ])->assertCreated();

        $this->assertSame(
            $this->staff->id,
            (int) SupportTicketMessage::where('ticket_id', $ticket->id)->value('user_id'),
            'Suplantar al autor por payload no puede funcionar.',
        );
    }

    #[Test]
    public function una_nota_sin_contenido_se_rechaza_con_mensaje_claro(): void
    {
        $ticket = $this->ticket();

        $respuesta = $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/message", ['message' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');

        $this->assertSame(
            'La nota no puede estar vacía.',
            $respuesta->json('errors.message.0'),
        );
    }

    #[Test]
    public function la_nota_respeta_is_internal(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/message", [
            'message' => 'Nota interna de la bitácora', 'is_internal' => true,
        ])->assertCreated();

        $this->assertTrue((bool) SupportTicketMessage::where('ticket_id', $ticket->id)->value('is_internal'));

        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/message", [
            'message' => 'Respuesta visible para el cliente', 'is_internal' => false,
        ])->assertCreated();

        $this->assertFalse(
            (bool) SupportTicketMessage::where('ticket_id', $ticket->id)
                ->orderByDesc('id')->value('is_internal'),
        );
    }

    #[Test]
    public function la_nota_se_asocia_al_ticket_correcto(): void
    {
        $uno = $this->ticket();
        $dos = $this->ticket();

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$dos->id}/message", ['message' => 'Va al segundo'])
            ->assertCreated();

        $this->assertSame(0, SupportTicketMessage::where('ticket_id', $uno->id)->count());
        $this->assertSame(1, SupportTicketMessage::where('ticket_id', $dos->id)->count());
    }

    #[Test]
    public function reenviar_la_misma_nota_por_accidente_no_la_duplica(): void
    {
        $ticket = $this->ticket();
        $cuerpo = ['message' => 'Se reinició el CPE y volvió el servicio.', 'is_internal' => true];

        $primera = $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/message", $cuerpo);
        $primera->assertCreated();

        // Doble clic, o el usuario que reintenta al ver la interfaz colgada.
        $segunda = $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/message", $cuerpo);
        $segunda->assertSuccessful();

        $this->assertSame(
            1,
            SupportTicketMessage::where('ticket_id', $ticket->id)->count(),
            'Un reenvío accidental no debe dejar dos notas idénticas.',
        );

        $this->assertSame(
            $primera->json('ticket_message.id'),
            $segunda->json('ticket_message.id'),
            'El reenvío debe devolver la nota que ya existía.',
        );
    }

    #[Test]
    public function una_nota_distinta_si_se_guarda_aunque_sea_seguida(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/message", ['message' => 'Primera observación'])
            ->assertCreated();

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/message", ['message' => 'Segunda observación'])
            ->assertCreated();

        $this->assertSame(2, SupportTicketMessage::where('ticket_id', $ticket->id)->count());
    }

    #[Test]
    public function no_se_puede_anotar_en_el_ticket_de_otro_isp(): void
    {
        $otro = Tenant::factory()->create();
        $ajeno = $this->ticket($otro, $this->clienteDe($otro));

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ajeno->id}/message", ['message' => 'Intruso'])
            ->assertNotFound();

        $this->assertSame(0, SupportTicketMessage::where('ticket_id', $ajeno->id)->count());
    }

    #[Test]
    public function sin_sesion_no_se_puede_anotar(): void
    {
        $ticket = $this->ticket();

        $this->postJson("/api/support/{$ticket->id}/message", ['message' => 'Anónimo'])
            ->assertUnauthorized();
    }

    #[Test]
    public function la_nota_se_lee_en_el_detalle_del_ticket(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->postJson("/api/support/{$ticket->id}/message", [
            'message' => 'Se cambió el patchcord de la ONU.', 'is_internal' => true,
        ])->assertCreated();

        $notas = $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")->assertOk()->json('messages');

        $this->assertCount(1, $notas);
        $this->assertSame('Se cambió el patchcord de la ONU.', $notas[0]['message']);
        $this->assertSame($this->staff->id, (int) $notas[0]['user_id']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // B · Adjuntos
    // ═════════════════════════════════════════════════════════════════════

    private function adjuntar(SupportTicket $ticket, string $nombre = 'sp1.jpg'): SupportTicketAttachment
    {
        $this->actingAs($this->staff)->post("/api/support/{$ticket->id}", [
            '_method'       => 'PUT',
            'attachments'   => [UploadedFile::fake()->image($nombre, 40, 30)],
        ])->assertOk();

        return SupportTicketAttachment::where('ticket_id', $ticket->id)->firstOrFail();
    }

    #[Test]
    public function un_adjunto_se_guarda_en_el_disco_remoto_no_en_el_efimero(): void
    {
        $ticket = $this->ticket();
        $adjunto = $this->adjuntar($ticket);

        // App Platform reemplaza el contenedor en cada despliegue: lo que quede
        // en el disco local desaparece. Por eso los adjuntos van al mismo disco
        // que ya usan los documentos de cliente.
        Storage::disk('s3')->assertExists($adjunto->file_path);
        $this->assertSame('sp1.jpg', $adjunto->file_name);
    }

    #[Test]
    public function la_url_del_adjunto_no_es_una_ruta_publica(): void
    {
        $ticket = $this->ticket();
        $adjunto = $this->adjuntar($ticket);

        $url = $adjunto->fresh()->url;

        $this->assertStringNotContainsString(
            '/storage/',
            $url,
            'Servir adjuntos por `asset(storage/…)` los deja legibles sin sesión.',
        );
        $this->assertStringContainsString("/support/{$ticket->id}/attachments/{$adjunto->id}", $url);
    }

    #[Test]
    public function un_usuario_autorizado_previsualiza_la_imagen_con_su_mime(): void
    {
        $ticket = $this->ticket();
        $adjunto = $this->adjuntar($ticket);

        $respuesta = $this->actingAs($this->staff)
            ->get("/api/support/{$ticket->id}/attachments/{$adjunto->id}");

        $respuesta->assertOk();
        $this->assertSame('image/jpeg', $respuesta->headers->get('Content-Type'));
        $this->assertStringStartsWith(
            'inline',
            (string) $respuesta->headers->get('Content-Disposition'),
            'La vista previa no puede forzar descarga: el <img> mostraría un icono roto.',
        );
    }

    #[Test]
    public function un_usuario_autorizado_descarga_el_adjunto(): void
    {
        $ticket = $this->ticket();
        $adjunto = $this->adjuntar($ticket);

        $respuesta = $this->actingAs($this->staff)
            ->get("/api/support/{$ticket->id}/attachments/{$adjunto->id}/download");

        $respuesta->assertOk();
        $this->assertStringStartsWith(
            'attachment',
            (string) $respuesta->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString('sp1.jpg', (string) $respuesta->headers->get('Content-Disposition'));
    }

    #[Test]
    public function sin_sesion_no_se_ve_un_adjunto(): void
    {
        $ticket = $this->ticket();
        $adjunto = $this->adjuntar($ticket);

        // Subir el archivo exige sesión, así que hay que soltarla antes de
        // probar el acceso anónimo: `actingAs` sigue vigente el resto del test.
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/support/{$ticket->id}/attachments/{$adjunto->id}")
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();

        $this->getJson("/api/support/{$ticket->id}/attachments/{$adjunto->id}/download")
            ->assertUnauthorized();
    }

    #[Test]
    public function no_se_puede_ver_el_adjunto_de_otro_isp(): void
    {
        $otro = Tenant::factory()->create();
        $ajeno = $this->ticket($otro, $this->clienteDe($otro));

        $rolAjeno = Role::create([
            'name' => 'Administrador', 'code' => 'admin',
            'permissions' => ['*'], 'tenant_id' => $otro->id,
        ]);
        $staffAjeno = User::factory()->create(['tenant_id' => $otro->id, 'role_id' => $rolAjeno->id]);

        $this->actingAs($staffAjeno)->post("/api/support/{$ajeno->id}", [
            '_method' => 'PUT',
            'attachments' => [UploadedFile::fake()->image('privado.jpg')],
        ])->assertOk();

        $adjuntoAjeno = SupportTicketAttachment::where('ticket_id', $ajeno->id)->firstOrFail();

        // El scope de tenant deja el ticket fuera de alcance: 404, no 403.
        $this->actingAs($this->staff)
            ->getJson("/api/support/{$ajeno->id}/attachments/{$adjuntoAjeno->id}")
            ->assertNotFound();
    }

    #[Test]
    public function un_adjunto_de_otro_ticket_no_se_sirve_aunque_el_ticket_sea_propio(): void
    {
        $mio = $this->ticket();
        $otroMio = $this->ticket();

        $adjunto = $this->adjuntar($otroMio);

        // Adivinar un id de adjunto y colgarlo de un ticket al que sí se tiene
        // acceso no debe servir el archivo.
        $this->actingAs($this->staff)
            ->getJson("/api/support/{$mio->id}/attachments/{$adjunto->id}")
            ->assertNotFound();
    }

    #[Test]
    public function un_adjunto_inexistente_devuelve_404(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}/attachments/999999")
            ->assertNotFound();
    }

    #[Test]
    public function un_archivo_perdido_del_disco_devuelve_404_y_no_una_imagen_rota(): void
    {
        $ticket = $this->ticket();
        $adjunto = $this->adjuntar($ticket);

        // Es lo que pasó en producción: la fila seguía en la base y el archivo
        // se había ido con el contenedor.
        Storage::disk('s3')->delete($adjunto->file_path);

        $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}/attachments/{$adjunto->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'El archivo adjunto ya no está disponible.');
    }

    #[Test]
    public function un_archivo_no_visualizable_se_entrega_como_descarga(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)->post("/api/support/{$ticket->id}", [
            '_method' => 'PUT',
            'attachments' => [UploadedFile::fake()->create('informe.docx', 12, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
        ])->assertOk();

        $adjunto = SupportTicketAttachment::where('ticket_id', $ticket->id)->firstOrFail();

        $respuesta = $this->actingAs($this->staff)
            ->get("/api/support/{$ticket->id}/attachments/{$adjunto->id}");

        $respuesta->assertOk();
        $this->assertStringStartsWith(
            'attachment',
            (string) $respuesta->headers->get('Content-Disposition'),
            'Lo que el navegador no puede pintar se ofrece como descarga, no en línea.',
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // C · Humo de extremo a extremo
    // ═════════════════════════════════════════════════════════════════════

    #[Test]
    public function humo_completo_diagnostico_nota_y_adjunto(): void
    {
        // 1 · Crear el ticket con diagnóstico
        $creado = $this->actingAs($this->staff)->postJson('/api/support', [
            'subject' => 'Intermitencia en el servicio',
            'user_id' => $this->customer->id,
            'symptom' => 'S02', 'suspected_cause' => 'RF',
            'confirmed_cause' => 'NF', 'solution' => 'AC02', 'result' => 'R01',
        ])->assertCreated();

        $id = $creado->json('ticket.id');

        // 2 · Guardar una nota
        $this->actingAs($this->staff)->postJson("/api/support/{$id}/message", [
            'message' => 'Se reinició el equipo y quedó estable.', 'is_internal' => true,
        ])->assertCreated();

        // 3 · Adjuntar una imagen
        $this->actingAs($this->staff)->post("/api/support/{$id}", [
            '_method' => 'PUT',
            'attachments' => [UploadedFile::fake()->image('sp1.jpg', 40, 30)],
        ])->assertOk();

        $adjunto = SupportTicketAttachment::where('ticket_id', $id)->firstOrFail();

        // 4 · Previsualizar y descargar
        $this->actingAs($this->staff)
            ->get("/api/support/{$id}/attachments/{$adjunto->id}")->assertOk();
        $this->actingAs($this->staff)
            ->get("/api/support/{$id}/attachments/{$adjunto->id}/download")->assertOk();

        // 5 · Reabrir el ticket: el diagnóstico, la nota y el adjunto siguen ahí
        $detalle = $this->actingAs($this->staff)->getJson("/api/support/{$id}")->assertOk()->json();

        $this->assertSame('S02', $detalle['diagnosis']['symptom']['code']);
        $this->assertSame('Sin falla confirmada', $detalle['diagnosis']['confirmed_cause']['label']);
        $this->assertCount(1, $detalle['messages']);
        $this->assertCount(1, $detalle['attachments']);
        $this->assertSame('open', $detalle['status']);
    }

    #[Test]
    public function un_ticket_anterior_sin_diagnostico_sigue_admitiendo_notas_y_adjuntos(): void
    {
        // Fila escrita por SQL directo, como las que ya existen en producción.
        $id = DB::table('support_ticket')->insertGetId([
            'tenant_id'   => $this->tenant->id,
            'user_id'     => $this->customer->id,
            'subject'     => 'Ticket anterior al PR #2',
            'status_id'   => DB::table('ticket_status')->where('code', 'open')->value('id'),
            'priority_id' => DB::table('ticket_priority')->where('code', 'medium')->value('id'),
            'category_id' => DB::table('ticket_category')->where('code', 'technical')->value('id'),
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$id}/message", ['message' => 'Nota sobre un ticket antiguo'])
            ->assertCreated();

        $this->actingAs($this->staff)->post("/api/support/{$id}", [
            '_method' => 'PUT',
            'attachments' => [UploadedFile::fake()->image('evidencia.png')],
        ])->assertOk();

        $detalle = $this->actingAs($this->staff)->getJson("/api/support/{$id}")->assertOk()->json();

        $this->assertNull($detalle['diagnosis']['symptom']);
        $this->assertCount(1, $detalle['messages']);
        $this->assertCount(1, $detalle['attachments']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Interfaz
    // ═════════════════════════════════════════════════════════════════════
    //
    // El proyecto no tiene runner de JavaScript; la interfaz se verifica sobre
    // el fuente, como ya hace `VersionConsistencyTest`.

    #[Test]
    public function la_interfaz_ya_no_manda_el_autor_de_la_nota(): void
    {
        $servicio = file_get_contents(resource_path('js/services/api/support.js'));
        $pantalla = file_get_contents(resource_path('js/pages/SupportDetail.vue'));

        $this->assertStringNotContainsString(
            'user_id: userId',
            $servicio,
            'El autor no puede viajar en el cuerpo de la petición.',
        );

        $this->assertStringNotContainsString(
            "localStorage.getItem('userData')",
            $pantalla,
            'Leer la sesión de localStorage a mano es lo que provocaba el 422.',
        );
    }

    #[Test]
    public function la_interfaz_muestra_el_mensaje_de_validacion_del_backend(): void
    {
        $fuente = file_get_contents(resource_path('js/pages/SupportDetail.vue'));

        $this->assertStringContainsString(
            'err.response?.data?.errors?.message?.[0]',
            $fuente,
            'El aviso debe decir POR QUÉ se rechazó la nota, no un texto genérico.',
        );
    }

    #[Test]
    public function la_interfaz_separa_vista_previa_de_descarga(): void
    {
        $fuente = file_get_contents(resource_path('js/pages/SupportDetail.vue'));

        $this->assertStringContainsString('attachment.download_url', $fuente);
        $this->assertStringContainsString('lightboxError', $fuente);
        $this->assertStringContainsString('No se pudo cargar la vista previa', $fuente);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Contrato de socios
    // ═════════════════════════════════════════════════════════════════════

    #[Test]
    public function una_sesion_del_panel_en_una_url_de_socios_devuelve_401_no_500(): void
    {
        // Un empleado con sesión abierta que pegue una URL de `/v1/partner` en el
        // navegador manda la cookie. `currentAccessToken()` devuelve entonces un
        // `TransientToken`, que no es una fila y no implementa `getKey()`: el
        // limitador de peticiones petaba con un Error fatal y salía un 500.
        $this->actingAs($this->staff)
            ->getJson('/api/v1/partner/tickets')
            ->assertUnauthorized();
    }

    #[Test]
    public function el_contrato_de_socios_no_expone_notas_ni_adjuntos(): void
    {
        $cliente = \App\Models\ApiClient::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Integrador', 'is_active' => true,
        ]);
        $token = $cliente->createToken('contrato', ['read:support']);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        $ticket = $this->ticket();
        $this->adjuntar($ticket);
        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/message", ['message' => 'Nota interna']);

        // La sesión del panel no puede seguir viva: el guard `api_key` tiene que
        // resolver la llave, no la cookie.
        $this->app['auth']->forgetGuards();

        $fila = $this->getJson('/api/v1/partner/tickets', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->assertOk()->json('data.0');

        foreach (['attachments', 'messages', 'diagnosis', 'notes'] as $clave) {
            $this->assertArrayNotHasKey($clave, $fila, "`{$clave}` no forma parte del contrato de socios.");
        }
    }
}
