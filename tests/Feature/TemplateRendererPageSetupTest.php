<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\DocumentTemplate;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Templates\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tamaño/orientación de página por plantilla (auditoría 2026-08-05).
 *
 * Origen: un contrato CRC a dos columnas (el formato estándar en Colombia, y
 * el que exportan sistemas como WispHub) necesita ~950px de ancho. A4 vertical
 * da ~698px útiles a 96dpi, así que dompdf lo aprieta y descuadra la
 * maquetación entera; A4 horizontal da ~1027px y cabe sin tocar el diseño.
 *
 * Se verifica sobre el PDF REAL (el /MediaBox del documento generado), no
 * sobre un mock que confirme que se llamó a setPaper() — un mock probaría que
 * el código llama al método, no que dompdf de verdad produzca la página en la
 * orientación pedida, que es lo único que le importa al usuario.
 */
class TemplateRendererPageSetupTest extends TestCase
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
            'user_id'   => $customer->id,
            'name'      => 'Juan',
            'last_name' => 'Pérez',
            'cedula'    => '123456789',
            'address'   => 'Calle 1 # 2-3',
            'city'      => 'Anapoima',
            'state'     => 'Cundinamarca',
        ]);

        return $customer;
    }

    /**
     * Ancho y alto de la primera página del PDF, leídos de su /MediaBox.
     *
     * @return array{0:float,1:float} [ancho, alto] en puntos PostScript
     */
    private function firstPageSize(string $pdfBytes): array
    {
        $this->assertMatchesRegularExpression(
            '/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/',
            $pdfBytes,
            'El PDF generado no declara un /MediaBox legible.'
        );

        preg_match('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdfBytes, $m);

        return [(float) $m[1], (float) $m[2]];
    }

    private function contractTemplate(Tenant $tenant, array $overrides = []): DocumentTemplate
    {
        return DocumentTemplate::create(array_merge([
            'tenant_id'        => $tenant->id,
            'type'             => DocumentTemplate::TYPE_CONTRACT,
            'body_html'        => '<html><body><div>Contrato de {{cliente.nombre}}</div></body></html>',
            'is_active'        => true,
            'is_advanced_mode' => true,
        ], $overrides));
    }

    private function renderContract(Tenant $tenant, User $customer): string
    {
        return $this->renderer->renderContract(
            $customer,
            $customer->customerProfile,
            $tenant,
            null,
            '',
            '05/08/2026',
            'CO-00001'
        )->output();
    }

    public function test_landscape_template_produces_a_wider_than_tall_page(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $this->contractTemplate($tenant, ['page_orientation' => 'landscape']);

        [$width, $height] = $this->firstPageSize($this->renderContract($tenant, $customer));

        $this->assertGreaterThan($height, $width, 'Con page_orientation=landscape la página debe ser más ancha que alta.');
        // A4 horizontal = 841.89 x 595.28 pt.
        $this->assertEqualsWithDelta(841.89, $width, 1.0);
        $this->assertEqualsWithDelta(595.28, $height, 1.0);
    }

    public function test_default_template_stays_portrait_a4(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        // Sin page_size/page_orientation explícitos: debe salir exactamente
        // igual que antes de existir estas columnas.
        $this->contractTemplate($tenant);

        [$width, $height] = $this->firstPageSize($this->renderContract($tenant, $customer));

        $this->assertGreaterThan($width, $height, 'Sin configuración explícita la página debe seguir siendo vertical.');
        $this->assertEqualsWithDelta(595.28, $width, 1.0);
        $this->assertEqualsWithDelta(841.89, $height, 1.0);
    }

    public function test_letter_size_is_honoured(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $this->contractTemplate($tenant, ['page_size' => 'letter', 'page_orientation' => 'portrait']);

        [$width, $height] = $this->firstPageSize($this->renderContract($tenant, $customer));

        // Carta = 612 x 792 pt.
        $this->assertEqualsWithDelta(612.0, $width, 1.0);
        $this->assertEqualsWithDelta(792.0, $height, 1.0);
    }

    /**
     * Red de seguridad de applyPaper(): una fila con basura en la columna
     * (escritura directa a la BD, fila migrada de otro sistema) no puede
     * llegar hasta dompdf — setPaper() con un tamaño desconocido no lanza
     * excepción, se queda en silencio con un canvas raro. Debe caer al
     * default, no romper ni producir una página inesperada.
     */
    public function test_garbage_page_values_fall_back_to_the_default(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $template = $this->contractTemplate($tenant);
        // Sin pasar por el modelo/validación, como lo haría un UPDATE a mano.
        DocumentTemplate::where('id', $template->id)->update([
            'page_size'        => 'papiro',
            'page_orientation' => 'diagonal',
        ]);

        [$width, $height] = $this->firstPageSize($this->renderContract($tenant, $customer));

        $this->assertEqualsWithDelta(595.28, $width, 1.0);
        $this->assertEqualsWithDelta(841.89, $height, 1.0);
    }

    /**
     * La vista previa tiene que reflejar lo que el tenant tiene seleccionado
     * AHORA en el editor, no lo persistido — si usara lo guardado, cambiar a
     * horizontal y previsualizar seguiría mostrando el diseño roto.
     */
    public function test_preview_uses_the_editor_selection_not_the_saved_row(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        // Guardado en vertical...
        $this->contractTemplate($tenant, ['page_orientation' => 'portrait']);

        // ...pero el editor pide horizontal para esta vista previa.
        $pdf = $this->renderer->previewContract(
            $customer,
            $customer->customerProfile,
            $tenant,
            null,
            '',
            '05/08/2026',
            '<html><body><div>Borrador</div></body></html>',
            true,
            'CO-00001',
            'a4',
            'landscape'
        );

        [$width, $height] = $this->firstPageSize($pdf->output());

        $this->assertGreaterThan($height, $width, 'La vista previa debe usar la orientación del editor, no la guardada.');
    }

    /**
     * Municipio/Departamento del servicio: campos obligatorios del formato de
     * contrato CRC colombiano. Antes no existían como placeholder, así que
     * PlaceholderResolver::apply() los blanqueaba en silencio.
     */
    public function test_contract_resolves_city_and_state_placeholders(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $this->contractTemplate($tenant, [
            'body_html' => '<html><body><div>Depto: {{cliente.departamento}} / Mun: {{cliente.ciudad}}</div></body></html>',
        ]);

        $values = app(\App\Services\Templates\PlaceholderResolver::class)->forContract(
            $customer,
            $customer->customerProfile,
            $tenant,
            new Plan(['name' => 'Plan 100MB', 'cost_product' => 75000]),
            '05/08/2026',
            'CO-00001'
        );

        $this->assertSame('Anapoima', $values['cliente.ciudad']);
        $this->assertSame('Cundinamarca', $values['cliente.departamento']);
    }

    public function test_city_and_state_are_listed_in_the_contract_placeholder_whitelist(): void
    {
        $whitelist = config('document_placeholders.contract');

        $this->assertArrayHasKey('cliente.ciudad', $whitelist);
        $this->assertArrayHasKey('cliente.departamento', $whitelist);
    }
}
