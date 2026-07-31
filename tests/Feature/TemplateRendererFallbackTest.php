<?php

namespace Tests\Feature;

use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\DocumentTemplate;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Templates\TemplateRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the is_active business rule end to end:
 *   - sin fila                => plantilla legacy/base.
 *   - fila con is_active=true  => plantilla custom (placeholders + saneada).
 *   - fila con is_active=false => plantilla legacy/base, borrador conservado.
 *
 * "Equivalencia" aquí se valida por contenido esperado (qué vista se eligió,
 * qué texto quedó resuelto/saneado en el body) y por que el HTML de cada
 * shell compila a un PDF válido — no por comparación byte a byte contra el
 * PDF legacy. La revisión visual final de los 3 shells queda pendiente de
 * inspección manual (ver resumen de la fase).
 */
class TemplateRendererFallbackTest extends TestCase
{
    use RefreshDatabase;

    private TemplateRenderer $renderer;

    private const SAMPLE_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

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
            'user_id'   => $customer->id,
            'name'      => 'Juan',
            'last_name' => 'Pérez',
            'cedula'    => '123456789',
            'address'   => 'Calle 1',
        ]);

        return $customer;
    }

    private function makeInvoice(Tenant $tenant, User $customer): Invoice
    {
        return Invoice::create([
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
        ])->fresh(['tenant', 'customer.customerProfile', 'items']);
    }

    // ── Reglas de negocio (Pdf::loadView mockeado: valida decisión + contenido) ──

    public function test_renders_legacy_invoice_view_when_tenant_has_no_template_row(): void
    {
        $tenant = $this->makeTenant();
        $invoice = $this->makeInvoice($tenant, $this->makeCustomer($tenant));
        $sentinel = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(fn ($view, $data) => $view === 'billing.invoice_pdf' && $data['invoice']->is($invoice))
            ->andReturn($sentinel);

        $this->assertSame($sentinel, $this->renderer->renderInvoice($invoice));
    }

    public function test_renders_custom_invoice_body_when_template_is_active(): void
    {
        $tenant = $this->makeTenant();
        $invoice = $this->makeInvoice($tenant, $this->makeCustomer($tenant));

        DocumentTemplate::create([
            'tenant_id' => $tenant->id,
            'type'      => DocumentTemplate::TYPE_INVOICE,
            'body_html' => '<p>Gracias {{cliente.nombre}} {{cliente.apellido}}, su factura {{factura.numero}}</p><script>alert(1)</script>',
            'is_active' => true,
        ]);

        $sentinel = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) {
                $this->assertSame('documents.shells.invoice_shell', $view);
                $this->assertStringContainsString('Gracias Juan Pérez, su factura FAC-0001', $data['body']);
                $this->assertStringNotContainsString('<script>', $data['body']);

                return true;
            })
            ->andReturn($sentinel);

        $this->assertSame($sentinel, $this->renderer->renderInvoice($invoice));
    }

    public function test_inactive_template_falls_back_to_legacy_but_keeps_the_draft(): void
    {
        $tenant = $this->makeTenant();
        $invoice = $this->makeInvoice($tenant, $this->makeCustomer($tenant));

        $template = DocumentTemplate::create([
            'tenant_id' => $tenant->id,
            'type'      => DocumentTemplate::TYPE_INVOICE,
            'body_html' => '<p>Borrador guardado por el tenant</p>',
            'is_active' => false,
        ]);

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(fn ($view) => $view === 'billing.invoice_pdf')
            ->andReturn(\Mockery::mock(\Barryvdh\DomPDF\PDF::class));

        $this->renderer->renderInvoice($invoice);

        $this->assertDatabaseHas('document_templates', [
            'id'        => $template->id,
            'is_active' => false,
            'body_html' => '<p>Borrador guardado por el tenant</p>',
        ]);
    }

    // ── Smoke tests reales (sin mockear Pdf): confirman que los 3 shells ──
    // ── compilan sin errores de Blade y producen un PDF válido.          ──

    public function test_invoice_legacy_and_custom_paths_both_compile_to_a_valid_pdf(): void
    {
        $tenant = $this->makeTenant();
        $invoice = $this->makeInvoice($tenant, $this->makeCustomer($tenant));

        $this->assertPdfBytes($this->renderer->renderInvoice($invoice)->output());

        DocumentTemplate::create([
            'tenant_id' => $tenant->id,
            'type'      => DocumentTemplate::TYPE_INVOICE,
            'body_html' => '<p>Nota adicional: {{empresa.nombre}}</p>',
            'is_active' => true,
        ]);

        $this->assertPdfBytes($this->renderer->renderInvoice($invoice->fresh(['tenant', 'customer.customerProfile', 'items']))->output());
    }

    public function test_contract_legacy_and_custom_paths_both_compile_to_a_valid_pdf(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $profile = $customer->customerProfile;
        $plan = Plan::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Plan 50MB']);

        $this->assertPdfBytes(
            $this->renderer->renderContract($customer, $profile, $tenant, $plan, self::SAMPLE_PNG, '25/07/2026')->output()
        );

        DocumentTemplate::create([
            'tenant_id' => $tenant->id,
            'type'      => DocumentTemplate::TYPE_CONTRACT,
            'body_html' => '<p>Cláusula adicional del proveedor para {{cliente.nombre}}.</p>',
            'is_active' => true,
        ]);

        $this->assertPdfBytes(
            $this->renderer->renderContract($customer, $profile, $tenant, $plan, self::SAMPLE_PNG, '25/07/2026')->output()
        );
    }

    public function test_installation_legacy_view_is_selected_when_there_is_no_template_row(): void
    {
        // NOTA: no se hace un render real (sin mockear Pdf) del path legacy
        // aquí a propósito. Se descubrió durante esta implementación que
        // documents/installation_sheet_pdf.blade.php (archivo preexistente,
        // no tocado en esta fase) falla al compilar cuando incluye el bloque
        // de plan (`$plan->speed_down`/`speed_up` con @if anidados en la
        // misma línea) — ver resumen de la fase. Es un bug preexistente,
        // fuera del alcance de Fase 1; este test solo verifica que
        // TemplateRenderer efectivamente delega a esa vista cuando el tenant
        // no tiene plantilla activa (la decisión, no la compilación del
        // legacy en sí).
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $installation = CustomerInstallation::create([
            'tenant_id'      => $tenant->id,
            'customer_id'    => $customer->id,
            'scheduled_date' => '2026-07-25',
            'address'        => 'Calle 1',
            'status'         => 'pendiente',
        ]);
        $sentinel = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(fn ($view) => $view === 'documents.installation_sheet_pdf')
            ->andReturn($sentinel);

        $result = $this->renderer->renderInstallationSheet(
            $installation, $customer, $customer->customerProfile, null, $tenant, null,
            collect(), null, null, null, self::SAMPLE_PNG, self::SAMPLE_PNG, '25/07/2026'
        );

        $this->assertSame($sentinel, $result);
    }

    public function test_installation_custom_shell_compiles_to_a_valid_pdf(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $profile = $customer->customerProfile;
        $installation = CustomerInstallation::create([
            'tenant_id'      => $tenant->id,
            'customer_id'    => $customer->id,
            'scheduled_date' => '2026-07-25',
            'address'        => 'Calle 1',
            'equipment'      => 'ONU + Router',
            'status'         => 'pendiente',
        ]);

        DocumentTemplate::create([
            'tenant_id' => $tenant->id,
            'type'      => DocumentTemplate::TYPE_INSTALLATION,
            'body_html' => '<p>Observación adicional para la orden {{instalacion.numero}}.</p>',
            'is_active' => true,
        ]);

        $plan = Plan::factory()->create([
            'tenant_id'  => $tenant->id,
            'name'       => 'Plan 50MB',
            'speed_down' => '50',
            'speed_up'   => '20',
        ]);

        $bytes = $this->renderer->renderInstallationSheet(
            $installation,
            $customer,
            $profile,
            null,
            $tenant,
            null,
            collect(),
            null,
            null,
            $plan,
            self::SAMPLE_PNG,
            self::SAMPLE_PNG,
            '25/07/2026'
        )->output();

        $this->assertPdfBytes($bytes);
    }

    private function assertPdfBytes(string $bytes): void
    {
        $this->assertStringStartsWith('%PDF-', $bytes);
    }
}
