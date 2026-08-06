<?php

namespace Tests\Feature\Documents;

use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\DocumentTemplate;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Characterization tests for POST /api/installations/{installation}/sign,
 * written against the CURRENT Pdf::loadView('documents.installation_sheet_pdf', ...)
 * call inside CustomerInstallationController::renderSheetPdf — BEFORE it
 * gets swapped to App\Services\Templates\TemplateRenderer::renderInstallationSheet().
 * Re-run unchanged after that swap: if they still pass, the swap was
 * transparent for every tenant without a custom template.
 */
class InstallationSheetSignTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected Tenant $tenant;
    protected User $staff;
    protected User $customer;
    protected CustomerInstallation $installation;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        // Los documentos de cliente viven en el disco 's3' (bucket privado, ver
        // CustomerDocument::getUrlAttribute). Sin este fake, el SDK de AWS
        // intenta resolver una región real y la petición muere con un 500
        // "Missing required client configuration options: region".
        Storage::fake('s3');

        $this->tenant = Tenant::factory()->create();

        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);
        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        $this->customer = User::factory()->create([
            'tenant_id'     => $this->tenant->id,
            'user_name'     => 'Juan',
            'user_lastname' => 'Pérez',
        ]);

        CustomerProfile::create([
            'user_id'   => $this->customer->id,
            'name'      => 'Juan',
            'last_name' => 'Pérez',
            'cedula'    => '123456789',
            'address'   => 'Calle Falsa 123',
        ]);

        $this->installation = CustomerInstallation::create([
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => $this->customer->id,
            'scheduled_date' => '2026-07-25',
            'address'        => 'Calle Falsa 123',
            'equipment'      => 'ONU + Router',
            'status'         => 'pendiente',
        ]);
    }

    /**
     * Pins the exact view name + data shape passed into Pdf::loadView. This
     * assertion must keep passing, unchanged, once renderSheetPdf() is
     * rewired to call TemplateRenderer::renderInstallationSheet().
     */
    public function test_loads_the_legacy_installation_sheet_view_with_the_expected_data(): void
    {
        Sanctum::actingAs($this->staff);

        $fakePdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $fakePdf->shouldReceive('output')->once()->andReturn('%PDF-fake');

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) {
                return $view === 'documents.installation_sheet_pdf'
                    && $data['installation']->is($this->installation)
                    && $data['customer']->is($this->customer)
                    && $data['customer_signature'] === self::SAMPLE_PNG;
            })
            ->andReturn($fakePdf);

        $response = $this->postJson("/api/installations/{$this->installation->id}/sign", [
            'customer_signature' => self::SAMPLE_PNG,
        ]);

        $response->assertOk();
    }

    /**
     * No mocks: proves the endpoint really produces and stores a valid
     * installation sheet PDF today, end to end, and completes the order.
     */
    public function test_end_to_end_signing_stores_the_sheet_and_completes_the_installation(): void
    {
        Sanctum::actingAs($this->staff);

        $response = $this->postJson("/api/installations/{$this->installation->id}/sign", [
            'customer_signature'   => self::SAMPLE_PNG,
            'technician_signature' => self::SAMPLE_PNG,
        ]);

        $response->assertOk()
            ->assertJsonPath('document.type', 'instalacion');

        $this->assertDatabaseHas('customer_documents', [
            'tenant_id'       => $this->tenant->id,
            'installation_id' => $this->installation->id,
            'type'            => 'instalacion',
        ]);

        $this->assertDatabaseHas('customer_installations', [
            'id'     => $this->installation->id,
            'status' => 'completada',
        ]);

        $path = \App\Models\CustomerDocument::where('installation_id', $this->installation->id)->first()->file_path;
        Storage::disk('s3')->assertExists($path);

        $bytes = Storage::disk('s3')->get($path);
        $this->assertStringStartsWith('%PDF-', $bytes);
    }

    /**
     * Now that renderSheetPdf() is wired to TemplateRenderer: a tenant with
     * an active installation template gets the custom shell, with
     * placeholders resolved and the body sanitized.
     */
    public function test_uses_the_custom_shell_when_tenant_has_an_active_template(): void
    {
        Sanctum::actingAs($this->staff);

        DocumentTemplate::create([
            'tenant_id' => $this->tenant->id,
            'type'      => DocumentTemplate::TYPE_INSTALLATION,
            'body_html' => '<p>Observación para la orden {{instalacion.numero}}.</p><script>alert(1)</script>',
            'is_active' => true,
        ]);

        $fakePdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $fakePdf->shouldReceive('output')->once()->andReturn('%PDF-fake');

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) {
                return $view === 'documents.shells.installation_shell'
                    && str_contains($data['body'], 'Observación para la orden ' . $this->installation->id . '.')
                    && !str_contains($data['body'], '<script>');
            })
            ->andReturn($fakePdf);

        $response = $this->postJson("/api/installations/{$this->installation->id}/sign", [
            'customer_signature' => self::SAMPLE_PNG,
        ]);

        $response->assertOk();
    }

    /**
     * No mocks: the custom shell path also compiles and stores a real,
     * valid installation sheet PDF end to end.
     */
    public function test_custom_template_end_to_end_still_stores_a_valid_sheet(): void
    {
        Sanctum::actingAs($this->staff);

        DocumentTemplate::create([
            'tenant_id' => $this->tenant->id,
            'type'      => DocumentTemplate::TYPE_INSTALLATION,
            'body_html' => '<p>Observación de prueba.</p>',
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/installations/{$this->installation->id}/sign", [
            'customer_signature' => self::SAMPLE_PNG,
        ]);

        $response->assertOk()->assertJsonPath('document.type', 'instalacion');

        $path = \App\Models\CustomerDocument::where('installation_id', $this->installation->id)->first()->file_path;
        $bytes = Storage::disk('s3')->get($path);
        $this->assertStringStartsWith('%PDF-', $bytes);
    }

    /**
     * A template that exists but is deactivated must not affect the
     * generated sheet — same legacy view as if it never existed.
     */
    public function test_inactive_template_still_uses_the_legacy_view(): void
    {
        Sanctum::actingAs($this->staff);

        DocumentTemplate::create([
            'tenant_id' => $this->tenant->id,
            'type'      => DocumentTemplate::TYPE_INSTALLATION,
            'body_html' => '<p>Esto nunca debería aparecer.</p>',
            'is_active' => false,
        ]);

        $fakePdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $fakePdf->shouldReceive('output')->once()->andReturn('%PDF-fake');

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(fn ($view, $data) => $view === 'documents.installation_sheet_pdf' && $data['installation']->is($this->installation))
            ->andReturn($fakePdf);

        $response = $this->postJson("/api/installations/{$this->installation->id}/sign", [
            'customer_signature' => self::SAMPLE_PNG,
        ]);

        $response->assertOk();
    }

    public function test_requires_a_valid_base64_png_customer_signature(): void
    {
        Sanctum::actingAs($this->staff);

        $response = $this->postJson("/api/installations/{$this->installation->id}/sign", [
            'customer_signature' => 'not-a-png',
        ]);

        $response->assertStatus(422);
    }

    /**
     * La vista previa devuelve el PDF pero NO guarda documento, NO firma y
     * NO cierra la orden: el cliente solo está leyendo lo que va a firmar.
     */
    public function test_sheet_preview_returns_a_pdf_without_storing_or_signing(): void
    {
        Sanctum::actingAs($this->staff);

        $response = $this->post("/api/installations/{$this->installation->id}/sheet-preview");

        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $this->assertDatabaseMissing('customer_documents', [
            'installation_id' => $this->installation->id,
        ]);
        $this->assertDatabaseHas('customer_installations', [
            'id'        => $this->installation->id,
            'status'    => 'pendiente',
            'signed_at' => null,
        ]);
    }

    /**
     * El técnico previsualiza con lo que tiene escrito en pantalla aunque no
     * haya pulsado "Guardar hoja" — y esa hoja en borrador no se persiste.
     */
    public function test_sheet_preview_uses_the_draft_sheet_without_persisting_it(): void
    {
        Sanctum::actingAs($this->staff);

        $fakePdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $fakePdf->shouldReceive('stream')->once()->andReturn(response('%PDF-fake'));

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) {
                return $view === 'documents.installation_sheet_pdf'
                    && ($data['installation']->sheet['modem_brand'] ?? null) === 'TP-Link'
                    && $data['customer_signature'] === '';
            })
            ->andReturn($fakePdf);

        $response = $this->postJson("/api/installations/{$this->installation->id}/sheet-preview", [
            'sheet' => ['modem_brand' => 'TP-Link'],
        ]);

        $response->assertOk();
        $this->assertNull($this->installation->fresh()->sheet);
    }

    /**
     * Caso que motivó la vista previa: una orden de PROSPECTO (todavía sin
     * cliente) también tiene que poder previsualizarse.
     */
    public function test_sheet_preview_works_for_a_prospect_only_installation(): void
    {
        Sanctum::actingAs($this->staff);

        $prospect = \App\Models\Prospect::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Ana',
            'last_name' => 'Gómez',
            'cedula'    => '987654321',
            'address'   => 'Carrera 9 #1-2',
            'status'    => 'agendado',
        ]);

        $installation = CustomerInstallation::create([
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => null,
            'prospect_id'    => $prospect->id,
            'scheduled_date' => '2026-07-25',
            'address'        => 'Carrera 9 #1-2',
            'status'         => 'pendiente',
        ]);

        $response = $this->post("/api/installations/{$installation->id}/sheet-preview");

        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    /**
     * Una orden = UNA hoja firmada. Firmar dos veces dejaba dos PDF casi
     * idénticos en los documentos del cliente sin forma de saber cuál vale.
     */
    public function test_refuses_to_sign_twice_and_says_to_delete_the_previous_sheet(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/installations/{$this->installation->id}/sign", [
            'customer_signature' => self::SAMPLE_PNG,
        ])->assertOk();

        $second = $this->postJson("/api/installations/{$this->installation->id}/sign", [
            'customer_signature' => self::SAMPLE_PNG,
        ]);

        $second->assertStatus(409);
        $this->assertStringContainsString('Elimínala', $second->json('message'));

        $this->assertSame(
            1,
            \App\Models\CustomerDocument::where('installation_id', $this->installation->id)
                ->where('signed', true)
                ->count()
        );
    }

    /**
     * Tras borrar la hoja anterior se puede volver a firmar: el bloqueo mira
     * los documentos, no una marca en la orden.
     */
    public function test_can_sign_again_after_deleting_the_previous_sheet(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/installations/{$this->installation->id}/sign", [
            'customer_signature' => self::SAMPLE_PNG,
        ])->assertOk();

        \App\Models\CustomerDocument::where('installation_id', $this->installation->id)
            ->where('signed', true)
            ->delete();

        $this->postJson("/api/installations/{$this->installation->id}/sign", [
            'customer_signature' => self::SAMPLE_PNG,
        ])->assertOk();
    }

    /** Las fotos subidas no cuentan como hoja firmada y no bloquean la firma. */
    public function test_uploaded_photos_do_not_block_signing(): void
    {
        Sanctum::actingAs($this->staff);

        \App\Models\CustomerDocument::create([
            'tenant_id'       => $this->tenant->id,
            'customer_id'     => $this->customer->id,
            'installation_id' => $this->installation->id,
            'type'            => 'instalacion',
            'file_name'       => 'fachada.jpg',
            'file_path'       => "customer_documents/{$this->customer->id}/fachada.jpg",
            'file_size'       => 1024,
            'mime_type'       => 'image/jpeg',
            'signed'          => false,
        ]);

        $this->postJson("/api/installations/{$this->installation->id}/sign", [
            'customer_signature' => self::SAMPLE_PNG,
        ])->assertOk();
    }

    public function test_cannot_preview_the_sheet_of_another_tenant(): void
    {
        Sanctum::actingAs($this->staff);

        $otherTenant = Tenant::factory()->create();
        $otherInstallation = CustomerInstallation::create([
            'tenant_id'      => $otherTenant->id,
            'customer_id'    => User::factory()->create(['tenant_id' => $otherTenant->id])->id,
            'scheduled_date' => '2026-07-25',
            'status'         => 'pendiente',
        ]);

        $this->post("/api/installations/{$otherInstallation->id}/sheet-preview")
            ->assertStatus(404);
    }

    public function test_cannot_sign_an_installation_of_another_tenant(): void
    {
        Sanctum::actingAs($this->staff);

        $otherTenant = Tenant::factory()->create();
        $otherInstallation = CustomerInstallation::create([
            'tenant_id'      => $otherTenant->id,
            'customer_id'    => User::factory()->create(['tenant_id' => $otherTenant->id])->id,
            'scheduled_date' => '2026-07-25',
            'status'         => 'pendiente',
        ]);

        $response = $this->postJson("/api/installations/{$otherInstallation->id}/sign", [
            'customer_signature' => self::SAMPLE_PNG,
        ]);

        $response->assertStatus(404);
    }
}
