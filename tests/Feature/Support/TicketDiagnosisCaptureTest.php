<?php

namespace Tests\Feature\Support;

use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TicketCatalogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR #2 · Captura estructurada del diagnóstico.
 *
 * Las cinco columnas existen desde la R1 y el vocabulario desde el PR #1, pero
 * hasta ahora nadie las escribía. Lo que estos tests protegen es que se puedan
 * rellenar SIN romper nada de lo anterior, y que el aislamiento entre ISPs valga
 * también al escribir — no sólo al listar el catálogo.
 *
 * El caso que más importa es el de los tickets antiguos: hay 20 en producción
 * sin diagnóstico, y tienen que seguir cargando y editándose igual que antes.
 */
class TicketDiagnosisCaptureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // El controlador notifica por correo al cambiar de estado.
        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        // `code` explícito: CheckStaffProfile identifica al personal por código
        // de rol, no por id — los ids de rol son por tenant.
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
            'user_id' => $user->id, 'name' => 'Marta', 'last_name' => 'Ospina', 'status' => true,
        ]);

        return $user;
    }

    /** Un ticket ya existente, creado como lo haría el módulo. */
    private function ticket(array $extra = []): SupportTicket
    {
        return SupportTicket::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->customer->id,
            'subject'   => 'Intermitencia en el servicio',
            'status'    => 'open',
            'priority'  => 'medium',
            'category'  => 'technical',
        ] + $extra);
    }

    /** Diagnóstico completo con códigos reales del Anexo A. */
    private function diagnosticoValido(): array
    {
        return [
            'symptom'         => 'S02',
            'suspected_cause' => 'RF',
            'confirmed_cause' => 'CL',
            'solution'        => 'AC07',
            'result'          => 'R02',
        ];
    }

    // ── Creación ─────────────────────────────────────────────────────────

    #[Test]
    public function un_ticket_puede_crearse_sin_diagnostico(): void
    {
        $respuesta = $this->actingAs($this->staff)->postJson('/api/support', [
            'subject' => 'Sin Internet desde anoche',
            'user_id' => $this->customer->id,
        ]);

        $respuesta->assertCreated();

        $fila = DB::table('support_ticket')->where('id', $respuesta->json('ticket.id'))->first();

        foreach (['symptom_id', 'suspected_cause_id', 'confirmed_cause_id', 'solution_id', 'result_id'] as $columna) {
            $this->assertNull($fila->{$columna}, "Un ticket nuevo no debe traer {$columna}.");
        }
    }

    #[Test]
    public function un_ticket_puede_crearse_con_diagnostico_valido(): void
    {
        $respuesta = $this->actingAs($this->staff)->postJson('/api/support', [
            'subject' => 'Corte total',
            'user_id' => $this->customer->id,
        ] + $this->diagnosticoValido());

        $respuesta->assertCreated();

        $diagnostico = $respuesta->json('ticket.diagnosis');

        $this->assertSame('S02', $diagnostico['symptom']['code']);
        $this->assertSame('Servicio intermitente', $diagnostico['symptom']['label']);
        $this->assertSame('RF', $diagnostico['suspected_cause']['code']);
        $this->assertSame('CL', $diagnostico['confirmed_cause']['code']);
        $this->assertSame('AC07', $diagnostico['solution']['code']);
        $this->assertSame('R02', $diagnostico['result']['code']);
    }

    #[Test]
    public function el_diagnostico_se_guarda_como_clave_foranea_no_como_texto(): void
    {
        $respuesta = $this->actingAs($this->staff)->postJson('/api/support', [
            'subject' => 'Corte total',
            'user_id' => $this->customer->id,
        ] + $this->diagnosticoValido());

        $fila = DB::table('support_ticket')->where('id', $respuesta->json('ticket.id'))->first();

        $this->assertSame(
            (int) DB::table('ticket_symptom')->where('code', 'S02')->value('id'),
            (int) $fila->symptom_id,
        );

        // La causa sospechada y la confirmada comparten catálogo pero NO fila.
        $this->assertNotSame((int) $fila->suspected_cause_id, (int) $fila->confirmed_cause_id);
    }

    // ── Edición campo a campo ────────────────────────────────────────────

    #[Test]
    public function cada_campo_del_diagnostico_puede_editarse_por_separado(): void
    {
        $ticket = $this->ticket();

        $casos = [
            'symptom'         => ['S05', 'symptom_id',          'ticket_symptom'],
            'suspected_cause' => ['FO',  'suspected_cause_id',  'ticket_cause'],
            'confirmed_cause' => ['RE',  'confirmed_cause_id',  'ticket_cause'],
            'solution'        => ['AC13', 'solution_id',        'ticket_solution'],
            'result'          => ['R09', 'result_id',           'ticket_result'],
        ];

        foreach ($casos as $campo => [$codigo, $columna, $tabla]) {
            $this->actingAs($this->staff)
                ->putJson("/api/support/{$ticket->id}", [$campo => $codigo])
                ->assertOk();

            $fila = DB::table('support_ticket')->where('id', $ticket->id)->first();

            $this->assertSame(
                (int) DB::table($tabla)->where('code', $codigo)->value('id'),
                (int) $fila->{$columna},
                "Editar `{$campo}` debe escribir `{$columna}`.",
            );
        }
    }

    #[Test]
    public function un_campo_del_diagnostico_puede_borrarse_enviando_null(): void
    {
        $ticket = $this->ticket($this->diagnosticoValido());

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['result' => null])
            ->assertOk();

        $fila = DB::table('support_ticket')->where('id', $ticket->id)->first();

        $this->assertNull($fila->result_id, 'Enviar null debe borrar el resultado.');
        // Los demás no se tocan: se editó uno, no el bloque entero.
        $this->assertNotNull($fila->symptom_id);
    }

    #[Test]
    public function editar_otro_campo_no_borra_el_diagnostico_existente(): void
    {
        $ticket = $this->ticket($this->diagnosticoValido());

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['subject' => 'Asunto corregido'])
            ->assertOk();

        $fila = DB::table('support_ticket')->where('id', $ticket->id)->first();

        $this->assertNotNull($fila->symptom_id, 'Un update parcial no puede vaciar el diagnóstico.');
        $this->assertNotNull($fila->result_id);
    }

    // ── Lectura ──────────────────────────────────────────────────────────

    #[Test]
    public function el_detalle_devuelve_el_diagnostico_con_codigo_y_etiqueta(): void
    {
        $ticket = $this->ticket($this->diagnosticoValido());

        $diagnostico = $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")
            ->assertOk()
            ->json('diagnosis');

        $this->assertSame(
            ['symptom', 'suspected_cause', 'confirmed_cause', 'solution', 'result'],
            array_keys($diagnostico),
        );

        $this->assertSame(['code', 'label'], array_keys($diagnostico['symptom']));
        $this->assertSame('Cambio de CPE', $diagnostico['solution']['label']);
        $this->assertSame('Solucionado en primera visita', $diagnostico['result']['label']);
    }

    #[Test]
    public function un_ticket_sin_diagnostico_devuelve_null_en_cada_campo(): void
    {
        $ticket = $this->ticket();

        $diagnostico = $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")
            ->assertOk()
            ->json('diagnosis');

        foreach ($diagnostico as $campo => $valor) {
            $this->assertNull($valor, "`{$campo}` debe ser null, no un objeto vacío.");
        }
    }

    #[Test]
    public function los_tickets_antiguos_sin_diagnostico_siguen_cargando(): void
    {
        // Escrito por SQL directo para imitar de verdad una fila anterior al
        // PR #2: sin pasar por el modelo ni por sus mutators.
        $id = DB::table('support_ticket')->insertGetId([
            'tenant_id'   => $this->tenant->id,
            'user_id'     => $this->customer->id,
            'subject'     => 'Ticket anterior al PR #2',
            'status_id'   => DB::table('ticket_status')->where('code', 'open')->value('id'),
            'priority_id' => DB::table('ticket_priority')->where('code', 'medium')->value('id'),
            'category_id' => DB::table('ticket_category')->where('code', 'technical')->value('id'),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->actingAs($this->staff)->getJson('/api/support')->assertOk();

        $respuesta = $this->actingAs($this->staff)->getJson("/api/support/{$id}")->assertOk();

        $this->assertNull($respuesta->json('diagnosis.symptom'));
        // Y las claves de la R3 siguen intactas: el PR #2 es aditivo.
        $this->assertSame('open', $respuesta->json('status'));
        $this->assertSame('technical', $respuesta->json('category'));
    }

    // ── Validación ───────────────────────────────────────────────────────

    #[Test]
    public function un_codigo_inexistente_se_rechaza(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['symptom' => 'S99'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('symptom');
    }

    #[Test]
    public function un_codigo_del_catalogo_equivocado_se_rechaza(): void
    {
        $ticket = $this->ticket();

        // `RF` existe, pero es una familia de CAUSA, no un síntoma.
        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['symptom' => 'RF'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('symptom');

        // `S01` existe, pero es un síntoma, no una acción ni un resultado.
        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['solution' => 'S01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('solution');

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['result' => 'AC01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('result');
    }

    #[Test]
    public function no_se_puede_asignar_una_subcausa_inventada(): void
    {
        $ticket = $this->ticket();

        // El Anexo A describe las subcausas en prosa y NO les da código. Nadie
        // debe poder colar un `RF01` por la puerta de atrás.
        foreach (['RF01', 'FO03', 'CL08'] as $inventado) {
            $this->actingAs($this->staff)
                ->putJson("/api/support/{$ticket->id}", ['confirmed_cause' => $inventado])
                ->assertStatus(422)
                ->assertJsonValidationErrors('confirmed_cause');
        }

        $this->assertSame(
            7,
            DB::table('ticket_cause')->whereNull('tenant_id')->count(),
            'El catálogo de causas debe seguir teniendo sólo las 7 familias.',
        );
    }

    #[Test]
    public function una_fila_retirada_deja_de_aceptarse(): void
    {
        $ticket = $this->ticket();

        DB::table('ticket_symptom')->where('code', 'S16')->update(['valid_until' => now()->subDay()]);
        app(TicketCatalogs::class)->flush();

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['symptom' => 'S16'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('symptom');
    }

    #[Test]
    public function el_mensaje_de_error_esta_en_espanol(): void
    {
        $ticket = $this->ticket();

        $error = $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['symptom' => 'NO_EXISTE'])
            ->assertStatus(422)
            ->json('errors.symptom.0');

        $this->assertSame('El síntoma no pertenece al catálogo vigente.', $error);
    }

    // ── Aislamiento entre tenant y plataforma ────────────────────────────

    #[Test]
    public function el_vocabulario_privado_de_otro_isp_no_puede_asignarse(): void
    {
        $otro = Tenant::factory()->create();

        DB::table('ticket_symptom')->insert([
            'tenant_id' => $otro->id,
            'code' => 'X01', 'label' => 'Síntoma privado del otro ISP',
            'weight' => 999, 'valid_from' => now(), 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(TicketCatalogs::class)->flush();

        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['symptom' => 'X01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('symptom');

        $this->assertNull(
            DB::table('support_ticket')->where('id', $ticket->id)->value('symptom_id'),
        );
    }

    #[Test]
    public function el_vocabulario_propio_del_isp_si_puede_asignarse(): void
    {
        DB::table('ticket_symptom')->insert([
            'tenant_id' => $this->tenant->id,
            'code' => 'Z01', 'label' => 'Síntoma propio de este ISP',
            'weight' => 999, 'valid_from' => now(), 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(TicketCatalogs::class)->flush();

        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['symptom' => 'Z01'])
            ->assertOk();

        $this->assertSame(
            (int) DB::table('ticket_symptom')->where('code', 'Z01')->value('id'),
            (int) DB::table('support_ticket')->where('id', $ticket->id)->value('symptom_id'),
        );
    }

    #[Test]
    public function un_codigo_repetido_entre_isps_resuelve_al_del_propio(): void
    {
        $otro = Tenant::factory()->create();

        // Los índices parciales de la R1 permiten a propósito que dos ISP usen
        // el mismo código. Resolver sin tenant devolvería el primero que saliera
        // de la consulta — que puede ser el ajeno.
        foreach ([$otro->id, $this->tenant->id] as $duenio) {
            DB::table('ticket_solution')->insert([
                'tenant_id' => $duenio,
                'code' => 'AC99', 'label' => 'Acción propia del ISP ' . $duenio,
                'weight' => 999, 'valid_from' => now(), 'revision' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        app(TicketCatalogs::class)->flush();

        $ticket = $this->ticket();

        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['solution' => 'AC99'])
            ->assertOk();

        $esperado = (int) DB::table('ticket_solution')
            ->where('code', 'AC99')->where('tenant_id', $this->tenant->id)->value('id');

        $this->assertSame(
            $esperado,
            (int) DB::table('support_ticket')->where('id', $ticket->id)->value('solution_id'),
            'Debe resolver a la fila del propio ISP, no a la del otro.',
        );
    }

    // ── Autorización ─────────────────────────────────────────────────────

    #[Test]
    public function sin_sesion_no_se_puede_diagnosticar(): void
    {
        $ticket = $this->ticket();

        $this->putJson("/api/support/{$ticket->id}", ['symptom' => 'S01'])
            ->assertUnauthorized();
    }

    #[Test]
    public function un_rol_sin_permiso_de_soporte_no_puede_diagnosticar(): void
    {
        $rolSinPermiso = Role::create([
            'name' => 'Cartera', 'code' => 'billing',
            'permissions' => ['view_billing'], 'tenant_id' => $this->tenant->id,
        ]);

        $usuario = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $rolSinPermiso->id,
        ]);

        $ticket = $this->ticket();

        $this->actingAs($usuario)
            ->putJson("/api/support/{$ticket->id}", ['symptom' => 'S01'])
            ->assertForbidden();
    }

    #[Test]
    public function un_ticket_de_otro_isp_no_se_puede_diagnosticar(): void
    {
        $otro = Tenant::factory()->create();
        $ajeno = $this->clienteDe($otro);

        $ticket = SupportTicket::create([
            'tenant_id' => $otro->id,
            'user_id'   => $ajeno->id,
            'subject'   => 'Ticket de otro ISP',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);

        // El scope global de BelongsToTenant lo deja fuera: 404, no 403.
        $this->actingAs($this->staff)
            ->putJson("/api/support/{$ticket->id}", ['symptom' => 'S01'])
            ->assertNotFound();
    }

    // ── No regresión de lo ya entregado ──────────────────────────────────

    #[Test]
    public function la_respuesta_conserva_las_claves_de_la_r3(): void
    {
        $ticket = $this->ticket($this->diagnosticoValido());

        $cuerpo = $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")->assertOk()->json();

        foreach (['status', 'priority', 'category', 'status_label', 'priority_label', 'category_label'] as $clave) {
            $this->assertArrayHasKey($clave, $cuerpo, "El PR #2 no puede quitar `{$clave}`.");
        }

        $this->assertSame('open', $cuerpo['status']);
        $this->assertSame('Abierto', $cuerpo['status_label']);
    }

    #[Test]
    public function las_columnas_enum_de_la_r3_no_reaparecen(): void
    {
        $this->ticket($this->diagnosticoValido());

        foreach (['status', 'priority', 'category'] as $columna) {
            $this->assertFalse(
                \Schema::hasColumn('support_ticket', $columna),
                "El PR #2 no debe reintroducir la columna `{$columna}`.",
            );
        }
    }

    // ── Interfaz ─────────────────────────────────────────────────────────
    //
    // El proyecto no tiene runner de JavaScript, así que la interfaz se verifica
    // leyendo el fuente, como ya hace `VersionConsistencyTest` con Settings.vue.
    // No sustituye a una prueba de navegador —no comprueba que se vea bien— pero
    // sí atrapa lo que de verdad se rompe en silencio: que una pantalla deje de
    // enviar el diagnóstico, o que alguien vuelva a escribir el vocabulario a
    // mano en vez de leerlo del catálogo.

    #[Test]
    public function las_tres_pantallas_usan_el_mismo_componente_de_diagnostico(): void
    {
        foreach (['SupportCreate', 'SupportEdit'] as $pantalla) {
            $fuente = file_get_contents(resource_path("js/pages/{$pantalla}.vue"));

            $this->assertStringContainsString(
                'TicketDiagnosisFields',
                $fuente,
                "{$pantalla}.vue debe reutilizar el componente, no repetir los cinco selects.",
            );
        }

        $detalle = file_get_contents(resource_path('js/pages/SupportDetail.vue'));
        $this->assertStringContainsString('camposDeDiagnostico', $detalle);
        $this->assertStringContainsString('Sin diagnóstico registrado', $detalle);
    }

    #[Test]
    public function la_interfaz_no_escribe_el_vocabulario_a_mano(): void
    {
        // Este módulo ya tuvo los mapas de etiquetas duplicados en cinco
        // componentes; el catálogo existe justamente para que no vuelva a pasar.
        $fuentes = [
            resource_path('js/components/TicketDiagnosisFields.vue'),
            resource_path('js/pages/SupportEdit.vue'),
            resource_path('js/pages/SupportCreate.vue'),
            resource_path('js/pages/SupportDetail.vue'),
        ];

        foreach ($fuentes as $ruta) {
            $fuente = file_get_contents($ruta);

            foreach (['Sin Internet', 'Radiofrecuencia', 'Cambio de ONU', 'Solucionado remotamente'] as $etiqueta) {
                $this->assertStringNotContainsString(
                    $etiqueta,
                    $fuente,
                    basename($ruta) . " no debe traer la etiqueta «{$etiqueta}» escrita a mano.",
                );
            }

            // Tampoco los códigos: son datos, no literales de la interfaz.
            $this->assertDoesNotMatchRegularExpression(
                "/'(S0[1-9]|S1[0-6]|AC[01][0-9]|R0[1-9])'/",
                $fuente,
                basename($ruta) . ' no debe traer códigos del Anexo A en duro.',
            );
        }
    }

    #[Test]
    public function la_pantalla_de_edicion_envia_el_diagnostico_y_precarga_el_existente(): void
    {
        $fuente = file_get_contents(resource_path('js/pages/SupportEdit.vue'));

        $this->assertStringContainsString(
            '...diagnostico.value',
            $fuente,
            'El PUT debe incluir el diagnóstico junto al resto del formulario.',
        );

        $this->assertStringContainsString(
            'd.symptom?.code',
            $fuente,
            'El formulario debe precargar el diagnóstico que ya tiene el ticket.',
        );
    }

    #[Test]
    public function el_componente_cubre_los_estados_de_carga_vacio_y_error(): void
    {
        $fuente = file_get_contents(resource_path('js/components/TicketDiagnosisFields.vue'));

        $this->assertStringContainsString('Cargando catálogos de diagnóstico', $fuente);
        $this->assertStringContainsString('No se pudieron cargar los catálogos', $fuente);
        $this->assertStringContainsString('sinVocabulario', $fuente);
        $this->assertStringContainsString('— Sin definir —', $fuente);

        // Las subcausas se muestran como REFERENCIA, nunca como opción.
        $this->assertStringContainsString('Subcausas de referencia', $fuente);
        $this->assertStringNotContainsString('<option v-for="sub', $fuente);
    }

    #[Test]
    public function el_endpoint_de_catalogos_alimenta_los_cinco_desplegables(): void
    {
        // La interfaz lee `symptoms`, `causes`, `actions` y `results` del mismo
        // endpoint que el PR #1 amplió. Si esa respuesta cambiara de forma, los
        // desplegables quedarían vacíos sin ningún error.
        $catalogos = $this->actingAs($this->staff)
            ->getJson('/api/catalogs/ticket')->assertOk()->json();

        $this->assertCount(16, $catalogos['symptoms']);
        $this->assertCount(7, $catalogos['causes']);
        $this->assertCount(20, $catalogos['actions']);
        $this->assertCount(15, $catalogos['results']);

        // Las familias traen el texto de subcausas que la pantalla muestra
        // debajo del desplegable.
        $rf = collect($catalogos['causes'])->firstWhere('code', 'RF');
        $this->assertStringContainsString('interferencia', $rf['description']);
    }

    #[Test]
    public function el_listado_sigue_respondiendo_con_diagnostico_incluido(): void
    {
        $this->ticket($this->diagnosticoValido());
        $this->ticket();

        $tickets = $this->actingAs($this->staff)->getJson('/api/support')->assertOk()->json();

        $this->assertCount(2, $tickets);

        foreach ($tickets as $ticket) {
            $this->assertArrayHasKey('diagnosis', $ticket);
        }
    }
}
