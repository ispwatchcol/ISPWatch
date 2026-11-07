<?php

namespace Tests\Feature\Billing;

use App\Models\BillingActionLog;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El panel de failover de facturación carga el perfil del cliente. `customer_profile`
 * no tiene columna `id` (su clave es `user_id`): pedir `customerProfile:id,...` rompe
 * en PostgreSQL. SQLite no lo detecta porque toma el identificador desconocido entre
 * comillas como un literal, así que este test sólo falla en el job PostgreSQL del CI.
 */
class BillingActionLogEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Administrador', 'permissions' => ['*']]); // ocupa el id 1
        $this->tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'Cobranza', 'permissions' => ['execute_mass_actions']]);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]));
    }

    private function failedLog(): BillingActionLog
    {
        $cliente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $cliente->id, 'name' => 'Ana', 'last_name' => 'Ruiz', 'status' => true,
        ]);

        return BillingActionLog::create([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $cliente->id,
            'action'       => BillingActionLog::ACTION_GENERATE_MONTHLY,
            'period_start' => '2026-11-01',
            'period_end'   => '2026-11-30',
            'status'       => BillingActionLog::STATUS_FAILED,
            'attempts'     => 1,
            'last_error'   => 'Router sin respuesta',
        ]);
    }

    #[Test]
    public function el_listado_trae_el_nombre_del_cliente(): void
    {
        $this->failedLog();

        $this->getJson('/api/billing/action-logs')
            ->assertOk()
            ->assertJsonPath('data.0.customer.customer_profile.name', 'Ana')
            ->assertJsonPath('data.0.customer.customer_profile.last_name', 'Ruiz');
    }

    #[Test]
    public function reintentar_devuelve_el_registro_con_el_cliente(): void
    {
        $log = $this->failedLog();
        $this->mock(BillingService::class)
            ->shouldReceive('retryFailedInvoice')->once()->andReturn(false);

        $this->postJson("/api/billing/action-logs/{$log->id}/retry")
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('log.customer.customer_profile.name', 'Ana');
    }
}
