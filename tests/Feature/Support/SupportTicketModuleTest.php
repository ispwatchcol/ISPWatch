<?php

namespace Tests\Feature\Support;

use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TicketCatalogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Red de seguridad del módulo de tickets ANTES de reestructurarlo.
 *
 * Hasta ahora el módulo no tenía un solo test, y la Fase 1 va a mover sus tres
 * columnas enum (`status`, `priority`, `category`) a tablas de catálogo con
 * clave foránea — es decir, una migración de datos que toca todas las filas de
 * `support_ticket`. Sin cobertura previa, la única forma de enterarse de una
 * regresión sería en producción.
 *
 * Estos son tests de CARACTERIZACIÓN: afirman lo que el módulo hace HOY, no lo
 * que debería hacer. Dos de ellos fijan defectos conocidos a propósito (van
 * marcados como DEFECTO FIJADO) para que la reestructuración los cambie de
 * forma deliberada y visible, y no por accidente.
 */
class SupportTicketModuleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // El controlador notifica por correo en cada cambio de estado y en cada
        // mensaje no interno. Se interceptan para que la suite no dependa del
        // transporte de correo.
        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        // `code` explícito: CheckStaffProfile identifica al personal por código
        // de rol ('admin'/'staff'), no por id — los ids de rol son por tenant.
        $role = Role::create([
            'name'        => 'Administrador',
            'code'        => 'admin',
            'permissions' => ['*'],
            'tenant_id'   => $this->tenant->id,
        ]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        $this->customer = $this->customerOf($this->tenant, 'Marta', 'Ospina');
    }

    private function customerOf(Tenant $tenant, string $name, string $lastName): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id'   => $user->id,
            'name'      => $name,
            'last_name' => $lastName,
            'status'    => true,
        ]);

        return $user;
    }

    private function ticketOf(Tenant $tenant, User $customer, array $overrides = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'tenant_id' => $tenant->id,
            'user_id'   => $customer->id,
            'subject'   => 'Intermitencia en el servicio',
            'status'    => 'open',
            'priority'  => 'medium',
            'category'  => 'technical',
        ], $overrides));
    }

    // ── Creación ─────────────────────────────────────────────────────────

    #[Test]
    public function crea_un_ticket_con_los_valores_por_defecto_del_modulo(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/support', [
            'subject'     => 'No tiene internet',
            'description' => 'Reporta caída total desde las 8am.',
            'category'    => 'technical',
            'user_id'     => $this->customer->id,
        ]);

        $response->assertCreated();

        $ticket = SupportTicket::find($response->json('ticket.id'));

        // Un ticket nace RADICADO: es donde la Solicitud Maestra abre el ciclo
        // de vida (seccion 7). El estado sale del catalogo (`is_initial`), no de
        // una constante, asi que mover el primer paso del flujo es editar una fila.
        $this->assertSame('radicado', $ticket->status, 'Todo ticket nace radicado.');
        $this->assertSame('medium', $ticket->priority);
        $this->assertSame('technical', $ticket->category);
        $this->assertNull($ticket->resolved_at);
    }

    #[Test]
    public function la_categoria_cae_a_general_cuando_no_se_envia(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/support', [
            'subject' => 'Consulta de facturación',
            'user_id' => $this->customer->id,
        ]);

        $response->assertCreated();
        $this->assertSame('general', SupportTicket::find($response->json('ticket.id'))->category);
    }

    /**
     * DEFECTO FIJADO — `store()` escribe PRIORITY_MEDIUM a fuego y ni siquiera
     * declara `priority` entre las reglas de validación, así que la prioridad
     * enviada al crear se descarta en silencio. Hoy sólo se puede cambiar
     * después, por update. La reestructuración debería aceptarla al crear; al
     * hacerlo, este test debe actualizarse de forma consciente.
     */
    #[Test]
    public function la_prioridad_enviada_al_crear_se_ignora_hoy(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/support', [
            'subject'  => 'Cliente corporativo caído',
            'user_id'  => $this->customer->id,
            'priority' => 'urgent',
        ]);

        $response->assertCreated();

        $this->assertSame(
            'medium',
            SupportTicket::find($response->json('ticket.id'))->priority,
            'Comportamiento actual: la prioridad de creación se ignora. Si esto cambia, es intencional.'
        );
    }

    #[Test]
    public function el_ticket_se_crea_en_el_tenant_del_usuario_autenticado(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/support', [
            'subject' => 'Sin señal',
            'user_id' => $this->customer->id,
        ]);

        $response->assertCreated();

        $this->assertSame(
            $this->tenant->id,
            SupportTicket::find($response->json('ticket.id'))->tenant_id,
            'El tenant se deriva del autenticado, nunca del cuerpo de la petición.'
        );
    }

    #[Test]
    public function no_crea_un_ticket_sin_asunto(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/support', ['user_id' => $this->customer->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject');
    }

    #[Test]
    public function rechaza_una_categoria_fuera_del_catalogo(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/support', [
                'subject'  => 'Algo',
                'user_id'  => $this->customer->id,
                'category' => 'inventada',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    // ── Consulta ─────────────────────────────────────────────────────────

    #[Test]
    public function lista_solo_los_tickets_del_tenant(): void
    {
        $this->ticketOf($this->tenant, $this->customer, ['subject' => 'Propio']);

        $otro = Tenant::factory()->create();
        $this->ticketOf($otro, $this->customerOf($otro, 'Ajeno', 'Ajeno'), ['subject' => 'De otro tenant']);

        $filas = $this->actingAs($this->staff)->getJson('/api/support')->assertOk()->json();

        $this->assertCount(1, $filas);
        $this->assertSame('Propio', $filas[0]['subject']);
    }

    #[Test]
    public function filtra_por_estado_prioridad_y_categoria(): void
    {
        $this->ticketOf($this->tenant, $this->customer, [
            'subject' => 'Urgente abierto', 'status' => 'open', 'priority' => 'urgent', 'category' => 'technical',
        ]);
        $this->ticketOf($this->tenant, $this->customer, [
            'subject' => 'Bajo cerrado', 'status' => 'closed', 'priority' => 'low', 'category' => 'billing',
        ]);

        $porEstado = $this->actingAs($this->staff)->getJson('/api/support?status=open')->assertOk()->json();
        $this->assertCount(1, $porEstado);
        $this->assertSame('Urgente abierto', $porEstado[0]['subject']);

        $porPrioridad = $this->actingAs($this->staff)->getJson('/api/support?priority=low')->assertOk()->json();
        $this->assertCount(1, $porPrioridad);
        $this->assertSame('Bajo cerrado', $porPrioridad[0]['subject']);

        $porCategoria = $this->actingAs($this->staff)->getJson('/api/support?category=billing')->assertOk()->json();
        $this->assertCount(1, $porCategoria);
        $this->assertSame('Bajo cerrado', $porCategoria[0]['subject']);
    }

    #[Test]
    public function consulta_el_detalle_del_ticket_con_sus_mensajes(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        SupportTicketMessage::create([
            'ticket_id'   => $ticket->id,
            'user_id'     => $this->staff->id,
            'message'     => 'Se agenda visita técnica.',
            'is_internal' => false,
        ]);

        $response = $this->actingAs($this->staff)->getJson("/api/support/{$ticket->id}")->assertOk();

        $this->assertSame($ticket->id, $response->json('id'));
        $this->assertSame('open', $response->json('status'));
        $this->assertCount(1, $response->json('messages'));
        $this->assertSame('Se agenda visita técnica.', $response->json('messages.0.message'));
    }

    #[Test]
    public function no_deja_ver_un_ticket_de_otro_tenant(): void
    {
        $otro = Tenant::factory()->create();
        $ajeno = $this->ticketOf($otro, $this->customerOf($otro, 'Ajeno', 'Ajeno'));

        // 404 y no 403: lo produce el global scope de BelongsToTenant, que deja
        // el ticket fuera del universo consultable en vez de negarlo.
        $this->actingAs($this->staff)->getJson("/api/support/{$ajeno->id}")->assertNotFound();
    }

    // ── Cambio de estado ─────────────────────────────────────────────────

    /** Recorre el flujo hasta el estado pedido, paso a paso por la matriz. */
    private function llevarA(SupportTicket $ticket, array $pasos): void
    {
        foreach ($pasos as $estado) {
            $this->actingAs($this->staff)
                ->patchJson("/api/support/{$ticket->id}/status", ['status' => $estado])
                ->assertOk();
        }
    }

    #[Test]
    public function restablecer_el_servicio_sella_resolved_at(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        // No se salta del radicado al restablecimiento: hay que recorrer el
        // flujo. Eso es exactamente lo que este PR vino a imponer.
        $this->llevarA($ticket, [
            'en_clasificacion', 'en_diagnostico_remoto', 'asignado',
            'en_intervencion', 'servicio_restablecido',
        ]);

        $ticket->refresh();

        $this->assertSame('servicio_restablecido', $ticket->status);
        $this->assertNotNull($ticket->resolved_at, 'Restablecer debe sellar la fecha.');
        // Restablecido NO es cerrado: el documento le dedica un recuadro entero.
        $this->assertNull($ticket->closed_at);
    }

    #[Test]
    public function clasificar_no_sella_resolved_at(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'en_clasificacion'])
            ->assertOk();

        $ticket->refresh();

        $this->assertSame('en_clasificacion', $ticket->status);
        $this->assertNull($ticket->resolved_at);
    }

    /**
     * DEFECTO CORREGIDO. Este test afirmaba lo contrario —«hoy se admite
     * cualquier transición, incluida cerrado → abierto»— y dejaba escrito que
     * la Fase 2 debía modelar la reapertura como transición explícita. Es lo
     * que hace el workflow formal, así que el test cambia a conciencia.
     */
    #[Test]
    public function ya_no_se_admite_cualquier_transicion(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer, ['status' => 'closed']);

        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'open'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ticket_transition_not_allowed');

        $this->assertSame('closed', $ticket->refresh()->status);
    }

    /**
     * Lo que antes era un defecto —«resolved_at sobrevive a la reapertura»— es
     * ahora el comportamiento EXIGIDO: «los estados y timestamps se conservan
     * sin sobrescritura» (seccion 19.5 de la Solicitud Maestra).
     *
     * Lo que sí cambió es cómo se llega: volver a intervenir ya no es escribir
     * un estado cualquiera, es una transición que la matriz permite desde
     * `servicio_restablecido` porque la falla puede reaparecer antes de cerrar.
     */
    #[Test]
    public function volver_a_intervenir_no_limpia_resolved_at(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        $this->llevarA($ticket, [
            'en_clasificacion', 'en_diagnostico_remoto', 'asignado',
            'en_intervencion', 'servicio_restablecido',
        ]);

        $resuelto = $ticket->refresh()->resolved_at;
        $this->assertNotNull($resuelto);

        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'en_intervencion'])
            ->assertOk();

        $ticket->refresh();

        $this->assertSame('en_intervencion', $ticket->status);
        $this->assertNotNull($ticket->resolved_at, 'El restablecimiento ocurrió: su fecha es un hecho.');
        $this->assertEquals($resuelto, $ticket->resolved_at, 'Y no se vuelve a sellar.');
    }

    #[Test]
    public function rechaza_un_estado_fuera_del_catalogo(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'archivado'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    // ── Actualización general ────────────────────────────────────────────

    #[Test]
    public function actualiza_prioridad_categoria_y_asignacion_por_put(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", [
                'priority' => 'urgent',
                'category' => 'services',
                'staff_id' => $this->staff->id,
            ])
            ->assertOk();

        $ticket->refresh();

        $this->assertSame('urgent', $ticket->priority);
        $this->assertSame('services', $ticket->category);
        $this->assertSame($this->staff->id, $ticket->staff_id);
    }

    /**
     * DEFECTO CORREGIDO. El `PUT` sellaba `resolved_at` porque movía el estado
     * desde el formulario de edición. Ya no lo mueve: `status` salió de la
     * validación y un cambio de estado es una transición.
     *
     * Se IGNORA en vez de dar 422 porque la pantalla de edición reenvía el
     * formulario entero; rechazar la petición por un campo que el usuario no
     * tocó habría roto el guardado.
     */
    #[Test]
    public function el_put_generico_ya_no_mueve_el_estado(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['status' => 'cerrado', 'subject' => 'Otro asunto'])
            ->assertOk();

        $ticket->refresh();

        // El helper crea el ticket directo en base, en `open`. Lo que importa
        // es que siga donde estaba: el `PUT` no lo movió a `cerrado`.
        $this->assertSame('open', $ticket->status, 'El estado no se mueve por el PUT.');
        $this->assertSame('Otro asunto', $ticket->subject, 'Pero el resto del formulario sí se guarda.');
        $this->assertNull($ticket->resolved_at);
        $this->assertNull($ticket->closed_at);
    }

    #[Test]
    public function rechaza_una_prioridad_fuera_del_catalogo_en_update(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['priority' => 'altisima'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('priority');
    }

    // ── Mensajes ─────────────────────────────────────────────────────────

    #[Test]
    public function agrega_un_mensaje_al_ticket(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/message", [
                'message'     => 'Se reinició la ONU en sitio.',
                'is_internal' => false,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('support_ticket_message', [
            'ticket_id' => $ticket->id,
            'message'   => 'Se reinició la ONU en sitio.',
        ]);
    }

    #[Test]
    public function distingue_la_nota_interna_del_mensaje_al_abonado(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/message", [
                'message'     => 'El abonado no atiende el teléfono.',
                'is_internal' => true,
            ])
            ->assertCreated();

        $mensaje = SupportTicketMessage::where('ticket_id', $ticket->id)->first();

        $this->assertTrue((bool) $mensaje->is_internal);
    }

    #[Test]
    public function no_agrega_un_mensaje_vacio(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        $this->actingAs($this->staff)
            ->postJson("/api/support/{$ticket->id}/message", ['message' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    // ── Estadísticas ─────────────────────────────────────────────────────

    /**
     * ACTUALIZADO EN LA R2 — y el cambio es el esperado, no una regresión.
     *
     * Hasta la R1 este test afirmaba `['Open', 'In progress']`, porque
     * `statistics()` fabricaba la etiqueta con `ucfirst(str_replace('_',' '))`
     * sobre el código: texto en inglés, con la forma que impusiera el código,
     * en una interfaz en español. Ahora la etiqueta sale de `label` del
     * catálogo, así que dice «Abierto» y «En progreso».
     *
     * La respuesta trae además `code` junto a la etiqueta: el frontend colorea
     * por código —que es estable— y muestra la etiqueta —que puede cambiar—.
     */
    #[Test]
    public function las_estadisticas_agrupan_por_estado_y_etiquetan_desde_el_catalogo(): void
    {
        $this->ticketOf($this->tenant, $this->customer, ['status' => 'open', 'priority' => 'high']);
        $this->ticketOf($this->tenant, $this->customer, ['status' => 'open', 'priority' => 'low']);
        $this->ticketOf($this->tenant, $this->customer, ['status' => 'in_progress', 'priority' => 'low']);

        $stats = $this->actingAs($this->staff)->getJson('/api/support/statistics')->assertOk()->json();

        $this->assertSame(3, $stats['total_tickets']);
        $this->assertSame(2, $stats['open_tickets']);
        $this->assertSame(1, $stats['in_progress_tickets']);

        $this->assertEqualsCanonicalizing(
            ['Abierto', 'En progreso'],
            collect($stats['by_status'])->pluck('status')->all(),
            'La etiqueta debe venir del catálogo, no fabricarse desde el código.'
        );

        $this->assertEqualsCanonicalizing(
            ['open', 'in_progress'],
            collect($stats['by_status'])->pluck('code')->all(),
            'El código estable viaja junto a la etiqueta para que el frontend coloree por él.'
        );

        $this->assertEqualsCanonicalizing(
            ['Alta', 'Baja'],
            collect($stats['by_priority'])->pluck('priority')->all()
        );
    }

    /**
     * La etiqueta se puede cambiar sin desplegar: es el punto de tener catálogo.
     * El código no se toca, así que nada más en el sistema se entera.
     */
    #[Test]
    public function reetiquetar_el_catalogo_cambia_lo_que_muestran_las_estadisticas(): void
    {
        $this->ticketOf($this->tenant, $this->customer, ['status' => 'open']);

        DB::table('ticket_status')->where('code', 'open')->update(['label' => 'Recibido']);

        // `TicketCatalogs` cachea por PETICIÓN. En producción eso basta: la
        // edición ocurre en una petición y la siguiente ya ve el cambio. Dentro
        // de un test el contenedor se comparte entre la preparación y la
        // llamada, así que hay que vaciarlo a mano para reproducir el escenario
        // real de «se editó el catálogo y llega una petición nueva».
        app(TicketCatalogs::class)->flush();

        $stats = $this->actingAs($this->staff)->getJson('/api/support/statistics')->assertOk()->json();

        $this->assertSame('Recibido', $stats['by_status'][0]['status']);
        $this->assertSame('open', $stats['by_status'][0]['code'], 'El código NO cambia al reetiquetar.');
    }

    #[Test]
    public function las_estadisticas_solo_cuentan_el_tenant_propio(): void
    {
        $this->ticketOf($this->tenant, $this->customer);

        $otro = Tenant::factory()->create();
        $this->ticketOf($otro, $this->customerOf($otro, 'Ajeno', 'Ajeno'));

        $stats = $this->actingAs($this->staff)->getJson('/api/support/statistics')->assertOk()->json();

        $this->assertSame(1, $stats['total_tickets']);
    }
}
