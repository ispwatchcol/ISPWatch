<?php

namespace Tests\Feature\Router;

use App\Models\CustomerProfile;
use App\Models\Router;
use App\Models\SuspensionActionLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OverdueSuspensionService;
use App\Services\RouterProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cuando un router lo gestiona un AAA externo, ISPWatch NO le escribe.
 *
 * El agujero que estos tests cierran: el interruptor `radius` solo cubría el
 * aprovisionamiento. Cortar y reconectar seguían abriendo SSH contra el equipo,
 * que es exactamente la operación más peligrosa para pisarle la configuración a
 * quien lo administra — y encima la que más veces corre (cada ciclo de mora).
 *
 * Se prueba a través de RouterProvisioningService y no de cada llamador porque
 * es el cuello de botella común de las seis puertas (panel ×2, reintento
 * manual, corte por mora, reconciliador y reactivación al pagar).
 */
class ExternallyManagedRouterTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function router(Tenant $tenant, bool $external): Router
    {
        return Router::create([
            'name'      => 'Router ' . (++$this->seq),
            'tenant_id' => $tenant->id,
            'status'    => 'active',
            'ip'        => '10.10.0.' . (($this->seq % 200) + 2),
            'user_rb'   => 'admin',
            'password_rb' => 'secreta',
            'radius'    => $external,
        ]);
    }

    private function customer(Tenant $tenant, Router $router): CustomerProfile
    {
        $this->seq++;
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        return CustomerProfile::create([
            'user_id'   => $user->id,
            'name'      => "Cliente{$this->seq}",
            'last_name' => 'Apellido',
            'router_id' => $router->id,
            'ip_user'   => '10.40.0.' . (($this->seq % 200) + 2),
            'status'    => false,
            'service_status' => 'suspendido',
        ]);
    }

    #[Test]
    public function suspender_en_un_router_externo_no_escribe_ni_deja_bitacora_de_rb(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->router($tenant, external: true);
        $customer = $this->customer($tenant, $router);

        $ok = app(RouterProvisioningService::class)
            ->suspendCustomer($customer->user_id, $router->id);

        // true = "la orden quedó dada", no "escribí en el RouterBoard". Si
        // devolviera false, el reconciliador reintentaría para siempre contra
        // un equipo que nunca va a responderle.
        $this->assertTrue($ok);

        // suspension_action_logs es la bitácora de "intentamos escribir en el
        // RB". Aquí no se intentó, así que inventar un registro exitoso
        // ensuciaría el panel de failover con intentos que nunca pasaron.
        $this->assertSame(0, SuspensionActionLog::where('customer_id', $customer->user_id)->count());
    }

    #[Test]
    public function reconectar_en_un_router_externo_tampoco_escribe(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->router($tenant, external: true);
        $customer = $this->customer($tenant, $router);

        $ok = app(RouterProvisioningService::class)
            ->unsuspendCustomer($customer->user_id, $router->id);

        $this->assertTrue($ok);
        $this->assertSame(0, SuspensionActionLog::where('customer_id', $customer->user_id)->count());
    }

    #[Test]
    public function el_reconciliador_salta_los_routers_externos_y_los_cuenta_aparte(): void
    {
        // El falso positivo que esto evita: sin la salida, cada pasada contaría
        // un "re-bloqueo OK" que nunca ocurrió, y ese ruido taparía justamente
        // los cortes que sí fallaron.
        $tenant   = Tenant::factory()->create();
        $router   = $this->router($tenant, external: true);
        $customer = $this->customer($tenant, $router);

        $stats = app(OverdueSuspensionService::class)->reconcileSuspensions();

        $this->assertSame(1, $stats['scanned']);
        $this->assertSame(1, $stats['skipped_external']);
        $this->assertSame(0, $stats['reblocked_ok']);
        $this->assertSame(0, $stats['reblocked_failed']);
    }

    #[Test]
    public function un_router_normal_no_se_ve_afectado_por_el_cambio(): void
    {
        // La guarda no debe cambiar nada para la flota que ISPWatch sí gestiona:
        // ahí el reconciliador tiene que seguir intentando el re-bloqueo.
        $tenant   = Tenant::factory()->create();
        $router   = $this->router($tenant, external: false);
        $customer = $this->customer($tenant, $router);

        $stats = app(OverdueSuspensionService::class)->reconcileSuspensions();

        $this->assertSame(1, $stats['scanned']);
        $this->assertSame(0, $stats['skipped_external']);
        // No se afirma si el re-bloqueo salió bien o mal: sin un RouterBoard de
        // verdad detrás fallará. Lo que importa es que lo INTENTÓ.
        $this->assertSame(1, $stats['reblocked_ok'] + $stats['reblocked_failed']);
    }
}
