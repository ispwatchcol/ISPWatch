<?php

namespace Tests\Feature\Support;

use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TicketCatalogs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR #1 · Vocabulario de diagnóstico del Anexo A.
 *
 * Lo que estos tests protegen es el CONTRATO con el cliente: los códigos y las
 * etiquetas se transcribieron de
 * `docs/cliente/CNO/V1_1/Solicitud_Maestra_ISPwash_CNO_V1_1.docx`, Anexo A, y
 * cualquier desviación —una tilde, un plural, un código de más— rompe el mapeo
 * que el integrador construirá encima.
 *
 * Por eso se compara el juego COMPLETO de códigos y etiquetas, no una muestra:
 * un catálogo al que le falte `S16` pasa cualquier prueba de bulto.
 */
class TicketDiagnosticCatalogSeedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create([
            'name' => 'Administrador', 'code' => 'admin',
            'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $role->id,
        ]);
    }

    private function migracion(): Migration
    {
        return require database_path(
            'migrations/2026_08_21_000001_seed_ticket_diagnostic_catalogs.php'
        );
    }

    /** Sólo las filas de plataforma, que son las que siembra la migración. */
    private function codigosDePlataforma(string $tabla, bool $conTenant = true): array
    {
        $q = DB::table($tabla);

        if ($conTenant) {
            $q->whereNull('tenant_id');
        }

        return $q->orderBy('weight')->pluck('code')->all();
    }

    // ── Conteo y códigos exactos ─────────────────────────────────────────

    #[Test]
    public function siembra_los_conteos_exactos_del_anexo_a(): void
    {
        $this->assertCount(16, $this->codigosDePlataforma(TicketCatalogs::SYMPTOM), 'A.1 son 16 síntomas.');
        $this->assertCount(7, $this->codigosDePlataforma(TicketCatalogs::CAUSE), 'A.2 son 7 familias de causa.');
        $this->assertCount(20, $this->codigosDePlataforma(TicketCatalogs::SOLUTION), 'A.3 son 20 acciones.');
        $this->assertCount(15, $this->codigosDePlataforma(TicketCatalogs::RESULT, false), 'A.4 son 15 resultados.');
    }

    #[Test]
    public function los_codigos_de_sintomas_son_exactamente_s01_a_s16(): void
    {
        $esperado = array_map(fn ($i) => sprintf('S%02d', $i), range(1, 16));

        $this->assertSame($esperado, $this->codigosDePlataforma(TicketCatalogs::SYMPTOM));
    }

    #[Test]
    public function los_codigos_de_acciones_son_exactamente_ac01_a_ac20(): void
    {
        $esperado = array_map(fn ($i) => sprintf('AC%02d', $i), range(1, 20));

        $this->assertSame($esperado, $this->codigosDePlataforma(TicketCatalogs::SOLUTION));
    }

    #[Test]
    public function los_codigos_de_resultados_son_exactamente_r01_a_r15(): void
    {
        $esperado = array_map(fn ($i) => sprintf('R%02d', $i), range(1, 15));

        $this->assertSame($esperado, $this->codigosDePlataforma(TicketCatalogs::RESULT, false));
    }

    #[Test]
    public function las_familias_de_causa_son_las_siete_del_anexo(): void
    {
        $this->assertSame(
            ['RF', 'FO', 'CL', 'AA', 'RE', 'EX', 'NF'],
            $this->codigosDePlataforma(TicketCatalogs::CAUSE),
            'El orden es el del Anexo A, fijado por `weight`.'
        );
    }

    // ── Etiquetas oficiales ──────────────────────────────────────────────

    #[Test]
    public function las_etiquetas_se_transcriben_literalmente_del_anexo(): void
    {
        $etiqueta = fn ($tabla, $code) => DB::table($tabla)->where('code', $code)->value('label');

        // Muestras con tilde, coma y punto y coma: es donde una transcripción
        // descuidada se rompe.
        $this->assertSame('Pérdida de paquetes', $etiqueta(TicketCatalogs::SYMPTOM, 'S05'));
        $this->assertSame('Wi-Fi conectado, pero sin Internet', $etiqueta(TicketCatalogs::SYMPTOM, 'S07'));
        $this->assertSame('Otro síntoma técnico; explicación obligatoria', $etiqueta(TicketCatalogs::SYMPTOM, 'S16'));
        $this->assertSame('Alarma o pérdida de señal óptica', $etiqueta(TicketCatalogs::SYMPTOM, 'S13'));

        $this->assertSame('Radiofrecuencia', $etiqueta(TicketCatalogs::CAUSE, 'RF'));
        $this->assertSame('Cliente / instalación interna', $etiqueta(TicketCatalogs::CAUSE, 'CL'));
        $this->assertSame('Autenticación y direccionamiento', $etiqueta(TicketCatalogs::CAUSE, 'AA'));

        $this->assertSame('Corrección PPPoE o RADIUS', $etiqueta(TicketCatalogs::SOLUTION, 'AC04'));
        $this->assertSame('Ajuste VLAN, routing, NAT o firewall', $etiqueta(TicketCatalogs::SOLUTION, 'AC15'));

        $this->assertSame('Solucionado después de varias intervenciones', $etiqueta(TicketCatalogs::RESULT, 'R03'));
        $this->assertSame('Requiere intervención adicional de infraestructura', $etiqueta(TicketCatalogs::RESULT, 'R10'));
    }

    // ── Subcausas: referencia, no contrato ───────────────────────────────

    #[Test]
    public function las_subcausas_viajan_como_texto_de_referencia_sin_codigo_propio(): void
    {
        // El Anexo A no les asigna código, así que NO se siembran como filas
        // seleccionables. Sólo existen las 7 familias.
        $rf = DB::table(TicketCatalogs::CAUSE)->where('code', 'RF')->first();

        $this->assertStringContainsString('interferencia', $rf->description);
        $this->assertStringContainsString('desalineación', $rf->description);

        $this->assertNull(
            $rf->group_code,
            'Las familias SON el nivel superior; `group_code` queda libre para las subcausas cuando tengan código.'
        );

        // Ninguna fila inventada del estilo RF01, FO02…
        //
        // El filtro se hace en PHP y no con un `~` de SQL a propósito: ese
        // operador de expresiones regulares sólo existe en PostgreSQL y aquí la
        // suite corre además sobre SQLite. La comparación en memoria da el mismo
        // resultado en los dos motores.
        $inventados = array_filter(
            $this->codigosDePlataforma(TicketCatalogs::CAUSE),
            fn (string $code) => preg_match('/^[A-Z]{2}\d/', $code) === 1,
        );

        $this->assertSame([], $inventados, 'No debe existir ninguna subcausa con código inventado.');
    }

    #[Test]
    public function los_sintomas_no_se_atan_a_una_categoria(): void
    {
        // Deliberado: el Anexo A no relaciona síntomas con las categorías de
        // ISPWatch, y hacerlo dependería de la decisión D-02 (separación
        // soporte/facturación), todavía abierta con el cliente.
        $this->assertSame(
            0,
            DB::table(TicketCatalogs::SYMPTOM)->whereNotNull('category_id')->count(),
        );
    }

    #[Test]
    public function todas_las_filas_sembradas_son_de_plataforma(): void
    {
        foreach ([TicketCatalogs::SYMPTOM, TicketCatalogs::CAUSE, TicketCatalogs::SOLUTION] as $tabla) {
            $this->assertSame(
                0,
                DB::table($tabla)->whereNotNull('tenant_id')->count(),
                "`{$tabla}` no debe sembrar filas atribuidas a un tenant concreto.",
            );
        }
    }

    // ── Idempotencia ─────────────────────────────────────────────────────

    #[Test]
    public function volver_a_ejecutar_la_migracion_no_duplica_nada(): void
    {
        $antes = [
            TicketCatalogs::SYMPTOM  => DB::table(TicketCatalogs::SYMPTOM)->count(),
            TicketCatalogs::CAUSE    => DB::table(TicketCatalogs::CAUSE)->count(),
            TicketCatalogs::SOLUTION => DB::table(TicketCatalogs::SOLUTION)->count(),
            TicketCatalogs::RESULT   => DB::table(TicketCatalogs::RESULT)->count(),
        ];

        $this->migracion()->up();
        $this->migracion()->up();

        foreach ($antes as $tabla => $n) {
            $this->assertSame($n, DB::table($tabla)->count(), "`{$tabla}` se duplicó al reejecutar.");
        }
    }

    #[Test]
    public function reejecutar_no_pisa_una_etiqueta_editada(): void
    {
        // La etiqueta es EDITABLE por diseño (R1) y su cambio aplica
        // retroactivamente. Un upsert la habría sobrescrito en cada despliegue;
        // por eso la migración inserta sólo lo ausente.
        DB::table(TicketCatalogs::SYMPTOM)->where('code', 'S01')->update(['label' => 'Sin servicio de Internet']);

        $this->migracion()->up();

        $this->assertSame(
            'Sin servicio de Internet',
            DB::table(TicketCatalogs::SYMPTOM)->where('code', 'S01')->value('label'),
        );
    }

    #[Test]
    public function la_siembra_no_toca_ningun_ticket(): void
    {
        $customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $customer->id, 'name' => 'Lucía', 'last_name' => 'Bermúdez', 'status' => true,
        ]);

        $ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $customer->id,
            'subject'   => 'Intermitencia',
            'status'    => 'open',
            'priority'  => 'high',
            'category'  => 'technical',
        ]);

        $antes = DB::table('support_ticket')->where('id', $ticket->id)->first();

        $this->migracion()->up();

        $despues = DB::table('support_ticket')->where('id', $ticket->id)->first();

        $this->assertEquals($antes, $despues, 'La siembra de catálogos no debe modificar tickets existentes.');
        $this->assertNull($despues->symptom_id, 'Sembrar el vocabulario no asigna diagnóstico a nadie.');
    }

    #[Test]
    public function la_version_del_catalogo_sube_al_sembrar(): void
    {
        foreach (['symptom', 'cause', 'solution', 'result'] as $catalogo) {
            $this->assertGreaterThan(
                1,
                DB::table('ticket_catalog_version')->where('catalog', $catalogo)->value('version'),
                "La versión de `{$catalogo}` debe subir para que el integrador detecte el cambio.",
            );
        }
    }

    // ── Endpoint ─────────────────────────────────────────────────────────

    #[Test]
    public function el_endpoint_expone_el_vocabulario_completo(): void
    {
        $data = $this->actingAs($this->staff)->getJson('/api/catalogs/ticket')->assertOk()->json();

        $this->assertCount(16, $data['symptoms']);
        $this->assertCount(7, $data['causes']);
        $this->assertCount(20, $data['actions']);
        $this->assertCount(15, $data['results']);

        $this->assertSame('S01', $data['symptoms'][0]['code']);
        $this->assertSame('Sin Internet', $data['symptoms'][0]['label']);
        $this->assertSame('RF', $data['causes'][0]['code']);
        $this->assertStringContainsString('interferencia', $data['causes'][0]['description']);
        $this->assertSame('AC01', $data['actions'][0]['code']);
        $this->assertSame('R01', $data['results'][0]['code']);
    }

    #[Test]
    public function el_endpoint_conserva_la_forma_previa_de_los_tres_catalogos_originales(): void
    {
        // El PR es ADITIVO: el frontend que ya consume statuses/priorities/
        // categories no debe notar el cambio.
        $data = $this->actingAs($this->staff)->getJson('/api/catalogs/ticket')->assertOk()->json();

        $this->assertSame(['open', 'in_progress', 'resolved', 'closed'], collect($data['statuses'])->pluck('code')->all());
        $this->assertSame(['code', 'label'], array_keys($data['statuses'][0]));
        $this->assertSame(4, count($data['priorities']));
        $this->assertSame(4, count($data['categories']));
    }

    #[Test]
    public function el_endpoint_publica_la_version_de_cada_catalogo(): void
    {
        $versions = $this->actingAs($this->staff)->getJson('/api/catalogs/ticket')->assertOk()->json('versions');

        foreach (['status', 'priority', 'category', 'symptom', 'cause', 'solution', 'result'] as $catalogo) {
            $this->assertArrayHasKey($catalogo, $versions);
        }
    }

    #[Test]
    public function el_endpoint_sigue_exigiendo_autenticacion(): void
    {
        $this->getJson('/api/catalogs/ticket')->assertUnauthorized();
    }

    #[Test]
    public function cada_fila_del_vocabulario_expone_solo_codigo_etiqueta_y_descripcion(): void
    {
        // La forma es parte del contrato, y el catálogo se lee de una tabla con
        // columnas internas (`id`, `tenant_id`, `weight`, `revision`, vigencias).
        // Filtrarlas no es cosmética: `tenant_id` revelaría qué filas son propias
        // de otro ISP y `id` ataría al consumidor a una clave que sólo es estable
        // dentro de una instalación — el identificador del contrato es `code`.
        $data = $this->actingAs($this->staff)->getJson('/api/catalogs/ticket')->assertOk()->json();

        foreach (['symptoms', 'causes', 'actions', 'results'] as $clave) {
            foreach ($data[$clave] as $fila) {
                $this->assertSame(
                    ['code', 'label', 'description'],
                    array_keys($fila),
                    "La fila de `{$clave}` no expone exactamente code/label/description."
                );
            }
        }
    }

    #[Test]
    public function una_llave_de_la_api_publica_no_alcanza_el_vocabulario(): void
    {
        // El endpoint es del PANEL. Ahora que además publica el Anexo A entero,
        // conviene fijar por prueba que un token de socio no lo alcanza: exponer
        // los catálogos al integrador es una decisión de contrato pendiente
        // (D-07), no algo que deba ocurrir por descuido de enrutamiento.
        $client = \App\Models\ApiClient::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Integrador',
            'is_active' => true,
        ]);

        $token = $client->createToken('test', ['read:customers']);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        // 401 y no 403: el guard `sanctum` resuelve contra el provider `users`,
        // así que un ApiClient ni siquiera llega a autenticar y DenyApiClients
        // no necesita opinar. La barrera es estructural, no un middleware que
        // haya que recordar al añadir rutas.
        $this->getJson('/api/catalogs/ticket', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ])->assertUnauthorized();
    }

    #[Test]
    public function el_endpoint_no_ofrece_filas_retiradas(): void
    {
        DB::table(TicketCatalogs::SYMPTOM)->where('code', 'S15')->update(['valid_until' => now()->subDay()]);
        app(TicketCatalogs::class)->flush();

        $data = $this->actingAs($this->staff)->getJson('/api/catalogs/ticket')->assertOk()->json();

        $this->assertNotContains('S15', collect($data['symptoms'])->pluck('code')->all());
        $this->assertCount(15, $data['symptoms']);
    }

    // ── Aislamiento entre tenants ────────────────────────────────────────

    #[Test]
    public function el_vocabulario_propio_de_otro_isp_no_se_filtra(): void
    {
        $otro = Tenant::factory()->create();

        DB::table(TicketCatalogs::SYMPTOM)->insert([
            'tenant_id' => $otro->id,
            'code' => 'X01', 'label' => 'Síntoma privado del otro ISP',
            'weight' => 999, 'valid_from' => now(), 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(TicketCatalogs::class)->flush();

        $codigos = collect(
            $this->actingAs($this->staff)->getJson('/api/catalogs/ticket')->assertOk()->json('symptoms')
        )->pluck('code')->all();

        $this->assertNotContains('X01', $codigos, 'El vocabulario propio de un ISP no puede verse desde otro.');
        $this->assertCount(16, $codigos, 'Sólo deben salir las 16 filas de plataforma.');
    }

    #[Test]
    public function el_vocabulario_propio_del_isp_si_aparece_junto_al_de_plataforma(): void
    {
        DB::table(TicketCatalogs::SYMPTOM)->insert([
            'tenant_id' => $this->tenant->id,
            'code' => 'Z01', 'label' => 'Síntoma propio de este ISP',
            'weight' => 999, 'valid_from' => now(), 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(TicketCatalogs::class)->flush();

        $codigos = collect(
            $this->actingAs($this->staff)->getJson('/api/catalogs/ticket')->assertOk()->json('symptoms')
        )->pluck('code')->all();

        $this->assertContains('Z01', $codigos);
        $this->assertCount(17, $codigos, '16 de plataforma + 1 propia.');
    }
}
