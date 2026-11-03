<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\Router;
use App\Models\Sectorial;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TicketCatalogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR #7 · Tablero y exportación de tickets (F1-18, F1-19, F1-20).
 *
 * Lo que se fija:
 *  - el tablero trae mediana, P90 y P95 de resolución y cierre;
 *  - la exportación respeta los filtros —incluido el de infraestructura— y el
 *    tenant, y sale en hora de Bogotá;
 *  - el tablero y el archivo filtran IGUAL (comparten `TicketReportFilters`);
 *  - «este mes» y los cortes por día son los de Bogotá, no los de UTC.
 */
class TicketReportsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $auditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        // Se quema el id 1: `CheckPermission` deja pasar siempre a role_id == 1.
        Role::create(['name' => 'Superadmin global', 'code' => 'superadmin', 'permissions' => ['*'], 'tenant_id' => null]);

        $this->auditor = $this->usuarioCon([Permissions::TICKET_EXPORT]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Tablero ──────────────────────────────────────────────────────────

    #[Test]
    public function el_tablero_trae_mediana_p90_y_p95_de_la_resolucion(): void
    {
        $cliente = $this->cliente();

        // Diez tickets resueltos en 1, 2, … 10 horas.
        foreach (range(1, 10) as $horas) {
            $this->ticket($cliente, '2026-10-01 12:00:00', ['resolved_at' => Carbon::parse('2026-10-01 12:00:00')->addHours($horas)]);
        }
        // Uno abierto: no entra en la distribución.
        $this->ticket($cliente, '2026-10-01 12:00:00');

        $stats = $this->actingAs($this->auditor)->getJson('/api/support/statistics')->assertOk()->json();

        $this->assertSame(10, $stats['resolution_time']['count']);
        $this->assertEquals(5.5, $stats['resolution_time']['median_hours']);
        $this->assertEquals(9.1, $stats['resolution_time']['p90_hours']);
        $this->assertEquals(9.55, $stats['resolution_time']['p95_hours']);
        $this->assertEquals(5.5, $stats['resolution_time']['avg_hours']);
        $this->assertSame('America/Bogota', $stats['timezone']);

        // La clave vieja sigue existiendo (la pantalla la leía), ahora en días
        // y calculada desde la MISMA distribución: 5,5 h = 0,2 días.
        $this->assertEquals(0.2, $stats['avg_resolution_time']);
    }

    #[Test]
    public function el_cierre_se_mide_aparte_de_la_resolucion(): void
    {
        $cliente = $this->cliente();
        $this->ticket($cliente, '2026-10-01 12:00:00', [
            'resolved_at' => '2026-10-01 14:00:00',
            'closed_at'   => '2026-10-02 12:00:00',
        ]);

        $stats = $this->actingAs($this->auditor)->getJson('/api/support/statistics')->assertOk()->json();

        $this->assertEquals(2.0, $stats['resolution_time']['median_hours']);
        $this->assertEquals(24.0, $stats['closure_time']['median_hours']);
    }

    #[Test]
    public function una_duracion_negativa_se_descarta_y_se_cuenta_aparte(): void
    {
        $cliente = $this->cliente();
        $this->ticket($cliente, '2026-10-01 12:00:00', ['resolved_at' => '2026-10-01 13:00:00']);
        // Datos importados con la resolución ANTES del radicado.
        $this->ticket($cliente, '2026-10-05 12:00:00', ['resolved_at' => '2026-10-01 12:00:00']);

        $stats = $this->actingAs($this->auditor)->getJson('/api/support/statistics')->assertOk()->json();

        $this->assertSame(1, $stats['resolution_time']['count']);
        $this->assertSame(1, $stats['resolution_time']['excluded']);
        $this->assertEquals(1.0, $stats['resolution_time']['median_hours']);
    }

    #[Test]
    public function sin_tickets_resueltos_los_percentiles_son_null_y_no_cero(): void
    {
        $this->ticket($this->cliente(), '2026-10-01 12:00:00');

        $stats = $this->actingAs($this->auditor)->getJson('/api/support/statistics')->assertOk()->json();

        $this->assertSame(0, $stats['resolution_time']['count']);
        $this->assertNull($stats['resolution_time']['p90_hours']);
        $this->assertSame(0, $stats['avg_resolution_time']);
    }

    #[Test]
    public function resueltos_este_mes_usa_el_mes_de_bogota(): void
    {
        // 1 de noviembre a las 02:00 UTC = 31 de octubre a las 21:00 en Bogotá.
        Carbon::setTestNow(Carbon::parse('2026-11-01 02:00:00', 'UTC'));

        $this->ticket($this->cliente(), '2026-10-10 12:00:00', [
            'status'      => 'resolved',
            'resolved_at' => '2026-10-15 12:00:00',
        ]);

        $stats = $this->actingAs($this->auditor)->getJson('/api/support/statistics')->assertOk()->json();

        // En UTC ya sería noviembre y el ticket de octubre no contaría.
        $this->assertSame(1, $stats['resolved_this_month']);
    }

    #[Test]
    public function el_tablero_filtra_por_router_del_cliente(): void
    {
        $routerA = $this->router('Torre Norte');
        $routerB = $this->router('Torre Sur');

        $this->ticket($this->cliente($routerA), '2026-10-01 12:00:00', ['resolved_at' => '2026-10-01 13:00:00']);
        $this->ticket($this->cliente($routerB), '2026-10-01 12:00:00', ['resolved_at' => '2026-10-01 22:00:00']);

        $stats = $this->actingAs($this->auditor)
            ->getJson("/api/support/statistics?router_id={$routerA->id}")->assertOk()->json();

        $this->assertSame(1, $stats['total_tickets']);
        $this->assertEquals(1.0, $stats['resolution_time']['median_hours']);
    }

    #[Test]
    public function las_opciones_de_filtro_son_solo_del_isp(): void
    {
        $this->router('Torre Norte');
        Sectorial::create(['tenant_id' => $this->tenant->id, 'name' => 'Nodo Centro', 'element_type' => 'sectorial']);

        $otro = Tenant::factory()->create();
        Router::create(['tenant_id' => $otro->id, 'name' => 'Router ajeno', 'status' => 'active']);
        Sectorial::create(['tenant_id' => $otro->id, 'name' => 'Nodo ajeno', 'element_type' => 'sectorial']);

        $opciones = $this->actingAs($this->auditor)->getJson('/api/support/statistics')
            ->assertOk()->json('filter_options');

        $this->assertSame(['Torre Norte'], array_column($opciones['routers'], 'name'));
        $this->assertSame(['Nodo Centro'], array_column($opciones['sectorials'], 'name'));
    }

    #[Test]
    public function una_fecha_final_anterior_a_la_inicial_es_422(): void
    {
        $this->actingAs($this->auditor)
            ->getJson('/api/support/statistics?created_from=2026-10-10&created_to=2026-10-01')
            ->assertStatus(422)->assertJsonValidationErrors('created_to');
    }

    // ── Exportación ──────────────────────────────────────────────────────

    #[Test]
    public function exportar_exige_ticket_export(): void
    {
        $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW]))
            ->get('/api/support/export')->assertForbidden();

        $this->actingAs($this->auditor)->get('/api/support/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    #[Test]
    public function el_archivo_trae_el_expediente_con_codigo_y_etiqueta(): void
    {
        $router = $this->router('Torre Norte');
        $sectorial = Sectorial::create(['tenant_id' => $this->tenant->id, 'name' => 'Nodo Centro', 'element_type' => 'sectorial']);
        $catalogos = app(TicketCatalogs::class);

        $ticket = $this->ticket($this->cliente($router, 'Axel', 'Cano', '1020304050'), '2026-10-01 15:00:00', [
            'sectorial_id' => $sectorial->id,
            'symptom_id'   => $catalogos->id(TicketCatalogs::SYMPTOM, 'S02'),
            'resolved_at'  => '2026-10-01 17:30:00',
        ]);

        $filas = $this->filas($this->actingAs($this->auditor)->get('/api/support/export')->assertOk()->streamedContent());

        $this->assertCount(2, $filas);
        $fila = array_combine($filas[0], $filas[1]);

        $this->assertSame((string) $ticket->id, $fila['Ticket']);
        // 15:00 UTC = 10:00 en Bogotá (UTC−5).
        $this->assertSame('2026-10-01 10:00', $fila['Creado (America/Bogota)']);
        $this->assertSame('2026-10-01 12:30', $fila['Resuelto (America/Bogota)']);
        $this->assertSame('2,50', $fila['Horas hasta resolución']);
        $this->assertSame('Axel Cano', $fila['Cliente']);
        $this->assertSame('1020304050', $fila['Cédula']);
        $this->assertSame('Torre Norte', $fila['Router']);
        $this->assertSame('Nodo Centro', $fila['Sectorial']);
        $this->assertSame('S02', $fila['Código Síntoma']);
        $this->assertSame('Servicio intermitente', $fila['Síntoma']);
        $this->assertSame('', $fila['Código Causa confirmada']);
    }

    #[Test]
    public function la_exportacion_respeta_los_filtros_de_infraestructura(): void
    {
        $routerA = $this->router('Torre Norte');
        $routerB = $this->router('Torre Sur');
        $nodo = Sectorial::create(['tenant_id' => $this->tenant->id, 'name' => 'Nodo Centro', 'element_type' => 'sectorial']);

        $enA = $this->ticket($this->cliente($routerA), '2026-10-01 12:00:00', ['sectorial_id' => $nodo->id]);
        $this->ticket($this->cliente($routerA), '2026-10-01 12:00:00');
        $this->ticket($this->cliente($routerB), '2026-10-01 12:00:00', ['sectorial_id' => $nodo->id]);

        $ids = fn (string $query) => array_column(
            array_slice($this->filas($this->actingAs($this->auditor)->get("/api/support/export?{$query}")->assertOk()->streamedContent()), 1),
            0,
        );

        $this->assertCount(2, $ids("router_id={$routerA->id}"));
        $this->assertSame([(string) $enA->id], $ids("router_id={$routerA->id}&sectorial_id={$nodo->id}"));
    }

    #[Test]
    public function el_rango_de_fechas_corta_por_el_dia_de_bogota(): void
    {
        $cliente = $this->cliente();
        // 1 de octubre 03:00 UTC = 30 de septiembre 22:00 en Bogotá.
        $deSeptiembre = $this->ticket($cliente, '2026-10-01 03:00:00');
        $deOctubre = $this->ticket($cliente, '2026-10-01 06:00:00');

        $ids = fn (string $query) => array_column(
            array_slice($this->filas($this->actingAs($this->auditor)->get("/api/support/export?{$query}")->assertOk()->streamedContent()), 1),
            0,
        );

        $this->assertSame([(string) $deSeptiembre->id], $ids('created_from=2026-09-30&created_to=2026-09-30'));
        $this->assertSame([(string) $deOctubre->id], $ids('created_from=2026-10-01&created_to=2026-10-01'));
    }

    #[Test]
    public function la_exportacion_no_trae_tickets_de_otro_isp_ni_archivados(): void
    {
        $propio = $this->ticket($this->cliente(), '2026-10-01 12:00:00');
        $archivado = $this->ticket($this->cliente(), '2026-10-01 12:00:00');
        DB::table('support_ticket')->where('id', $archivado->id)->update(['deleted_at' => now()]);

        $otro = Tenant::factory()->create();
        $ajeno = User::factory()->create(['tenant_id' => $otro->id]);
        SupportTicket::create([
            'tenant_id' => $otro->id, 'user_id' => $ajeno->id, 'subject' => 'Ajeno',
            'status' => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);

        $filas = $this->filas($this->actingAs($this->auditor)->get('/api/support/export')->assertOk()->streamedContent());

        $this->assertSame([(string) $propio->id], array_column(array_slice($filas, 1), 0));
    }

    #[Test]
    public function un_asunto_con_formula_sale_como_texto(): void
    {
        $this->ticket($this->cliente(), '2026-10-01 12:00:00', ['subject' => '=HYPERLINK("http://x","clic")']);

        $filas = $this->filas($this->actingAs($this->auditor)->get('/api/support/export')->assertOk()->streamedContent());
        $fila = array_combine($filas[0], $filas[1]);

        $this->assertSame('\'=HYPERLINK("http://x","clic")', $fila['Asunto']);
    }

    #[Test]
    public function el_tablero_y_el_archivo_cuentan_lo_mismo_con_el_mismo_filtro(): void
    {
        $router = $this->router('Torre Norte');
        foreach (range(1, 3) as $i) {
            $this->ticket($this->cliente($router), '2026-10-0' . $i . ' 12:00:00');
        }
        $this->ticket($this->cliente(), '2026-10-01 12:00:00');

        $query = "router_id={$router->id}&created_from=2026-10-02";
        $total = $this->actingAs($this->auditor)->getJson("/api/support/statistics?{$query}")->json('total_tickets');
        $filas = $this->filas($this->actingAs($this->auditor)->get("/api/support/export?{$query}")->streamedContent());

        $this->assertSame(2, $total);
        $this->assertCount($total, array_slice($filas, 1));
    }

    // ── Ayudas ───────────────────────────────────────────────────────────

    /** @param  array<int, string>  $permisos */
    private function usuarioCon(array $permisos): User
    {
        // `staff`: las dos rutas viven en el grupo `staff_profile`, que autoriza
        // por código de rol además del permiso.
        $rol = Role::create([
            'name' => 'Rol ' . implode(',', $permisos), 'code' => 'staff',
            'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
        ]);

        return User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rol->id]);
    }

    private function router(string $nombre): Router
    {
        return Router::create(['tenant_id' => $this->tenant->id, 'name' => $nombre, 'status' => 'active']);
    }

    private function cliente(?Router $router = null, string $nombre = 'Ana', string $apellido = 'Ruiz', ?string $cedula = null): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        CustomerProfile::create([
            'user_id' => $user->id, 'name' => $nombre, 'last_name' => $apellido,
            'status' => true, 'router_id' => $router?->id, 'cedula' => $cedula,
        ]);

        return $user;
    }

    /**
     * Ticket con `created_at` fijo. Las fechas van por `DB::table` para que el
     * observer del historial no las reinterprete ni deje eventos de prueba.
     *
     * @param  array<string, mixed>  $extra
     */
    private function ticket(User $cliente, string $creadoUtc, array $extra = []): SupportTicket
    {
        $fechas = array_intersect_key($extra, array_flip(['resolved_at', 'closed_at']));
        $resto = array_diff_key($extra, $fechas);

        $ticket = SupportTicket::create($resto + [
            'tenant_id' => $this->tenant->id,
            'user_id'   => $cliente->id,
            'subject'   => 'Sin señal',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);

        DB::table('support_ticket')->where('id', $ticket->id)->update(array_map(
            fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v,
            ['created_at' => $creadoUtc] + $fechas,
        ));

        return $ticket->fresh();
    }

    /** @return array<int, array<int, string>> */
    private function filas(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lineas = array_values(array_filter(preg_split('/\r\n|\n/', $csv), fn ($l) => $l !== ''));

        return array_map(fn ($l) => str_getcsv($l, ';', '"', ''), $lineas);
    }
}
