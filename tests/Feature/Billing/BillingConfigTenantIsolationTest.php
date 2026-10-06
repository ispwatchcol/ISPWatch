<?php

namespace Tests\Feature\Billing;

use App\Models\Billing;
use App\Models\Role;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KAN-121: /api/billing/configs no cruza tenants. `Billing` no tiene scope
 * global, así que el filtro vive en el controlador, y tiene que reconocer
 * también las filas antiguas con tenant_id NULL por el router que las usa.
 */
class BillingConfigTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $propio;
    private Tenant $ajeno;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Administrador', 'permissions' => ['*']]); // ocupa el id 1
        $this->propio = Tenant::factory()->create();
        $this->ajeno  = Tenant::factory()->create();

        $role = Role::create(['name' => 'Cajero', 'permissions' => ['view_billing']]);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->propio->id, 'role_id' => $role->id]));
    }

    private function config(?Tenant $tenant, ?Tenant $routerTenant = null, string $comments = 'original'): Billing
    {
        $billing = Billing::create(['overdue_invoices' => 1, 'status' => 'pending', 'comments' => $comments]);
        // tenant_id no es fillable: se fija a mano para poder simular filas viejas en NULL.
        $billing->forceFill(['tenant_id' => $tenant?->id])->save();

        if ($routerTenant) {
            Router::create([
                'name' => 'RB ' . uniqid(), 'tenant_id' => $routerTenant->id, 'status' => 'active',
                'ip' => '10.0.0.' . random_int(2, 250), 'user_rb' => 'u', 'password_rb' => 'p',
                'billing_router_id' => $billing->id,
            ]);
        }

        return $billing;
    }

    #[Test]
    public function no_se_puede_modificar_la_configuracion_de_otro_tenant(): void
    {
        $ajena = $this->config($this->ajeno, $this->ajeno);

        $this->putJson("/api/billing/configs/{$ajena->id}", ['comments' => 'modificado'])->assertNotFound();

        $this->assertSame('original', $ajena->fresh()->comments);
    }

    #[Test]
    public function tampoco_una_fila_antigua_sin_tenant_ligada_a_un_router_ajeno(): void
    {
        $ajena = $this->config(null, $this->ajeno);

        $this->putJson("/api/billing/configs/{$ajena->id}", ['comments' => 'modificado'])->assertNotFound();
        $this->assertSame('original', $ajena->fresh()->comments);
    }

    #[Test]
    public function la_propia_se_sigue_modificando_incluida_la_fila_antigua_sin_tenant(): void
    {
        $propia = $this->config($this->propio, $this->propio);
        $vieja  = $this->config(null, $this->propio);

        $this->putJson("/api/billing/configs/{$propia->id}", ['comments' => 'nueva'])->assertOk();
        $this->putJson("/api/billing/configs/{$vieja->id}", ['comments' => 'nueva'])->assertOk();

        $this->assertSame('nueva', $propia->fresh()->comments);
        $this->assertSame('nueva', $vieja->fresh()->comments);
    }

    #[Test]
    public function el_listado_solo_trae_las_del_tenant(): void
    {
        $propia = $this->config($this->propio, $this->propio);
        $vieja  = $this->config(null, $this->propio);
        $this->config($this->ajeno, $this->ajeno);
        $this->config(null, $this->ajeno);
        $this->config(null); // huérfana sin router: no es de nadie

        $ids = collect($this->getJson('/api/billing/configs')->assertOk()->json())->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$propia->id, $vieja->id])->sort()->values()->all(), $ids);
    }
}
