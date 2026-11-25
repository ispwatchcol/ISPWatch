<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TicketWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El cliente visto desde soporte (bitácora § 133): el buscador del alta y la
 * ficha técnica del ticket. Lo que más importa: el saldo sólo para quien ya ve
 * facturación, nunca credenciales, y nada de otro ISP.
 */
class SupportCustomerContextTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        Role::create(['name' => 'Superadmin global', 'code' => 'superadmin', 'permissions' => ['*'], 'tenant_id' => null]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id, 'tel' => '3110000000']);
        CustomerProfile::create([
            'user_id' => $this->customer->id, 'name' => 'Luis', 'last_name' => 'Pardo', 'status' => true,
            'cedula' => '1020304050', 'address' => 'Cra 5 # 10-20', 'ip_user' => '10.0.0.15',
            'service_status' => 'suspendido', 'pppoe_username' => 'luis.pardo', 'pppoe_password' => 'secreta',
        ]);
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(array $permisos): User
    {
        $rol = Role::create([
            'name' => 'Rol ' . uniqid(), 'code' => 'staff',
            'permissions' => $permisos, 'tenant_id' => $this->tenant->id,
        ]);

        return User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rol->id]);
    }

    private function ticket(string $estado): SupportTicket
    {
        return SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->customer->id,
            'subject' => 'Sin internet', 'status' => $estado, 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    #[Test]
    public function la_ficha_trae_lo_tecnico_y_los_tickets_abiertos_sin_credenciales(): void
    {
        $actual  = $this->ticket(TicketWorkflow::EN_INTERVENCION);
        $otro    = $this->ticket(TicketWorkflow::RADICADO);
        $this->ticket(TicketWorkflow::CERRADO);

        $datos = $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW]))
            ->getJson("/api/support/customer-context/{$this->customer->id}?exclude={$actual->id}")
            ->assertOk()->json();

        $this->assertSame('Cra 5 # 10-20', $datos['customer']['address']);
        $this->assertSame('10.0.0.15', $datos['customer']['ip_user']);
        $this->assertSame('suspendido', $datos['customer']['service_status']);
        $this->assertSame('3110000000', $datos['customer']['tel']);

        $this->assertSame([$otro->id], array_column($datos['open_tickets'], 'id'), 'Ni el propio ni el cerrado.');

        $json = json_encode($datos);
        $this->assertStringNotContainsString('secreta', $json);
        $this->assertStringNotContainsString('luis.pardo', $json);
    }

    #[Test]
    public function el_saldo_solo_lo_ve_quien_ya_ve_facturacion(): void
    {
        DB::table('invoices')->insert([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'number' => 'F-1', 'status' => Invoice::STATUS_OVERDUE, 'total' => 50000, 'balance_due' => 50000,
            'subtotal' => 50000, 'issue_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sinFacturacion = $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW]))
            ->getJson("/api/support/customer-context/{$this->customer->id}")->assertOk()->json();
        $this->assertNull($sinFacturacion['billing'], 'Un técnico no necesita saber cuánto debe el cliente.');

        $conFacturacion = $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW, Permissions::VIEW_BILLING]))
            ->getJson("/api/support/customer-context/{$this->customer->id}")->assertOk()->json();
        $this->assertSame(1, $conFacturacion['billing']['overdue_invoices']);
        $this->assertEquals(50000, $conFacturacion['billing']['balance_due']);
    }

    #[Test]
    public function el_cliente_de_otro_isp_no_existe(): void
    {
        $ajeno = Tenant::factory()->create();
        $clienteAjeno = User::factory()->create(['tenant_id' => $ajeno->id]);
        CustomerProfile::create(['user_id' => $clienteAjeno->id, 'name' => 'X', 'last_name' => 'Y', 'status' => true]);

        $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW]))
            ->getJson("/api/support/customer-context/{$clienteAjeno->id}")
            ->assertNotFound();
    }

    #[Test]
    public function el_buscador_del_alta_no_pide_view_clients_y_cuenta_los_abiertos(): void
    {
        $this->ticket(TicketWorkflow::RADICADO);
        $this->ticket(TicketWorkflow::CERRADO);

        $ajeno = Tenant::factory()->create();
        $clienteAjeno = User::factory()->create(['tenant_id' => $ajeno->id]);
        CustomerProfile::create(['user_id' => $clienteAjeno->id, 'name' => 'Ajeno', 'last_name' => 'Z', 'status' => true]);

        $filas = $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW, Permissions::TICKET_CREATE]))
            ->getJson('/api/support/customer-options')->assertOk()->json('data');

        $this->assertCount(1, $filas, 'Sólo los clientes del propio ISP.');
        $this->assertSame('1020304050', $filas[0]['cedula']);
        $this->assertSame('3110000000', $filas[0]['tel']);
        $this->assertSame(1, $filas[0]['open_tickets']);
        $this->assertArrayNotHasKey('pppoe_password', $filas[0]);
    }

    #[Test]
    public function el_buscador_exige_poder_abrir_o_editar_tickets(): void
    {
        $this->actingAs($this->usuarioCon([Permissions::TICKET_VIEW]))
            ->getJson('/api/support/customer-options')
            ->assertForbidden();
    }
}
