<?php

namespace Tests\Feature\Documents;

use App\Models\DocumentTemplate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Characterization tests for GET /api/billing/invoices/{id}/pdf, written
 * against the CURRENT Pdf::loadView('billing.invoice_pdf', ...) call inside
 * BillingController::downloadPdf — BEFORE it gets swapped to
 * App\Services\Templates\TemplateRenderer::renderInvoice(). Re-run unchanged
 * after that swap: if they still pass, the swap was transparent for every
 * tenant without a custom template (100% of tenants today).
 */
class BillingPdfDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        $customer = User::factory()->create([
            'tenant_id'     => $this->tenant->id,
            'user_name'     => 'Juan',
            'user_lastname' => 'Pérez',
        ]);

        $this->invoice = Invoice::create([
            'tenant_id'    => $this->tenant->id,
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
        ]);

        InvoiceItem::create([
            'invoice_id'  => $this->invoice->id,
            'description' => 'Plan mensual',
            'quantity'    => 1,
            'unit_price'  => 100000,
            'amount'      => 100000,
        ]);
    }

    /**
     * Pins the exact view name + invoice instance passed into Pdf::loadView.
     * This is the assertion that must keep passing, unchanged, once
     * downloadPdf() is rewired to call TemplateRenderer::renderInvoice().
     */
    public function test_loads_the_legacy_invoice_view_with_the_requested_invoice(): void
    {
        Sanctum::actingAs($this->user);

        $fakePdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $fakePdf->shouldReceive('download')
            ->once()
            ->with('Invoice-' . $this->invoice->number . '.pdf')
            ->andReturn(response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']));

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(fn ($view, $data) => $view === 'billing.invoice_pdf' && $data['invoice']->is($this->invoice))
            ->andReturn($fakePdf);

        $response = $this->getJson("/api/billing/invoices/{$this->invoice->id}/pdf");

        $response->assertStatus(200);
    }

    /**
     * No mocks: proves the endpoint really returns a downloadable, valid PDF
     * today, end to end.
     */
    public function test_end_to_end_download_returns_a_real_pdf(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->get("/api/billing/invoices/{$this->invoice->id}/pdf");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    /**
     * Now that downloadPdf() is wired to TemplateRenderer: a tenant with an
     * active invoice template gets the custom shell, with placeholders
     * resolved and the body sanitized.
     */
    public function test_uses_the_custom_shell_when_tenant_has_an_active_template(): void
    {
        Sanctum::actingAs($this->user);

        DocumentTemplate::create([
            'tenant_id' => $this->tenant->id,
            'type'      => DocumentTemplate::TYPE_INVOICE,
            'body_html' => '<p>Gracias por su pago, factura {{factura.numero}}.</p><script>alert(1)</script>',
            'is_active' => true,
        ]);

        $fakePdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $fakePdf->shouldReceive('download')->once()->andReturn(response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']));

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) {
                return $view === 'documents.shells.invoice_shell'
                    && str_contains($data['body'], 'Gracias por su pago, factura FAC-0001.')
                    && !str_contains($data['body'], '<script>');
            })
            ->andReturn($fakePdf);

        $response = $this->getJson("/api/billing/invoices/{$this->invoice->id}/pdf");

        $response->assertStatus(200);
    }

    /**
     * No mocks: the custom shell path also compiles and downloads a real,
     * valid PDF end to end.
     */
    public function test_custom_template_end_to_end_still_produces_a_valid_pdf(): void
    {
        Sanctum::actingAs($this->user);

        DocumentTemplate::create([
            'tenant_id' => $this->tenant->id,
            'type'      => DocumentTemplate::TYPE_INVOICE,
            'body_html' => '<p>Nota personalizada del tenant.</p>',
            'is_active' => true,
        ]);

        $response = $this->get("/api/billing/invoices/{$this->invoice->id}/pdf");

        $response->assertStatus(200);
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    /**
     * A template that exists but is deactivated must not affect the
     * downloaded PDF — same legacy view as if it never existed.
     */
    public function test_inactive_template_still_uses_the_legacy_view(): void
    {
        Sanctum::actingAs($this->user);

        DocumentTemplate::create([
            'tenant_id' => $this->tenant->id,
            'type'      => DocumentTemplate::TYPE_INVOICE,
            'body_html' => '<p>Esto nunca debería aparecer.</p>',
            'is_active' => false,
        ]);

        $fakePdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $fakePdf->shouldReceive('download')->once()->andReturn(response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']));

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(fn ($view, $data) => $view === 'billing.invoice_pdf' && $data['invoice']->is($this->invoice))
            ->andReturn($fakePdf);

        $response = $this->getJson("/api/billing/invoices/{$this->invoice->id}/pdf");

        $response->assertStatus(200);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson("/api/billing/invoices/{$this->invoice->id}/pdf");

        $response->assertStatus(401);
    }
}
