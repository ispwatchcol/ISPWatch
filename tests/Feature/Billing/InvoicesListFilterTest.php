<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Listado de facturas: filtros específicos por columna, orden y tamaño de
 * página, igual que en Recaudos.
 *
 * Antes sólo existían la búsqueda general, el estado, el tipo y el mes: para
 * revisar las emitidas había que confiar en que el término cayera en el número
 * o en el nombre del cliente, y no había forma de acotar por importe, por saldo
 * ni por fecha de vencimiento.
 */
class InvoicesListFilterTest extends TestCase
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
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
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

    private function invoice(User $customer, array $attributes = []): Invoice
    {
        static $consecutivo = 0;
        $consecutivo++;

        return Invoice::create(array_merge([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $customer->id,
            'number'       => 'FAC-' . str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT),
            'issue_date'   => '2026-07-01',
            'due_date'     => '2026-07-10',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'invoice_type' => 'monthly',
            'total'        => 50000,
            'balance_due'  => 50000,
            'status'       => 'issued',
        ], $attributes));
    }

    #[Test]
    public function pagina_con_el_tamano_pedido(): void
    {
        Sanctum::actingAs($this->staff);
        $customer = $this->customer('Juan', 'Pérez');

        foreach (range(1, 25) as $i) {
            $this->invoice($customer);
        }

        $response = $this->getJson('/api/billing/invoices?per_page=10&period=');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 25)
            ->assertJsonPath('last_page', 3);

        $page1 = collect($response->json('data'))->pluck('id');
        $page2 = collect($this->getJson('/api/billing/invoices?per_page=10&page=2')->json('data'))->pluck('id');

        $this->assertCount(10, $page2);
        $this->assertEmpty($page1->intersect($page2), 'Las páginas no deben repetir facturas.');
    }

    #[Test]
    public function filtra_por_numero_de_factura(): void
    {
        Sanctum::actingAs($this->staff);
        $customer = $this->customer('Juan', 'Pérez');

        $this->invoice($customer, ['number' => 'FAC-2026-0042']);
        $this->invoice($customer, ['number' => 'FAC-2026-0099']);

        $this->getJson('/api/billing/invoices?number=0042')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'FAC-2026-0042');
    }

    #[Test]
    public function filtra_por_cliente_incluyendo_nombre_completo_y_cedula(): void
    {
        Sanctum::actingAs($this->staff);
        $juan  = $this->customer('Juan', 'Pérez', '1122334455');
        $maria = $this->customer('María', 'Gómez', '9988776655');

        $this->invoice($juan,  ['number' => 'DE-JUAN']);
        $this->invoice($maria, ['number' => 'DE-MARIA']);

        // Nombre completo: no coincide con ninguna columna por separado.
        $this->getJson('/api/billing/invoices?customer=' . urlencode('juan pérez'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'DE-JUAN');

        $this->getJson('/api/billing/invoices?customer=9988776655')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'DE-MARIA');
    }

    #[Test]
    public function filtra_por_rango_de_total_y_de_saldo(): void
    {
        Sanctum::actingAs($this->staff);
        $customer = $this->customer('Juan', 'Pérez');

        $this->invoice($customer, ['number' => 'BAJA',  'total' => 10000, 'balance_due' => 0]);
        $this->invoice($customer, ['number' => 'MEDIA', 'total' => 50000, 'balance_due' => 50000]);
        $this->invoice($customer, ['number' => 'ALTA',  'total' => 90000, 'balance_due' => 90000]);

        $this->getJson('/api/billing/invoices?total_min=20000&total_max=60000')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'MEDIA');

        // Saldo mínimo 1: deja fuera las que ya no deben nada.
        $conSaldo = collect($this->getJson('/api/billing/invoices?balance_min=1')->json('data'))
            ->pluck('number')
            ->all();

        $this->assertEqualsCanonicalizing(['MEDIA', 'ALTA'], $conSaldo);
    }

    #[Test]
    public function filtra_por_rango_de_vencimiento(): void
    {
        Sanctum::actingAs($this->staff);
        $customer = $this->customer('Juan', 'Pérez');

        $this->invoice($customer, ['number' => 'ANTES',  'due_date' => '2026-06-30']);
        $this->invoice($customer, ['number' => 'DENTRO', 'due_date' => '2026-07-15']);
        $this->invoice($customer, ['number' => 'DESPUES','due_date' => '2026-08-05']);

        $this->getJson('/api/billing/invoices?due_from=2026-07-01&due_to=2026-07-31')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'DENTRO');
    }

    #[Test]
    public function filtra_por_estado_y_por_tipo(): void
    {
        Sanctum::actingAs($this->staff);
        $customer = $this->customer('Juan', 'Pérez');

        $this->invoice($customer, ['number' => 'PAGADA',    'status' => 'paid', 'balance_due' => 0]);
        $this->invoice($customer, ['number' => 'VENCIDA',   'status' => 'overdue']);
        $this->invoice($customer, ['number' => 'INSTALADA', 'invoice_type' => 'installation']);

        $this->getJson('/api/billing/invoices?status=overdue')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'VENCIDA');

        $this->getJson('/api/billing/invoices?invoice_type=installation')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'INSTALADA');
    }

    #[Test]
    public function ordena_por_la_columna_pedida(): void
    {
        Sanctum::actingAs($this->staff);
        $customer = $this->customer('Juan', 'Pérez');

        $this->invoice($customer, ['number' => 'B', 'total' => 30000]);
        $this->invoice($customer, ['number' => 'A', 'total' => 10000]);
        $this->invoice($customer, ['number' => 'C', 'total' => 20000]);

        $orden = collect($this->getJson('/api/billing/invoices?sort_by=total&sort_dir=asc')->json('data'))
            ->pluck('number')
            ->all();

        $this->assertEquals(['A', 'C', 'B'], $orden);
    }

    #[Test]
    public function el_summary_responde_a_los_filtros_por_columna(): void
    {
        Sanctum::actingAs($this->staff);
        $customer = $this->customer('Juan', 'Pérez');

        $this->invoice($customer, ['number' => 'CHICA', 'total' => 10000, 'balance_due' => 10000]);
        $this->invoice($customer, ['number' => 'GRANDE','total' => 90000, 'balance_due' => 40000]);
        // Anulada: nunca entra en el dinero, aunque cumpla el filtro.
        $this->invoice($customer, ['number' => 'NULA',  'total' => 90000, 'balance_due' => 90000, 'status' => 'void']);

        $this->getJson('/api/billing/invoices?total_min=50000')
            ->assertStatus(200)
            ->assertJsonPath('summary.count', 1)
            ->assertJsonPath('summary.total', 90000)
            ->assertJsonPath('summary.balance_due', 40000);
    }

    #[Test]
    public function rechaza_una_columna_de_orden_desconocida(): void
    {
        Sanctum::actingAs($this->staff);

        // Sin lista blanca, `sort_by` entra crudo en el ORDER BY.
        $this->getJson('/api/billing/invoices?sort_by=' . urlencode('(select 1)'))
            ->assertStatus(422);
    }

    #[Test]
    public function nunca_muestra_facturas_de_otro_tenant(): void
    {
        Sanctum::actingAs($this->staff);
        $mio = $this->customer('Juan', 'Pérez');
        $this->invoice($mio, ['number' => 'MIA']);

        $otroTenant = Tenant::factory()->create();
        $ajeno = User::factory()->create(['tenant_id' => $otroTenant->id]);
        Invoice::create([
            'tenant_id'    => $otroTenant->id,
            'customer_id'  => $ajeno->id,
            'number'       => 'AJENA',
            'issue_date'   => '2026-07-01',
            'due_date'     => '2026-07-10',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'total'        => 50000,
            'balance_due'  => 50000,
            'status'       => 'issued',
        ]);

        // Ni siquiera pidiéndolo explícitamente por query param.
        $this->getJson('/api/billing/invoices?tenant=' . $otroTenant->id)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'MIA');
    }
}
