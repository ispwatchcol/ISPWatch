<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Listado de recaudos: paginación real y filtros específicos por columna.
 *
 * El endpoint solo tenía búsqueda general y devolvía siempre la primera página,
 * así que la vista mostraba un puñado de recaudos y el resto solo aparecía
 * escribiendo en el buscador.
 */
class PaymentsListFilterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);

        $this->staff = User::factory()->create([
            'tenant_id'     => $this->tenant->id,
            'role_id'       => $role->id,
            'user_name'     => 'Laura',
            'user_lastname' => 'Cajera',
        ]);
    }

    private function customer(string $name, string $lastName, ?string $cedula = null): User
    {
        $customer = User::factory()->create(['tenant_id' => $this->tenant->id]);

        CustomerProfile::create([
            'user_id'   => $customer->id,
            'name'      => $name,
            'last_name' => $lastName,
            'cedula'    => $cedula,
            'status'    => true,
        ]);

        return $customer;
    }

    private function payment(User $customer, array $attributes = []): Payment
    {
        return Payment::create(array_merge([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $customer->id,
            'amount'       => 50000,
            'payment_date' => '2026-07-15',
            'method'       => 'cash',
            'status'       => 'completed',
        ], $attributes));
    }

    #[Test]
    public function lista_todos_los_recaudos_paginados_sin_necesidad_de_buscar(): void
    {
        Sanctum::actingAs($this->staff);
        $customer = $this->customer('Juan', 'Pérez');

        foreach (range(1, 25) as $i) {
            $this->payment($customer, ['reference' => "REF-$i"]);
        }

        $response = $this->getJson('/api/billing/payments?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 25)
            ->assertJsonPath('last_page', 3);

        // La segunda página trae registros distintos: antes `page` se ignoraba.
        $page1 = collect($response->json('data'))->pluck('id');
        $page2 = collect($this->getJson('/api/billing/payments?per_page=10&page=2')->json('data'))->pluck('id');

        $this->assertCount(10, $page2);
        $this->assertEmpty($page1->intersect($page2), 'Las páginas no deben repetir recaudos.');
    }

    #[Test]
    public function filtra_por_rango_de_fechas(): void
    {
        Sanctum::actingAs($this->staff);
        $customer = $this->customer('Juan', 'Pérez');

        $this->payment($customer, ['payment_date' => '2026-06-30', 'reference' => 'VIEJO']);
        $this->payment($customer, ['payment_date' => '2026-07-10', 'reference' => 'DENTRO']);
        $this->payment($customer, ['payment_date' => '2026-08-01', 'reference' => 'NUEVO']);

        $response = $this->getJson('/api/billing/payments?date_from=2026-07-01&date_to=2026-07-31');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'DENTRO');
    }

    #[Test]
    public function filtra_por_rango_de_monto(): void
    {
        Sanctum::actingAs($this->staff);
        $customer = $this->customer('Juan', 'Pérez');

        $this->payment($customer, ['amount' => 10000, 'reference' => 'BAJO']);
        $this->payment($customer, ['amount' => 50000, 'reference' => 'MEDIO']);
        $this->payment($customer, ['amount' => 90000, 'reference' => 'ALTO']);

        $this->getJson('/api/billing/payments?amount_min=20000&amount_max=60000')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'MEDIO');
    }

    #[Test]
    public function filtra_por_cliente_incluyendo_nombre_completo_y_cedula(): void
    {
        Sanctum::actingAs($this->staff);
        $juan  = $this->customer('Juan', 'Pérez', '1122334455');
        $maria = $this->customer('María', 'Gómez', '9988776655');

        $this->payment($juan,  ['reference' => 'DE-JUAN']);
        $this->payment($maria, ['reference' => 'DE-MARIA']);

        // Nombre completo: no coincide con ninguna columna por separado.
        $this->getJson('/api/billing/payments?customer=' . urlencode('juan pérez'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'DE-JUAN');

        $this->getJson('/api/billing/payments?customer=9988776655')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'DE-MARIA');
    }

    #[Test]
    public function filtra_por_referencia_metodo_y_quien_lo_registro(): void
    {
        Sanctum::actingAs($this->staff);
        $customer = $this->customer('Juan', 'Pérez');

        $this->payment($customer, ['reference' => 'TRX-9001', 'method' => 'nequi', 'created_by' => $this->staff->id]);
        $this->payment($customer, ['reference' => 'TRX-9002', 'method' => 'cash']);

        $this->getJson('/api/billing/payments?reference=9001')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'TRX-9001');

        $this->getJson('/api/billing/payments?method=cash')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'TRX-9002');

        $this->getJson('/api/billing/payments?registered_by=' . urlencode('Laura Cajera'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'TRX-9001');

        // Los pagos automáticos no tienen creator.
        $this->getJson('/api/billing/payments?registered_by=sistema')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'TRX-9002');
    }

    #[Test]
    public function filtra_por_numero_de_factura_afectada(): void
    {
        Sanctum::actingAs($this->staff);
        $customer = $this->customer('Juan', 'Pérez');

        $conFactura = $this->payment($customer, ['reference' => 'CON-FACTURA']);
        $this->payment($customer, ['reference' => 'SIN-FACTURA']);

        $invoice = Invoice::create([
            'customer_id'  => $customer->id,
            'tenant_id'    => $this->tenant->id,
            'issue_date'   => '2026-07-01',
            'due_date'     => '2026-07-10',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'total'        => 50000,
            'balance_due'  => 0,
            'status'       => 'paid',
            'number'       => 'INV-2026-0042',
        ]);
        PaymentAllocation::create([
            'payment_id' => $conFactura->id,
            'invoice_id' => $invoice->id,
            'amount'     => 50000,
        ]);

        $this->getJson('/api/billing/payments?invoice=0042')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'CON-FACTURA');
    }

    #[Test]
    public function ordena_por_la_columna_pedida(): void
    {
        Sanctum::actingAs($this->staff);
        $customer = $this->customer('Juan', 'Pérez');

        $this->payment($customer, ['amount' => 30000, 'reference' => 'B']);
        $this->payment($customer, ['amount' => 10000, 'reference' => 'A']);
        $this->payment($customer, ['amount' => 20000, 'reference' => 'C']);

        $amounts = collect($this->getJson('/api/billing/payments?sort_by=amount&sort_dir=asc')->json('data'))
            ->pluck('reference')
            ->all();

        $this->assertEquals(['A', 'C', 'B'], $amounts);
    }

    #[Test]
    public function nunca_muestra_recaudos_de_otro_tenant(): void
    {
        Sanctum::actingAs($this->staff);
        $mio = $this->customer('Juan', 'Pérez');
        $this->payment($mio, ['reference' => 'MIO']);

        $otroTenant = Tenant::factory()->create();
        $ajeno = User::factory()->create(['tenant_id' => $otroTenant->id]);
        Payment::create([
            'tenant_id'    => $otroTenant->id,
            'customer_id'  => $ajeno->id,
            'amount'       => 50000,
            'payment_date' => '2026-07-15',
            'method'       => 'cash',
            'status'       => 'completed',
            'reference'    => 'AJENO',
        ]);

        // Ni siquiera pidiéndolo explícitamente por query param.
        $this->getJson('/api/billing/payments?tenant=' . $otroTenant->id)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'MIO');
    }
}
