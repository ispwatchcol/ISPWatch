<?php

namespace Tests\Feature\Audit;

use App\Constants\Permissions;
use App\Models\AuditLog;
use App\Models\CustomerCredit;
use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Operador con un set de permisos concreto.
     *
     * El rol id 1 es el bypass de superadmin de CheckPermission, así que se
     * quema un rol de relleno antes: si no, el primer rol creado en una base de
     * pruebas recién migrada saldría con id 1 y el test de permisos pasaría por
     * la puerta de atrás sin verificar nada.
     */
    private function staffWith(Tenant $tenant, array $permissions): User
    {
        if (!Role::query()->exists()) {
            Role::create(['name' => 'Superadmin de relleno', 'code' => 'filler', 'permissions' => []]);
        }

        $role = Role::create([
            'name'        => 'Rol ' . uniqid(),
            'code'        => 'custom_' . uniqid(),
            'permissions' => $permissions,
            'tenant_id'   => $tenant->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id'   => $role->id,
        ]);
    }

    #[Test]
    public function el_visor_exige_el_permiso_de_auditoria(): void
    {
        $tenant = Tenant::factory()->create();
        $sinPermiso = $this->staffWith($tenant, [Permissions::VIEW_CLIENTS]);

        Sanctum::actingAs($sinPermiso);

        $this->getJson('/api/audit-logs')->assertForbidden();
    }

    #[Test]
    public function un_operador_solo_ve_la_bitacora_de_su_sede(): void
    {
        $tocaima  = Tenant::factory()->create(['name' => 'Tocaima']);
        $chaguani = Tenant::factory()->create(['name' => 'Chaguaní']);

        AuditLog::log([
            'tenant_id'   => $tocaima->id,
            'action'      => 'plan.updated',
            'description' => 'Cambio en Tocaima',
        ]);
        AuditLog::log([
            'tenant_id'   => $chaguani->id,
            'action'      => 'plan.updated',
            'description' => 'Cambio en Chaguaní',
        ]);

        Sanctum::actingAs($this->staffWith($tocaima, [Permissions::VIEW_AUDIT_LOG]));

        $response = $this->getJson('/api/audit-logs')->assertOk();

        $descripciones = collect($response->json('data'))->pluck('description');

        $this->assertContains('Cambio en Tocaima', $descripciones);
        $this->assertNotContains('Cambio en Chaguaní', $descripciones,
            'La bitácora de una sede no puede filtrarse a otra.');
    }

    #[Test]
    public function filtra_por_tipo_de_registro(): void
    {
        $tenant = Tenant::factory()->create();

        AuditLog::log([
            'tenant_id'  => $tenant->id,
            'action'     => 'plan.updated',
            'model_type' => Plan::class,
            'model_id'   => 1,
            'description' => 'Cambio de precio',
        ]);
        AuditLog::log([
            'tenant_id'  => $tenant->id,
            'action'     => 'payment.created',
            'model_type' => \App\Models\Payment::class,
            'model_id'   => 1,
            'description' => 'Pago registrado',
        ]);

        Sanctum::actingAs($this->staffWith($tenant, [Permissions::VIEW_AUDIT_LOG]));

        $response = $this->getJson('/api/audit-logs?model_type=Plan')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Cambio de precio', $response->json('data.0.description'));
    }

    #[Test]
    public function el_extracto_de_saldo_reporta_el_descuadre_contra_la_cache(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id'        => $customer->id,
            'name'           => 'Alba',
            'last_name'      => 'Gutierrez',
            'status'         => true,
            'credit_balance' => 0,
        ]);

        CustomerCredit::adjust($customer->id, 34000, 0, 'Saldo inicial');

        // Se fuerza una divergencia escribiendo el escalar por fuera del libro,
        // que es justo lo que el extracto tiene que delatar.
        CustomerProfile::where('user_id', $customer->id)->update(['credit_balance' => 30000]);

        Sanctum::actingAs($this->staffWith($tenant, [Permissions::VIEW_AUDIT_LOG]));

        $response = $this->getJson("/api/billing/customers/{$customer->id}/credit-movements")->assertOk();

        $this->assertEquals(34000, $response->json('ledger_balance'));
        $this->assertEquals(30000, $response->json('cached_balance'));
        $this->assertEquals(4000, $response->json('discrepancy'),
            'El extracto debe delatar cuando el libro y la caché no coinciden.');
    }
}
