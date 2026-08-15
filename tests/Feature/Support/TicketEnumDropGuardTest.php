<?php

namespace Tests\Feature\Support;

use App\Models\CustomerProfile;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * FASE 1 · R3 — la guarda que decide si la migración puede destruir o no.
 *
 * La migración corre dentro del `run_command` del contenedor, ANTES de que
 * levante Apache: si aborta, el contenedor nuevo no arranca. Por eso importa
 * tanto QUÉ la aborta, y sobre todo qué NO debe abortarla.
 *
 * Se prueba llevando el esquema hacia atrás con el `down()` de la propia
 * migración, ensuciando los datos, y volviendo a ejecutar `up()`.
 *
 *   · Clave foránea sin resolver  → ABORTA. Dropear la columna dejaría ese
 *                                   ticket sin estado de forma irreversible.
 *   · Espejo divergente           → NO ABORTA. Desde la R2.5 el espejo está
 *                                   congelado y diverge por diseño; abortar por
 *                                   eso rompería el despliegue sin motivo.
 */
class TicketEnumDropGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $this->customer->id, 'name' => 'Hugo', 'last_name' => 'Sarmiento', 'status' => true,
        ]);
    }

    /** La instancia real de la migración R3, no una copia de su lógica. */
    private function migracion(): Migration
    {
        return require database_path(
            'migrations/2026_08_15_000001_drop_ticket_enum_columns_from_support_ticket.php'
        );
    }

    /**
     * Deja el esquema como estaba ANTES de la R3, usando el propio `down()`.
     *
     * Aprovecha para verificar de paso que la reversión funciona: si `down()`
     * estuviera roto, estos tests fallarían al preparar el escenario y no
     * quedaría oculto hasta el día que hiciera falta revertir de verdad.
     */
    private function volverAlEstadoPrevio(Migration $migracion): void
    {
        $migracion->down();

        $this->assertTrue(
            Schema::hasColumn('support_ticket', 'status'),
            'El down() de la R3 no restauró la columna: la reversión está rota.'
        );
    }

    private function ticket(array $overrides = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->customer->id,
            'subject'   => 'Guarda',
            'status'    => 'open',
            'priority'  => 'medium',
            'category'  => 'technical',
        ], $overrides));
    }

    // ── Lo que sí debe abortar ───────────────────────────────────────────

    #[Test]
    public function aborta_si_algun_ticket_tiene_la_clave_foranea_sin_resolver(): void
    {
        $ticket = $this->ticket();
        $migracion = $this->migracion();
        $this->volverAlEstadoPrevio($migracion);

        // Query builder para saltarse el modelo: por Eloquent el mutator la
        // volvería a resolver y no habría escenario que probar.
        DB::table('support_ticket')->where('id', $ticket->id)->update(['status_id' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('R3 abortada');

        $migracion->up();
    }

    #[Test]
    public function al_abortar_no_ha_tocado_el_esquema(): void
    {
        $ticket = $this->ticket();
        $migracion = $this->migracion();
        $this->volverAlEstadoPrevio($migracion);

        DB::table('support_ticket')->where('id', $ticket->id)->update(['priority_id' => null]);

        try {
            $migracion->up();
            $this->fail('La migración debió abortar y no lo hizo.');
        } catch (RuntimeException) {
            // Lo que de verdad importa: la comprobación va ANTES de cualquier
            // DDL. Si alguien la moviera después del primer DROP, el esquema
            // quedaría a medias y este test lo cazaría.
            foreach (['status', 'priority', 'category'] as $columna) {
                $this->assertTrue(
                    Schema::hasColumn('support_ticket', $columna),
                    "La migración abortó DESPUÉS de dropear `{$columna}`: el esquema quedó a medias."
                );
            }
        }
    }

    #[Test]
    public function el_mensaje_de_aborto_dice_como_repararlo(): void
    {
        $ticket = $this->ticket();
        $migracion = $this->migracion();
        $this->volverAlEstadoPrevio($migracion);

        DB::table('support_ticket')->where('id', $ticket->id)->update(['category_id' => null]);

        try {
            $migracion->up();
            $this->fail('La migración debió abortar.');
        } catch (RuntimeException $e) {
            // Quien lea esto va a estar mirando un despliegue fallido a
            // deshoras: el mensaje tiene que traer la consulta de reparación.
            $this->assertStringContainsString('category_id', $e->getMessage());
            $this->assertStringContainsString('UPDATE support_ticket', $e->getMessage());
        }
    }

    // ── Lo que NO debe abortar ───────────────────────────────────────────

    #[Test]
    public function no_aborta_porque_el_espejo_congelado_diverja(): void
    {
        $ticket = $this->ticket(['status' => 'open', 'priority' => 'low']);
        $migracion = $this->migracion();
        $this->volverAlEstadoPrevio($migracion);

        // Escenario REAL de producción tras la R2.5: la clave foránea dice una
        // cosa y el espejo congelado dice otra, porque el ticket cambió de
        // estado después de aquel despliegue.
        DB::table('support_ticket')->where('id', $ticket->id)->update([
            'status'   => 'closed',
            'priority' => 'urgent',
            'category' => 'general',
        ]);

        $migracion->up();

        // Si esto abortara, el despliegue de la R3 fallaría en producción de
        // forma intermitente —según cuántos tickets hubieran cambiado— y en
        // desarrollo pasaría siempre, porque allí la R2.5 nunca corrió.
        foreach (['status', 'priority', 'category'] as $columna) {
            $this->assertFalse(
                Schema::hasColumn('support_ticket', $columna),
                "La migración no completó el DROP de `{$columna}`."
            );
        }

        $this->assertSame('open', $ticket->fresh()->status, 'Manda la clave foránea, no el espejo.');
    }

    #[Test]
    public function la_reversion_reconstruye_los_codigos_desde_el_catalogo(): void
    {
        $ticket = $this->ticket(['status' => 'resolved', 'priority' => 'urgent', 'category' => 'billing']);
        $migracion = $this->migracion();

        $migracion->down();

        // Reconstruye desde `ticket_status`, no desde ningún respaldo del
        // espejo — que desde la R2.5 estaría obsoleto y devolvería basura.
        $fila = DB::table('support_ticket')->where('id', $ticket->id)->first();

        $this->assertSame('resolved', $fila->status);
        $this->assertSame('urgent', $fila->priority);
        $this->assertSame('billing', $fila->category);
    }

    #[Test]
    public function la_migracion_es_idempotente_en_el_ciclo_completo(): void
    {
        $ticket = $this->ticket(['status' => 'in_progress']);
        $migracion = $this->migracion();

        $migracion->down();
        $migracion->up();

        $this->assertFalse(Schema::hasColumn('support_ticket', 'status'));
        $this->assertSame('in_progress', $ticket->fresh()->status);
    }
}
