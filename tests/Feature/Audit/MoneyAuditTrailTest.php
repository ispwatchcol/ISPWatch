<?php

namespace Tests\Feature\Audit;

use App\Imports\CustomersUpdateImport;
use App\Models\AuditLog;
use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bitácora de lo que mueve plata.
 *
 * El episodio que originó esto: el precio de un plan se cargó en $56.000 en vez
 * de $60.000, se facturó mal a 94 clientes y después se corrigió — sin que
 * quedara registro de quién lo cambió ni cuándo. En la otra sede pasó lo mismo
 * con clientes reasignados a planes equivocados mediante una carga masiva.
 *
 * Por eso el test más importante de este archivo no es el del panel, sino el de
 * la importación: es el camino que una instrumentación de controladores habría
 * dejado ciego.
 */
class MoneyAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function plan(Tenant $tenant, float $price = 56000): Plan
    {
        return Plan::create([
            'name'         => 'Internet Fibra 100MB',
            'speed_down'   => '100M',
            'speed_up'     => '100M',
            'cost_product' => $price,
            'type'         => 'pppoe',
            'tenant_id'    => $tenant->id,
        ]);
    }

    private function logsFor(string $action): \Illuminate\Support\Collection
    {
        return AuditLog::where('action', $action)->get();
    }

    // ── Cambio de precio: el caso original ───────────────────────────────

    #[Test]
    public function cambiar_el_precio_de_un_plan_queda_registrado_con_valores_viejo_y_nuevo(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->plan($tenant, 56000);

        $plan->update(['cost_product' => 60000, 'name' => 'Internet Fibra 100MB 60']);

        $log = $this->logsFor('plan.updated')->last();

        $this->assertNotNull($log, 'Cambiar el precio de un plan no dejó registro.');
        $this->assertEquals(56000, $log->old_values['cost_product']);
        $this->assertEquals(60000, $log->new_values['cost_product']);
        $this->assertEquals($tenant->id, $log->tenant_id);
        $this->assertStringContainsString('precio', $log->description);
        // El texto debe ser legible sin abrir el JSON.
        $this->assertStringContainsString('$56.000', $log->description);
        $this->assertStringContainsString('$60.000', $log->description);
    }

    #[Test]
    public function un_cambio_que_no_toca_campos_de_plata_no_ensucia_la_bitacora(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->plan($tenant);

        AuditLog::query()->delete();

        $plan->update(['speed_down' => '200M', 'priority' => 3]);

        $this->assertCount(0, $this->logsFor('plan.updated'),
            'Velocidad y prioridad no mueven plata: no deberían registrarse.');
    }

    #[Test]
    public function el_alta_y_la_baja_de_un_plan_quedan_registradas(): void
    {
        $tenant = Tenant::factory()->create();

        $plan = $this->plan($tenant);
        $this->assertCount(1, $this->logsFor('plan.created'));

        $plan->delete();
        $this->assertCount(1, $this->logsFor('plan.deleted'));
    }

    // ── El camino ciego: la carga masiva ─────────────────────────────────

    #[Test]
    public function reasignar_el_plan_de_un_cliente_por_carga_masiva_queda_registrado(): void
    {
        $tenant  = Tenant::factory()->create();
        $barato  = $this->plan($tenant, 52500);
        $caro    = Plan::create([
            'name'         => 'Internet Fibra 200MB',
            'speed_down'   => '200M',
            'speed_up'     => '200M',
            'cost_product' => 68000,
            'type'         => 'pppoe',
            'tenant_id'    => $tenant->id,
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'cliente@ejemplo.com']);
        CustomerProfile::create([
            'user_id'    => $user->id,
            'name'       => 'Cliente',
            'last_name'  => 'De Prueba',
            'status'     => true,
            'service_id' => $barato->id,
        ]);
        UserService::create([
            'user_id'         => $user->id,
            'service_plan_id' => $barato->id,
            'status'          => UserService::STATUS_ACTIVE,
            'start_date'      => now(),
        ]);

        AuditLog::query()->delete();

        // Este es el camino que usó la sede donde se reasignaron planes en masa.
        $import = new CustomersUpdateImport($tenant->id);
        $import->collection(collect([
            new \Illuminate\Support\Collection([
                'email_actual' => 'cliente@ejemplo.com',
                'nombre_plan'  => 'Internet Fibra 200MB',
            ]),
        ]));

        $log = $this->logsFor('customer_profile.updated')->last();

        $this->assertNotNull($log, 'Una carga masiva cambió el plan de un cliente sin dejar rastro.');
        $this->assertEquals($barato->id, $log->old_values['service_id']);
        $this->assertEquals($caro->id, $log->new_values['service_id']);
        // El nombre del plan tiene que aparecer: un id suelto no le sirve a nadie.
        $this->assertStringContainsString('Internet Fibra 200MB', $log->description);
    }

    // ── Configuración de facturación ─────────────────────────────────────

    #[Test]
    public function cambiar_el_dia_de_corte_queda_registrado(): void
    {
        $tenant = Tenant::factory()->create();

        $config = Billing::create([
            'create_invoice' => '2026-08-01',
            'cut_day'        => '2026-08-20',
            'billing_mode'   => Billing::MODE_ANTICIPADO,
            'status'         => 'pending',
            'tenant_id'      => $tenant->id,
        ]);

        AuditLog::query()->delete();

        $config->update(['cut_day' => '2026-08-28']);

        $log = $this->logsFor('billing.updated')->last();

        $this->assertNotNull($log, 'Mover el día de corte no dejó registro.');
        $this->assertStringContainsString('día de corte', $log->description);
    }

    // ── Contexto ─────────────────────────────────────────────────────────

    #[Test]
    public function la_bitacora_guarda_quien_hizo_el_cambio(): void
    {
        $tenant = Tenant::factory()->create();
        $staff  = User::factory()->create(['tenant_id' => $tenant->id]);
        $plan   = $this->plan($tenant);

        AuditLog::query()->delete();

        $this->actingAs($staff);
        $plan->update(['cost_product' => 60000]);

        $log = $this->logsFor('plan.updated')->last();

        $this->assertEquals($staff->id, $log->user_id);
    }

    /**
     * La bitácora es un observador, no un guardián: si falla, el negocio sigue.
     *
     * En PostgreSQL esto es más grave de lo que parece — una excepción dentro de
     * la transacción la deja abortada y todo lo que venga después revienta en
     * cadena. SQLite no tiene ese estado, así que un observer frágil pasaría
     * inadvertido en local y tumbaría producción.
     */
    #[Test]
    public function si_la_bitacora_falla_la_operacion_de_negocio_no_se_cae(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);

        // Simula que escribir en la bitácora revienta.
        \Illuminate\Support\Facades\Schema::drop('audit_logs');

        $pago = \App\Models\Payment::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => 60000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
            'status'       => 'completed',
        ]);

        $this->assertTrue($pago->exists, 'Un fallo de la bitácora no puede impedir registrar un pago.');
        $this->assertDatabaseHas('payments', ['id' => $pago->id, 'amount' => 60000]);
    }

    /**
     * `audit_logs.user_id` tiene clave foránea contra `users`, pero no todo lo
     * que se autentica es un User: la API pública autentica un ApiClient, cuyo
     * id vive en otra tabla. Estamparlo ahí viola la foránea en PostgreSQL.
     *
     * SQLite no aplica las foráneas por defecto, así que este fallo sólo se ve
     * en el motor real — exactamente el tipo de divergencia por la que el CI
     * corre las dos bases.
     */
    #[Test]
    public function un_actor_que_no_es_usuario_no_se_estampa_como_autor(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->plan($tenant);

        $cliente = \App\Models\ApiClient::create([
            'tenant_id' => $tenant->id,
            'name'      => 'CRM externo',
            'is_active' => true,
        ]);

        AuditLog::query()->delete();

        $this->actingAs($cliente);
        $plan->update(['cost_product' => 60000]);

        $log = $this->logsFor('plan.updated')->last();

        $this->assertNotNull($log, 'El cambio debía registrarse igual.');
        $this->assertNull(
            $log->user_id,
            'El id de un ApiClient no puede ir a user_id: viola la foránea contra users en PostgreSQL.'
        );
    }

    #[Test]
    public function los_cambios_sin_sesion_se_marcan_como_consola(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->plan($tenant);

        AuditLog::query()->delete();

        $plan->update(['cost_product' => 60000]);

        $log = $this->logsFor('plan.updated')->last();

        $this->assertNull($log->user_id, 'Sin sesión no puede inventarse un autor.');
        $this->assertSame('console', $log->source);
    }
}
