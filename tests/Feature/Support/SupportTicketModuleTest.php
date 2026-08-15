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

        $this->assertSame('open', $ticket->status, 'Todo ticket nace abierto.');
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

    #[Test]
    public function cambia_el_estado_y_sella_resolved_at_al_resolver(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'resolved'])
            ->assertOk();

        $ticket->refresh();

        $this->assertSame('resolved', $ticket->status);
        $this->assertNotNull($ticket->resolved_at, 'Resolver debe sellar la fecha de resolución.');
    }

    #[Test]
    public function pasar_a_en_progreso_no_sella_resolved_at(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'in_progress'])
            ->assertOk();

        $ticket->refresh();

        $this->assertSame('in_progress', $ticket->status);
        $this->assertNull($ticket->resolved_at);
    }

    /**
     * DEFECTO FIJADO — hoy no existe máquina de estados: `updateStatus()` sólo
     * valida que el valor esté entre los cuatro del enum, así que cerrado →
     * abierto pasa sin objeción. La Fase 2 debe modelar la reapertura como
     * transición explícita; hasta entonces esto documenta la realidad.
     */
    #[Test]
    public function hoy_se_admite_cualquier_transicion_incluida_cerrado_a_abierto(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer, ['status' => 'closed']);

        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'open'])
            ->assertOk();

        $this->assertSame('open', $ticket->refresh()->status);
    }

    /**
     * DEFECTO FIJADO — al reabrir un ticket resuelto, `resolved_at` conserva la
     * fecha anterior porque nada la limpia. Eso sesga el tiempo promedio de
     * resolución que calcula `statistics()`. La Fase 2 lo corrige junto con la
     * máquina de estados; cuando lo haga, este test debe cambiar de forma
     * consciente y no simplemente "arreglarse".
     */
    #[Test]
    public function reabrir_un_ticket_resuelto_no_limpia_resolved_at(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'resolved'])
            ->assertOk();

        $resuelto = $ticket->refresh()->resolved_at;
        $this->assertNotNull($resuelto);

        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'open'])
            ->assertOk();

        $ticket->refresh();

        $this->assertSame('open', $ticket->status);
        $this->assertNotNull(
            $ticket->resolved_at,
            'Comportamiento actual: resolved_at sobrevive a la reapertura. Es un defecto conocido.'
        );
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

    #[Test]
    public function el_update_tambien_sella_resolved_at_al_resolver(): void
    {
        $ticket = $this->ticketOf($this->tenant, $this->customer);

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['status' => 'resolved'])
            ->assertOk();

        $this->assertNotNull($ticket->refresh()->resolved_at);
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
