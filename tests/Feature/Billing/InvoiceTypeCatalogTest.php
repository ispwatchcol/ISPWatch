<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Catálogo administrable de tipos de factura: el operador crea "Equipos", "TV",
 * "Reconexión"... sin esperar un despliegue, y los cuatro tipos del sistema
 * (mensual, instalación, adicional, cargo de ticket) quedan intocables porque
 * la facturación automática depende de sus slugs.
 */
class InvoiceTypeCatalogTest extends TestCase
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

    private function customer(): User
    {
        $customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $customer->id, 'name' => 'Axel', 'last_name' => 'Cañón', 'status' => true,
        ]);

        return $customer;
    }

    // ── Catálogo ─────────────────────────────────────────────────────────

    #[Test]
    public function lista_los_tipos_del_sistema_y_los_propios(): void
    {
        InvoiceType::create([
            'tenant_id' => $this->tenant->id,
            'slug'      => 'equipos',
            'name'      => 'Equipos',
            'color'     => 'cyan',
        ]);

        // Tipo de OTRO tenant: no debe aparecer.
        $other = Tenant::factory()->create();
        InvoiceType::create([
            'tenant_id' => $other->id, 'slug' => 'ajeno', 'name' => 'Ajeno', 'color' => 'rose',
        ]);

        $response = $this->actingAs($this->staff)->getJson('/api/billing/invoice-types');

        $response->assertOk();
        $slugs = collect($response->json())->pluck('slug');

        $this->assertEqualsCanonicalizing(
            ['monthly', 'installation', 'additional', 'service_charge', 'equipos'],
            $slugs->all()
        );
    }

    #[Test]
    public function crea_un_tipo_propio_con_slug_derivado_del_nombre(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/billing/invoice-types', [
            'name'  => 'Factura de Televisión',
            'color' => 'purple',
        ]);

        $response->assertCreated()->assertJson([
            'slug'      => 'factura_de_television',
            'name'      => 'Factura de Televisión',
            'is_system' => false,
        ]);

        $this->assertDatabaseHas('invoice_types', [
            'tenant_id' => $this->tenant->id,
            'slug'      => 'factura_de_television',
        ]);
    }

    #[Test]
    public function rechaza_un_nombre_que_choca_con_un_tipo_del_sistema(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/billing/invoice-types', ['name' => 'monthly'])
            ->assertStatus(422);
    }

    #[Test]
    public function no_permite_editar_ni_borrar_los_tipos_del_sistema(): void
    {
        $system = InvoiceType::where('slug', 'monthly')->firstOrFail();

        $this->actingAs($this->staff)
            ->putJson("/api/billing/invoice-types/{$system->id}", ['name' => 'Otro nombre'])
            ->assertStatus(403);

        $this->actingAs($this->staff)
            ->deleteJson("/api/billing/invoice-types/{$system->id}")
            ->assertStatus(403);
    }

    #[Test]
    public function no_borra_un_tipo_que_ya_tiene_facturas(): void
    {
        $type = InvoiceType::create([
            'tenant_id' => $this->tenant->id, 'slug' => 'equipos', 'name' => 'Equipos', 'color' => 'cyan',
        ]);

        Invoice::create([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->customer()->id,
            'number'       => '00000001',
            'invoice_type' => 'equipos',
            'issue_date'   => now(),
            'due_date'     => now()->addDays(5),
            'period_start' => now()->startOfMonth(),
            'period_end'   => now()->endOfMonth(),
            'total'        => 100000,
            'balance_due'  => 100000,
            'status'       => 'issued',
        ]);

        $this->actingAs($this->staff)
            ->deleteJson("/api/billing/invoice-types/{$type->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('invoice_types', ['id' => $type->id]);
    }

    // ── Uso del tipo al facturar ─────────────────────────────────────────

    #[Test]
    public function una_factura_manual_se_puede_emitir_con_un_tipo_propio(): void
    {
        InvoiceType::create([
            'tenant_id' => $this->tenant->id, 'slug' => 'equipos', 'name' => 'Equipos', 'color' => 'cyan',
        ]);

        $response = $this->actingAs($this->staff)->postJson('/api/billing/invoices', [
            'customer_id'  => $this->customer()->id,
            'tenant_id'    => $this->tenant->id,
            'invoice_type' => 'equipos',
            'issue_date'   => now()->toDateString(),
            'due_date'     => now()->addDays(5)->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end'   => now()->endOfMonth()->toDateString(),
            'total'        => 250000,
        ]);

        $response->assertCreated()->assertJson(['invoice_type' => 'equipos']);
    }

    #[Test]
    public function un_cargo_adicional_se_puede_emitir_con_un_tipo_propio(): void
    {
        InvoiceType::create([
            'tenant_id' => $this->tenant->id, 'slug' => 'tv', 'name' => 'TV', 'color' => 'orange',
        ]);

        $response = $this->actingAs($this->staff)->postJson('/api/billing/additional-charges', [
            'customer_id'  => $this->customer()->id,
            'invoice_type' => 'tv',
            'items'        => [
                ['description' => 'Decodificador', 'quantity' => 1, 'unit_price' => 120000],
            ],
        ]);

        $response->assertCreated();
        $this->assertSame('tv', $response->json('invoice.invoice_type'));
    }

    #[Test]
    public function rechaza_el_tipo_de_otro_tenant(): void
    {
        $other = Tenant::factory()->create();
        InvoiceType::create([
            'tenant_id' => $other->id, 'slug' => 'ajeno', 'name' => 'Ajeno', 'color' => 'rose',
        ]);

        $this->actingAs($this->staff)->postJson('/api/billing/invoices', [
            'customer_id'  => $this->customer()->id,
            'tenant_id'    => $this->tenant->id,
            'invoice_type' => 'ajeno',
            'issue_date'   => now()->toDateString(),
            'due_date'     => now()->addDays(5)->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end'   => now()->endOfMonth()->toDateString(),
            'total'        => 10000,
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_type');
    }

    #[Test]
    public function rechaza_un_tipo_desactivado(): void
    {
        InvoiceType::create([
            'tenant_id' => $this->tenant->id,
            'slug'      => 'equipos',
            'name'      => 'Equipos',
            'color'     => 'cyan',
            'is_active' => false,
        ]);

        $this->actingAs($this->staff)->postJson('/api/billing/additional-charges', [
            'customer_id'  => $this->customer()->id,
            'invoice_type' => 'equipos',
            'items'        => [['description' => 'Antena', 'quantity' => 1, 'unit_price' => 50000]],
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_type');
    }

    #[Test]
    public function una_factura_manual_sin_tipo_sigue_siendo_mensual(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/billing/invoices', [
            'customer_id'  => $this->customer()->id,
            'tenant_id'    => $this->tenant->id,
            'issue_date'   => now()->toDateString(),
            'due_date'     => now()->addDays(5)->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end'   => now()->endOfMonth()->toDateString(),
            'total'        => 50000,
        ]);

        $response->assertCreated()->assertJson(['invoice_type' => Invoice::TYPE_MONTHLY]);
    }
}
