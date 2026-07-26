<?php

namespace Tests\Unit\Services;

use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Prospect;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Templates\PlaceholderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaceholderResolverTest extends TestCase
{
    use RefreshDatabase;

    private PlaceholderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PlaceholderResolver();
    }

    private function makeTenant(array $overrides = []): Tenant
    {
        return Tenant::factory()->create(array_merge([
            'legal_name'      => 'Internet Rápido S.A.S.',
            'trade_name'      => 'NetRápido',
            'nit'             => '900123456',
            'nit_verification_digit' => '7',
            'billing_address' => 'Cra 10 # 20-30',
            'billing_phone'   => '3001234567',
            'billing_email'   => 'facturacion@netrapido.test',
            'city'            => 'Ibagué',
        ], $overrides));
    }

    private function makeCustomer(Tenant $tenant, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'tenant_id'     => $tenant->id,
            'user_name'     => 'Juan',
            'user_lastname' => 'Pérez',
            'email'         => 'juan.perez@example.test',
            'tel'           => '3007654321',
        ], $overrides));
    }

    public function test_for_invoice_resolves_exactly_the_whitelisted_keys(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        CustomerProfile::create([
            'user_id'   => $customer->id,
            'name'      => 'Juan',
            'last_name' => 'Pérez',
            'cedula'    => '123456789',
            'address'   => 'Calle Falsa 123',
            'city'      => 'Ibagué',
        ]);

        $invoice = Invoice::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'invoice_type' => Invoice::TYPE_MONTHLY,
            'number'       => 'FAC-0001',
            'issue_date'   => '2026-07-01',
            'due_date'     => '2026-07-15',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'currency'     => 'COP',
            'subtotal'     => 100000,
            'tax'          => 19000,
            'total'        => 119000,
            'balance_due'  => 119000,
            'status'       => 'issued',
            'notes'        => 'Pago antes del vencimiento.',
        ]);

        $values = $this->resolver->forInvoice($invoice->fresh(['tenant', 'customer.customerProfile']));

        // El resolver debe producir exactamente las claves de la whitelist —
        // ni de más (fuga de datos no auditada) ni de menos (placeholder roto).
        $this->assertEqualsCanonicalizing(
            array_keys(config('document_placeholders.invoice')),
            array_keys($values)
        );

        $this->assertSame('Internet Rápido S.A.S.', $values['empresa.nombre']);
        $this->assertSame('900123456-7', $values['empresa.nit']);
        $this->assertSame('Juan Pérez', $values['cliente.nombre']);
        $this->assertSame('123456789', $values['cliente.cedula']);
        $this->assertSame('FAC-0001', $values['factura.numero']);
        $this->assertSame('119,000.00', $values['factura.total']);
        $this->assertSame('01/07/2026 al 31/07/2026', $values['factura.periodo']);
    }

    public function test_for_contract_resolves_exactly_the_whitelisted_keys(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $profile = CustomerProfile::create([
            'user_id'   => $customer->id,
            'name'      => 'Juan',
            'last_name' => 'Pérez',
            'cedula'    => '987654321',
            'address'   => 'Av. Siempre Viva 742',
            'ip_user'   => '10.0.0.5',
        ]);
        $plan = Plan::factory()->create([
            'tenant_id'    => $tenant->id,
            'name'         => 'Plan 50MB',
            'speed_down'   => '50',
            'speed_up'     => '20',
            'cost_product' => 65000,
        ]);

        $values = $this->resolver->forContract($customer, $profile, $tenant, $plan, '25/07/2026');

        $this->assertEqualsCanonicalizing(
            array_keys(config('document_placeholders.contract')),
            array_keys($values)
        );

        $this->assertSame('Internet Rápido S.A.S.', $values['empresa.nombre']);
        $this->assertSame('10.0.0.5', $values['cliente.ip']);
        $this->assertSame('Plan 50MB', $values['plan.nombre']);
        $this->assertSame('65.000', $values['plan.valor_mensual']);
        $this->assertSame('25/07/2026', $values['contrato.fecha']);
    }

    public function test_for_installation_falls_back_to_prospect_when_there_is_no_customer(): void
    {
        $tenant = $this->makeTenant();
        $prospect = Prospect::create([
            'tenant_id' => $tenant->id,
            'name'      => 'María',
            'last_name' => 'Gómez',
            'cedula'    => '111222333',
            'address'   => 'Diagonal 5 # 6-7',
        ]);
        // customer_installations.customer_id es NOT NULL a nivel de esquema
        // (columna heredada de antes de que existiera prospect_id), aunque el
        // flujo de prospectos en producción también deja este registro sin
        // vínculo real de negocio con ese customer. Se crea uno solo para
        // satisfacer la restricción de BD; el resolver recibe `null` para
        // $customer explícitamente más abajo, que es lo que de verdad ejercita
        // la rama de fallback a prospecto.
        $installation = CustomerInstallation::create([
            'tenant_id'      => $tenant->id,
            'customer_id'    => $this->makeCustomer($tenant)->id,
            'prospect_id'    => $prospect->id,
            'scheduled_date' => '2026-07-25',
            'address'        => 'Diagonal 5 # 6-7',
            'equipment'      => 'ONU + Router TP-Link',
            'notes'          => 'Instalación en segundo piso.',
            'status'         => 'pendiente',
        ]);

        $values = $this->resolver->forInstallation(
            $installation,
            null,
            null,
            $prospect,
            $tenant,
            null,
            '25/07/2026'
        );

        $this->assertEqualsCanonicalizing(
            array_keys(config('document_placeholders.installation')),
            array_keys($values)
        );

        $this->assertSame('María Gómez', $values['cliente.nombre']);
        $this->assertSame('111222333', $values['cliente.cedula']);
        $this->assertSame((string) $installation->id, $values['instalacion.numero']);
        $this->assertSame('ONU + Router TP-Link', $values['instalacion.equipo']);
    }

    public function test_apply_replaces_known_tokens_and_blanks_unknown_ones(): void
    {
        $html = '<p>Cliente: {{cliente.nombre}}, Factura: {{factura.numero}}, ' .
            'Token roto: {{no.existe}}</p>';

        $result = $this->resolver->apply($html, [
            'cliente.nombre' => 'Juan Pérez',
            'factura.numero' => 'FAC-0001',
        ]);

        $this->assertSame(
            '<p>Cliente: Juan Pérez, Factura: FAC-0001, Token roto: </p>',
            $result
        );
    }
}
