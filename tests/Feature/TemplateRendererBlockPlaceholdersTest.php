<?php

namespace Tests\Feature;

use App\Models\CustomerDocument;
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
 * End to end (real DocumentTemplate row -> TemplateRenderer -> compiled
 * body) for the 4 block placeholders approved 2026-07-31:
 * factura.tabla_items, instalacion.fotos, instalacion.firma_cliente,
 * instalacion.firma_tecnico. Pdf::loadView is mocked only to intercept and
 * assert on the compiled $data['body'] — the compilation itself (sanitize +
 * scalar substitution + block splice) runs for real.
 */
class TemplateRendererBlockPlaceholdersTest extends TestCase
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

    public function test_invoice_template_with_items_table_block_renders_the_real_rows(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);

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

        DocumentTemplate::create([
            'tenant_id' => $tenant->id,
            'type'      => DocumentTemplate::TYPE_INVOICE,
            // El token aparece dos veces: una en un atributo (nunca debe
            // expandirse) y otra en contenido (sí debe expandirse) — mismo
            // caso adversarial acordado en el diseño.
            'body_html' => '<p title="ver {{factura.tabla_items}} completo">Detalle para {{cliente.nombre}}:</p>'
                . '<div>{{factura.tabla_items}}</div><script>alert(1)</script>',
            'is_active' => true,
        ]);

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) {
                $body = $data['body'];

                return $view === 'documents.shells.invoice_shell'
                    && str_contains($body, 'Plan Hogar 100MB')
                    && str_contains($body, 'Detalle para Juan:')
                    && !str_contains($body, '<script>')
                    // Solo una tabla real en todo el body — la del atributo
                    // nunca se expandió.
                    && substr_count($body, '<table') === 1
                    && !preg_match('/title="[^"]*<table/', $body);
            })
            ->andReturn(\Mockery::mock(\Barryvdh\DomPDF\PDF::class)->shouldIgnoreMissing(\Mockery::self()));

        $this->renderer->renderInvoice($invoice);
    }

    /**
     * Las firmas siguen siendo bloques; `instalacion.fotos` se RETIRÓ el
     * 2026-08-05 (las fotos se consultan en los documentos del cliente, y
     * dentro del PDF nunca llegaron a verse: ruta local vs. S3). Una plantilla
     * que todavía lo use se blanquea, como cualquier token desconocido —
     * nunca deja el marcador ni el texto crudo a la vista.
     */
    public function test_installation_template_renders_signature_blocks_and_blanks_the_retired_photo_block(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);

        $installation = CustomerInstallation::create([
            'tenant_id'      => $tenant->id,
            'customer_id'    => $customer->id,
            'scheduled_date' => '2026-07-25',
            'address'        => 'Calle 1',
            'status'         => 'completada',
        ]);

        $photo = CustomerDocument::create([
            'tenant_id'       => $tenant->id,
            'customer_id'     => $customer->id,
            'installation_id' => $installation->id,
            'type'            => 'instalacion',
            'file_name'       => 'fachada.jpg',
            'file_path'       => "instalaciones/{$installation->id}/fachada.jpg",
            'file_size'       => 1024,
            'mime_type'       => 'image/jpeg',
        ]);

        DocumentTemplate::create([
            'tenant_id' => $tenant->id,
            'type'      => DocumentTemplate::TYPE_INSTALLATION,
            'body_html' => '<h3>Evidencia fotográfica</h3><div>{{instalacion.fotos}}</div>'
                . '<p>Firmas: {{instalacion.firma_cliente}} y {{instalacion.firma_tecnico}}</p>',
            'is_active' => true,
        ]);

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) use ($photo) {
                $body = $data['body'];

                return $view === 'documents.shells.installation_shell'
                    && str_contains($body, 'data:image/png;base64,firma-cliente')
                    && str_contains($body, 'data:image/png;base64,firma-tecnico')
                    // El bloque retirado no deja ni la foto, ni el token, ni el marcador.
                    && !str_contains($body, $photo->file_path)
                    && !str_contains($body, 'instalacion.fotos')
                    && !str_contains($body, 'BLOCKMARK_');
            })
            ->andReturn(\Mockery::mock(\Barryvdh\DomPDF\PDF::class)->shouldIgnoreMissing(\Mockery::self()));

        $this->renderer->renderInstallationSheet(
            $installation,
            $customer,
            $customer->customerProfile,
            null,
            $tenant,
            null,
            null,
            null,
            null,
            'data:image/png;base64,firma-cliente',
            'data:image/png;base64,firma-tecnico',
            now()->format('d/m/Y H:i')
        );
    }

    /**
     * Un tenant puede romper un token de bloque a mano de dos formas
     * distintas, con dos resultados distintos (verificado empíricamente
     * antes de escribir este test, ver resumen de la sesión):
     *   - typo en el nombre ({{factura.tabla_item}}, sin la "s") — sintaxis
     *     {{...}} válida pero nombre desconocido para PlaceholderResolver::
     *     apply(), así que se blanquea a '' (MISMA regla ya establecida para
     *     cualquier placeholder escalar desconocido, ej. {{no.existe}} —
     *     decisión confirmada explícitamente, no cambiar sin aprobación).
     *   - llave de cierre faltante ({{factura.tabla_items sin "}}") — nunca
     *     hace match con el regex de apply(), así que sobrevive como texto
     *     plano visible, tal cual lo escribió el tenant.
     * En ningún caso debe: romper el render, disparar una excepción, o
     * afectar al placeholder de bloque correcto ni al escalar que están
     * justo al lado.
     */
    public function test_a_hand_broken_block_token_never_breaks_the_rest_of_the_document(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);

        $invoice = Invoice::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'invoice_type' => Invoice::TYPE_MONTHLY,
            'number'       => 'FAC-0002',
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

        DocumentTemplate::create([
            'tenant_id' => $tenant->id,
            'type'      => DocumentTemplate::TYPE_INVOICE,
            'body_html' => '<p>Hola {{cliente.nombre}}, typo: {{factura.tabla_item}}, roto: {{factura.tabla_items</p>'
                . '<div>{{factura.tabla_items}}</div>',
            'is_active' => true,
        ]);

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) {
                $body = $data['body'];

                return $view === 'documents.shells.invoice_shell'
                    // El escalar de al lado se resuelve sin problema.
                    && str_contains($body, 'Hola Juan,')
                    // El typo se blanquea (no queda "{{factura.tabla_item}}" visible).
                    && str_contains($body, 'typo: ,')
                    && !str_contains($body, '{{factura.tabla_item}}')
                    // La llave faltante sobrevive tal cual, visible.
                    && str_contains($body, 'roto: {{factura.tabla_items')
                    // El bloque correcto de al lado se resuelve igual de bien.
                    && str_contains($body, '<table')
                    && str_contains($body, 'Plan Hogar 100MB')
                    // Nada de marcadores internos filtrados al HTML final.
                    && !str_contains($body, 'BLOCKMARK_');
            })
            ->andReturn(\Mockery::mock(\Barryvdh\DomPDF\PDF::class)->shouldIgnoreMissing(\Mockery::self()));

        // No debe lanzar ninguna excepción por el token roto.
        $this->renderer->renderInvoice($invoice);

        // Tampoco debe reportarse como "huérfano": un typo nunca llegó a
        // convertirse en marcador (no coincide con ningún block token
        // conocido), así que no es lo mismo que un bloque bien escrito que
        // falló al insertarse.
        $this->assertSame([], $this->renderer->lastRenderWarnings());
    }

    /**
     * empresa.logo (auditoría 2026-08-03): contrato es la PRIMERA vez que
     * tiene un block placeholder — se rompe deliberadamente la invariante
     * anterior de "contrato sin bloques" (ver TemplateRendererAdvancedModeTest
     * y config/document_placeholder_blocks.php). Ruta LOCAL, no URL.
     */
    public function test_contract_template_with_logo_block_renders_the_real_local_path(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);

        // Ver BlockPlaceholderResolverTest::ensureStorageLinkExists() — nunca
        // mkdir() manual bajo public_path('storage/...'), reemplazaría un
        // symlink faltante por un directorio real que storage:link ya no
        // puede corregir después.
        if (!is_link(public_path('storage'))) {
            \Illuminate\Support\Facades\Artisan::call('storage:link');
        }

        $relativePath = 'test-logos/logo_' . uniqid() . '.png';
        \Illuminate\Support\Facades\Storage::disk('public')->put($relativePath, 'fake-png-bytes');
        $tenant->update(['logo' => $relativePath]);
        $expectedPath = str_replace('\\', '/', public_path('storage/' . $relativePath));

        try {
            DocumentTemplate::create([
                'tenant_id' => $tenant->id,
                'type'      => DocumentTemplate::TYPE_CONTRACT,
                'body_html' => '<div>{{empresa.logo}}</div><p>Condiciones para {{cliente.nombre}}</p>',
                'is_active' => true,
            ]);

            Pdf::shouldReceive('loadView')
                ->once()
                ->withArgs(function ($view, $data) use ($expectedPath) {
                    $body = $data['body'];

                    return $view === 'documents.shells.contract_shell'
                        && str_contains($body, $expectedPath)
                        && str_contains($body, '<img')
                        && str_contains($body, 'Condiciones para Juan')
                        && !str_contains($body, 'BLOCKMARK_');
                })
                ->andReturn(\Mockery::mock(\Barryvdh\DomPDF\PDF::class)->shouldIgnoreMissing(\Mockery::self()));

            $this->renderer->renderContract($customer, $customer->customerProfile, $tenant, null, '', now()->format('d/m/Y'));
        } finally {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($relativePath);
        }
    }

    /**
     * auditoría 2026-08-04: en modo avanzado no hay shell fijo — sin este
     * bloque, la firma real que CustomerDocumentController::signContract()
     * captura y pasa a renderContract() se perdía en silencio porque nada en
     * compileAdvanced() la insertaba en ningún lado.
     */
    public function test_contract_template_with_signature_block_renders_the_real_signature_in_advanced_mode(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);

        DocumentTemplate::create([
            'tenant_id'        => $tenant->id,
            'type'             => DocumentTemplate::TYPE_CONTRACT,
            'body_html'        => '<html><body><p>Firma: {{contrato.firma_cliente}}</p></body></html>',
            'is_active'        => true,
            'is_advanced_mode' => true,
        ]);

        Pdf::shouldReceive('loadHTML')
            ->once()
            ->withArgs(function ($html) {
                return str_contains($html, 'data:image/png;base64,firma-real-del-cliente')
                    && !str_contains($html, 'BLOCKMARK_');
            })
            ->andReturn(\Mockery::mock(\Barryvdh\DomPDF\PDF::class)->shouldIgnoreMissing(\Mockery::self()));

        $this->renderer->renderContract(
            $customer,
            $customer->customerProfile,
            $tenant,
            null,
            'data:image/png;base64,firma-real-del-cliente',
            now()->format('d/m/Y')
        );
    }
}
