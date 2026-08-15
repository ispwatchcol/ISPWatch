<?php

namespace Tests\Feature\Support;

use App\Models\CustomerProfile;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FASE 1 · R1 — que la migración a catálogos no deje ni un ticket huérfano.
 *
 * La afirmación central es la CONSULTA ANTI-JOIN: ningún ticket puede tener
 * valor en la columna enum y quedarse sin id de catálogo resuelto. Vive en la
 * suite y no sólo como verificación manual de despliegue, porque el modo de
 * fallo es silencioso — un ticket sin `status_id` no rompe nada hoy, y explota
 * en la R3, cuando el enum ya no exista para recuperarlo.
 *
 * Se prueban las dos mitades del invariante:
 *   · hacia atrás — el backfill de las filas que ya existían (la migración);
 *   · hacia adelante — el relleno de los tickets creados después (el modelo).
 *
 * Sin la segunda, el invariante se rompería el mismo día del despliegue.
 */
class TicketCatalogBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** Columna enum => [columna FK, tabla de catálogo]. Espejo de la R1. */
    private const MIGRADAS = [
        'status'   => ['status_id',   'ticket_status'],
        'priority' => ['priority_id', 'ticket_priority'],
        'category' => ['category_id', 'ticket_category'],
    ];

    private Tenant $tenant;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);

        CustomerProfile::create([
            'user_id' => $this->customer->id, 'name' => 'Iván', 'last_name' => 'Rueda', 'status' => true,
        ]);
    }

    private function ticket(array $overrides = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->customer->id,
            'subject'   => 'Ticket de prueba',
            'status'    => 'open',
            'priority'  => 'medium',
            'category'  => 'technical',
        ], $overrides));
    }

    /** Crea un ticket por cada valor posible de los tres enums. */
    private function ticketsDeTodoElDominio(): void
    {
        foreach (['open', 'in_progress', 'resolved', 'closed'] as $estado) {
            $this->ticket(['status' => $estado, 'subject' => 'Estado ' . $estado]);
        }

        foreach (['low', 'medium', 'high', 'urgent'] as $prioridad) {
            $this->ticket(['priority' => $prioridad, 'subject' => 'Prioridad ' . $prioridad]);
        }

        foreach (['technical', 'billing', 'services', 'general'] as $categoria) {
            $this->ticket(['category' => $categoria, 'subject' => 'Categoría ' . $categoria]);
        }
    }

    /**
     * Deja las filas como estaban ANTES de la R1: con la columna enum escrita y
     * la clave foránea vacía.
     *
     * Hace falta desde la R2.5 porque el modelo ya no escribe el espejo — un
     * ticket creado hoy nace con la columna enum en su valor por defecto, que no
     * es lo que tenía una fila heredada. Sin esto, el escenario de partida del
     * backfill no se parecería al real y el test probaría otra cosa.
     */
    private function simularFilasHeredadas(): void
    {
        foreach (self::MIGRADAS as $enum => [$columna, $tabla]) {
            DB::statement("
                UPDATE support_ticket
                SET {$enum} = (SELECT code FROM {$tabla} WHERE {$tabla}.id = support_ticket.{$columna})
                WHERE {$columna} IS NOT NULL
            ");
        }

        DB::table('support_ticket')->update([
            'status_id' => null, 'priority_id' => null, 'category_id' => null,
        ]);
    }

    /** La consulta anti-join: filas con enum y sin catálogo resuelto. */
    private function huerfanos(string $enum, string $columna): int
    {
        return DB::table('support_ticket')
            ->whereNotNull($enum)
            ->whereNull($columna)
            ->count();
    }

    // ── El invariante ────────────────────────────────────────────────────

    #[Test]
    public function ningun_ticket_queda_sin_catalogo_resuelto(): void
    {
        $this->ticketsDeTodoElDominio();

        foreach (self::MIGRADAS as $enum => [$columna, $tabla]) {
            $this->assertSame(
                0,
                $this->huerfanos($enum, $columna),
                "Hay tickets con `{$enum}` sin correspondencia en `{$tabla}`. "
                . 'El backfill quedó incompleto y en la R3 esos tickets perderían el dato.'
            );
        }
    }

    #[Test]
    public function el_backfill_resuelve_las_filas_que_ya_existian(): void
    {
        $this->ticketsDeTodoElDominio();
        $this->simularFilasHeredadas();

        foreach (self::MIGRADAS as $enum => [$columna, $tabla]) {
            $this->assertGreaterThan(0, $this->huerfanos($enum, $columna), 'El escenario de partida no se preparó bien.');

            // La MISMA subconsulta correlacionada de la migración: es SQL
            // estándar a propósito, para que corra igual en SQLite (donde se
            // prueba) y en PostgreSQL (donde se despliega).
            DB::statement("
                UPDATE support_ticket
                SET {$columna} = (
                    SELECT id FROM {$tabla} WHERE {$tabla}.code = support_ticket.{$enum}
                )
                WHERE {$enum} IS NOT NULL
            ");

            $this->assertSame(0, $this->huerfanos($enum, $columna));
        }
    }

    #[Test]
    public function el_backfill_apunta_cada_fila_heredada_a_su_codigo_exacto(): void
    {
        $this->ticketsDeTodoElDominio();
        $this->simularFilasHeredadas();

        // No basta con que haya un id: tiene que ser el id CORRECTO. Un backfill
        // mal escrito podría asignarlos todos a la misma fila y la consulta
        // anti-join no lo notaría.
        foreach (self::MIGRADAS as $enum => [$columna, $tabla]) {
            DB::statement("
                UPDATE support_ticket
                SET {$columna} = (SELECT id FROM {$tabla} WHERE {$tabla}.code = support_ticket.{$enum})
                WHERE {$enum} IS NOT NULL
            ");

            $descuadres = DB::table('support_ticket')
                ->join($tabla, "{$tabla}.id", '=', "support_ticket.{$columna}")
                ->whereColumn("{$tabla}.code", '!=', "support_ticket.{$enum}")
                ->count();

            $this->assertSame(0, $descuadres, "Hay tickets apuntando a una fila de `{$tabla}` con otro código.");
        }
    }

    #[Test]
    public function un_ticket_creado_despues_de_la_migracion_tambien_queda_resuelto(): void
    {
        $ticket = $this->ticket(['status' => 'in_progress', 'priority' => 'urgent', 'category' => 'billing']);

        $this->assertNotNull($ticket->status_id, 'El relleno hacia adelante no actuó al crear.');

        foreach (self::MIGRADAS as $enum => [$columna, $tabla]) {
            $this->assertSame(
                $ticket->{$enum},
                DB::table($tabla)->where('id', $ticket->{$columna})->value('code'),
            );
        }
    }

    #[Test]
    public function cambiar_el_enum_reapunta_la_clave_foranea(): void
    {
        $ticket = $this->ticket(['status' => 'open']);
        $idAbierto = $ticket->status_id;

        $ticket->update(['status' => 'resolved']);
        $ticket->refresh();

        $this->assertNotSame($idAbierto, $ticket->status_id, 'La FK se quedó apuntando al estado anterior.');
        $this->assertSame(
            'resolved',
            DB::table('ticket_status')->where('id', $ticket->status_id)->value('code'),
        );
    }

    // ── Contenido del catálogo ───────────────────────────────────────────

    #[Test]
    public function los_catalogos_traen_exactamente_los_codigos_de_los_enums_actuales(): void
    {
        // Es lo que convierte el backfill en un join sin mapeo manual: si el
        // catálogo trajera un código distinto, habría tickets huérfanos.
        $esperado = [
            'ticket_status'   => ['open', 'in_progress', 'resolved', 'closed'],
            'ticket_priority' => ['low', 'medium', 'high', 'urgent'],
            'ticket_category' => ['technical', 'billing', 'services', 'general'],
        ];

        foreach ($esperado as $tabla => $codigos) {
            $this->assertEqualsCanonicalizing($codigos, DB::table($tabla)->pluck('code')->all());
        }
    }

    #[Test]
    public function el_vocabulario_de_diagnostico_nace_vacio_a_proposito(): void
    {
        // Sus códigos son inmutables una vez sembrados, así que se siembran
        // cuando estén acordados con el ISP y el integrador, no antes.
        foreach (['ticket_symptom', 'ticket_cause', 'ticket_solution', 'ticket_result'] as $tabla) {
            $this->assertSame(0, DB::table($tabla)->count(), "`{$tabla}` no debería tener vocabulario inventado.");
        }
    }

    #[Test]
    public function hay_exactamente_un_estado_inicial_y_dos_terminales(): void
    {
        $inicial = DB::table('ticket_status')->where('is_initial', true)->pluck('code');
        $this->assertSame(['open'], $inicial->all());

        $terminales = DB::table('ticket_status')->where('is_terminal', true)->pluck('code');
        $this->assertEqualsCanonicalizing(['resolved', 'closed'], $terminales->all());
    }

    #[Test]
    public function el_sellado_de_fechas_esta_declarado_en_el_catalogo(): void
    {
        $this->assertSame(['resolved'], DB::table('ticket_status')->where('stamps_resolved_at', true)->pluck('code')->all());
        $this->assertSame(['closed'], DB::table('ticket_status')->where('stamps_closed_at', true)->pluck('code')->all());
    }

    #[Test]
    public function solo_la_categoria_tecnica_viaja_al_integrador(): void
    {
        $this->assertSame(
            ['technical'],
            DB::table('ticket_category')->where('is_integration_visible', true)->pluck('code')->all(),
            'El alcance acordado se limita a soporte técnico de servicios existentes.'
        );
    }

    #[Test]
    public function todos_los_catalogos_arrancan_en_la_version_uno(): void
    {
        $this->assertEqualsCanonicalizing(
            ['status', 'priority', 'category', 'symptom', 'cause', 'solution', 'result'],
            DB::table('ticket_catalog_version')->pluck('catalog')->all(),
        );

        $this->assertSame(0, DB::table('ticket_catalog_version')->where('version', '!=', 1)->count());
    }

    // ── Reglas estructurales del diseño ──────────────────────────────────

    #[Test]
    public function no_se_puede_borrar_una_fila_de_catalogo_en_uso(): void
    {
        $ticket = $this->ticket(['status' => 'open']);

        // ON DELETE RESTRICT: que sea la base de datos y no la disciplina de
        // turno la que impida dejar un ticket histórico sin su estado.
        $this->expectException(QueryException::class);

        DB::table('ticket_status')->where('id', $ticket->status_id)->delete();
    }

    #[Test]
    public function dos_filas_globales_no_pueden_compartir_codigo(): void
    {
        // El índice parcial que cubre el agujero de NULL != NULL en PostgreSQL:
        // sin él, un UNIQUE(tenant_id, code) dejaría pasar este duplicado y la
        // unicidad de los códigos de plataforma sería decorativa.
        $this->expectException(QueryException::class);

        foreach ([1, 2] as $intento) {
            DB::table('ticket_symptom')->insert([
                'tenant_id'  => null,
                'code'       => 'sin_senal',
                'label'      => 'Sin señal ' . $intento,
                'weight'     => 0,
                'valid_from' => now(),
                'revision'   => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    #[Test]
    public function dos_tenants_si_pueden_usar_el_mismo_codigo_propio(): void
    {
        $otro = Tenant::factory()->create();

        foreach ([$this->tenant->id, $otro->id] as $tenantId) {
            DB::table('ticket_symptom')->insert([
                'tenant_id'  => $tenantId,
                'code'       => 'antena_desalineada',
                'label'      => 'Antena desalineada',
                'weight'     => 0,
                'valid_from' => now(),
                'revision'   => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, DB::table('ticket_symptom')->where('code', 'antena_desalineada')->count());
    }

    #[Test]
    public function retirar_una_fila_es_ponerle_fecha_y_no_borrarla(): void
    {
        $ticket = $this->ticket(['priority' => 'high']);

        DB::table('ticket_priority')->where('code', 'high')->update(['valid_until' => now()->subDay()]);

        $vigentes = DB::table('ticket_priority')
            ->whereNull('valid_until')
            ->pluck('code');

        $this->assertNotContains('high', $vigentes->all(), 'Una fila retirada no debe ofrecerse.');

        // Pero el ticket histórico la sigue resolviendo: es justo el punto.
        $ticket->refresh();
        $this->assertSame(
            'high',
            DB::table('ticket_priority')->where('id', $ticket->priority_id)->value('code'),
        );
    }
}
