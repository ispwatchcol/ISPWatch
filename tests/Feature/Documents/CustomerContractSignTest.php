<?php

namespace Tests\Feature\Documents;

use App\Models\CustomerProfile;
use App\Models\DocumentTemplate;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Characterization tests for POST /api/customers/{customer}/contract-sign,
 * written against the CURRENT Pdf::loadView('documents.contract_pdf', ...)
 * call inside CustomerDocumentController::signContract — BEFORE it gets
 * swapped to App\Services\Templates\TemplateRenderer::renderContract().
 * Re-run unchanged after that swap: if they still pass, the swap was
 * transparent for every tenant without a custom template.
 */
class CustomerContractSignTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected Tenant $tenant;
    protected User $staff;
    protected User $customer;
    protected CustomerProfile $profile;
    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

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

        $this->profile = CustomerProfile::create([
            'user_id'   => $this->customer->id,
            'name'      => 'Juan',
            'last_name' => 'Pérez',
            'cedula'    => '123456789',
            'address'   => 'Calle Falsa 123',
        ]);

        $this->plan = Plan::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /**
     * Pins the exact view name + data shape passed into Pdf::loadView. This
     * assertion must keep passing, unchanged, once signContract() is rewired
     * to call TemplateRenderer::renderContract().
     */
    public function test_loads_the_legacy_contract_view_with_the_expected_data(): void
    {
        Sanctum::actingAs($this->staff);

        $fakePdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $fakePdf->shouldReceive('output')->once()->andReturn('%PDF-fake');

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) {
                return $view === 'documents.contract_pdf'
                    && $data['customer']->is($this->customer)
                    && $data['profile']->is($this->profile)
                    && $data['tenant']->is($this->tenant)
                    && $data['signature'] === self::SAMPLE_PNG;
            })
            ->andReturn($fakePdf);

        $response = $this->postJson("/api/customers/{$this->customer->id}/contract-sign", [
            'signature' => self::SAMPLE_PNG,
        ]);

        $response->assertStatus(201);
    }

    /**
     * No mocks: proves the endpoint really produces and stores a valid,
     * signed contract PDF today, end to end.
     */
    public function test_end_to_end_signing_stores_a_signed_contract_document(): void
    {
        Sanctum::actingAs($this->staff);

        $response = $this->postJson("/api/customers/{$this->customer->id}/contract-sign", [
            'signature' => self::SAMPLE_PNG,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('document.type', 'contrato')
            ->assertJsonPath('document.signed', true);

        $this->assertDatabaseHas('customer_documents', [
            'tenant_id'   => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'type'        => 'contrato',
            'signed'      => true,
        ]);

        $path = \App\Models\CustomerDocument::where('customer_id', $this->customer->id)->first()->file_path;
        Storage::disk('public')->assertExists($path);

        $bytes = Storage::disk('public')->get($path);
        $this->assertStringStartsWith('%PDF-', $bytes);
    }

    /**
     * Now that signContract() is wired to TemplateRenderer: a tenant with an
     * active contract template gets the additional-clauses shell, with
     * placeholders resolved and the body sanitized.
     */
    public function test_uses_the_custom_shell_when_tenant_has_an_active_template(): void
    {
        Sanctum::actingAs($this->staff);

        DocumentTemplate::create([
            'tenant_id' => $this->tenant->id,
            'type'      => DocumentTemplate::TYPE_CONTRACT,
            'body_html' => '<p>Condición especial para {{cliente.nombre}}.</p><script>alert(1)</script>',
            'is_active' => true,
        ]);

        $fakePdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $fakePdf->shouldReceive('output')->once()->andReturn('%PDF-fake');

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) {
                return $view === 'documents.shells.contract_shell'
                    && str_contains($data['body'], 'Condición especial para Juan Pérez.')
                    && !str_contains($data['body'], '<script>');
            })
            ->andReturn($fakePdf);

        $response = $this->postJson("/api/customers/{$this->customer->id}/contract-sign", [
            'signature' => self::SAMPLE_PNG,
        ]);

        $response->assertStatus(201);
    }

    /**
     * No mocks: the custom shell path also compiles and stores a real,
     * valid signed contract PDF end to end.
     */
    public function test_custom_template_end_to_end_still_stores_a_valid_signed_contract(): void
    {
        Sanctum::actingAs($this->staff);

        DocumentTemplate::create([
            'tenant_id' => $this->tenant->id,
            'type'      => DocumentTemplate::TYPE_CONTRACT,
            'body_html' => '<p>Cláusula adicional de prueba.</p>',
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/customers/{$this->customer->id}/contract-sign", [
            'signature' => self::SAMPLE_PNG,
        ]);

        $response->assertStatus(201)->assertJsonPath('document.signed', true);

        $path = \App\Models\CustomerDocument::where('customer_id', $this->customer->id)->first()->file_path;
        $bytes = Storage::disk('public')->get($path);
        $this->assertStringStartsWith('%PDF-', $bytes);
    }

    /**
     * A template that exists but is deactivated must not affect the signed
     * contract — same legacy view as if it never existed.
     */
    public function test_inactive_template_still_uses_the_legacy_view(): void
    {
        Sanctum::actingAs($this->staff);

        DocumentTemplate::create([
            'tenant_id' => $this->tenant->id,
            'type'      => DocumentTemplate::TYPE_CONTRACT,
            'body_html' => '<p>Esto nunca debería aparecer.</p>',
            'is_active' => false,
        ]);

        $fakePdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $fakePdf->shouldReceive('output')->once()->andReturn('%PDF-fake');

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(fn ($view, $data) => $view === 'documents.contract_pdf' && $data['customer']->is($this->customer))
            ->andReturn($fakePdf);

        $response = $this->postJson("/api/customers/{$this->customer->id}/contract-sign", [
            'signature' => self::SAMPLE_PNG,
        ]);

        $response->assertStatus(201);
    }

    public function test_rejects_a_signature_that_is_not_a_base64_png(): void
    {
        Sanctum::actingAs($this->staff);

        $response = $this->postJson("/api/customers/{$this->customer->id}/contract-sign", [
            'signature' => 'not-a-png',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_sign_a_contract_for_a_customer_of_another_tenant(): void
    {
        Sanctum::actingAs($this->staff);

        $otherTenant = Tenant::factory()->create();
        $otherCustomer = User::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->postJson("/api/customers/{$otherCustomer->id}/contract-sign", [
            'signature' => self::SAMPLE_PNG,
        ]);

        $response->assertStatus(404);
    }
}
