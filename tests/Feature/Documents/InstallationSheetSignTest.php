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
