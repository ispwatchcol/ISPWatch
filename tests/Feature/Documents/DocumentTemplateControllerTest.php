<?php

namespace Tests\Feature\Documents;

use App\Models\DocumentTemplate;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);
    }

    public function test_index_lists_all_three_types_with_no_draft_by_default(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/document-templates');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');

        foreach ($response->json('data') as $row) {
            $this->assertContains($row['type'], DocumentTemplate::TYPES);
            $this->assertFalse($row['has_draft']);
            $this->assertFalse($row['is_active']);
        }
    }

    public function test_show_returns_the_closed_placeholder_whitelist_for_the_type(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/document-templates/invoice');

        $response->assertStatus(200);
        $this->assertSame(
            array_keys(config('document_placeholders.invoice')),
            array_keys($response->json('placeholders'))
        );
    }

    public function test_show_rejects_an_unknown_type(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/document-templates/not-a-type')->assertStatus(404);
    }

    public function test_show_returns_the_closed_block_placeholder_whitelist_for_the_type(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/document-templates/invoice');

        $response->assertStatus(200);
        $this->assertSame(
            array_keys(config('document_placeholder_blocks.invoice')),
            array_keys($response->json('block_placeholders'))
        );
        $this->assertArrayHasKey('factura.tabla_items', $response->json('block_placeholders'));
    }

    public function test_update_creates_and_sanitizes_the_draft_and_activates_it(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/document-templates/invoice', [
            'body_html' => '<p>Gracias {{cliente.nombre}}</p><script>alert(1)</script>',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.body_html', '<p>Gracias {{cliente.nombre}}</p>');

        $this->assertDatabaseHas('document_templates', [
            'tenant_id'  => $this->tenant->id,
            'type'       => 'invoice',
            'is_active'  => true,
            'updated_by' => $this->admin->id,
        ]);
    }

    /**
     * Modo avanzado (auditoría 2026-08-01): usa AdvancedTemplateSanitizer,
     * no TemplateSanitizer — por eso <div>/<style> sobreviven (el sanitizer
     * de modo seguro los habría quitado), pero <script> sigue bloqueado
     * igual que en modo seguro. is_advanced_mode queda persistido en true.
     */
    public function test_update_in_advanced_mode_uses_the_advanced_sanitizer_and_persists_the_flag(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/document-templates/invoice', [
            'body_html'        => '<html><head><style>.card{color:#1e5fa8;border-radius:8px;}</style></head>'
                . '<body><div class="card">Gracias {{cliente.nombre}}</div><script>alert(1)</script></body></html>',
            'is_advanced_mode' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_advanced_mode', true);

        $body = $response->json('data.body_html');
        $this->assertStringContainsString('<div class="card">Gracias {{cliente.nombre}}</div>', $body);
        $this->assertStringContainsString('border-radius:8px', $body);
        $this->assertStringNotContainsString('<script', $body);

        $this->assertDatabaseHas('document_templates', [
            'tenant_id'        => $this->tenant->id,
            'type'             => 'invoice',
            'is_advanced_mode' => true,
        ]);
    }

    public function test_preview_in_advanced_mode_renders_a_real_pdf_without_the_fixed_shell(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/document-templates/invoice/preview', [
            'body_html'        => '<html><body><h1>Vista previa avanzada {{cliente.nombre}}</h1></body></html>',
            'is_advanced_mode' => true,
        ]);

        $response->assertStatus(200);
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertDatabaseCount('document_templates', 0);
    }

    public function test_update_upserts_an_existing_draft_instead_of_duplicating_it(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/document-templates/contract', ['body_html' => '<p>Primero</p>']);
        $this->putJson('/api/document-templates/contract', ['body_html' => '<p>Segundo</p>']);

        $this->assertDatabaseCount('document_templates', 1);
        $this->assertDatabaseHas('document_templates', [
            'tenant_id' => $this->tenant->id,
            'type'      => 'contract',
            'body_html' => '<p>Segundo</p>',
        ]);
    }

    public function test_update_requires_body_html(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/document-templates/invoice', [])->assertStatus(422);
    }

    public function test_reset_deactivates_but_keeps_the_draft(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/document-templates/installation', ['body_html' => '<p>Mi borrador</p>']);

        $response = $this->postJson('/api/document-templates/installation/reset');

        $response->assertStatus(200)->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('document_templates', [
            'tenant_id' => $this->tenant->id,
            'type'      => 'installation',
            'is_active' => false,
            'body_html' => '<p>Mi borrador</p>',
        ]);
    }

    public function test_reset_is_a_no_op_when_there_is_no_draft_yet(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/document-templates/invoice/reset');

        $response->assertStatus(200)->assertJsonPath('data.has_draft', false);
        $this->assertDatabaseCount('document_templates', 0);
    }

    public function test_preview_renders_a_real_pdf_for_each_type_without_persisting_anything(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (DocumentTemplate::TYPES as $type) {
            $response = $this->postJson("/api/document-templates/{$type}/preview", [
                'body_html' => '<p>Vista previa de ' . $type . ' para {{cliente.nombre}}.</p><script>alert(1)</script>',
            ]);

            $response->assertStatus(200);
            $this->assertStringStartsWith('%PDF-', $response->getContent());
        }

        $this->assertDatabaseCount('document_templates', 0);
    }

    public function test_preview_omits_x_template_warnings_header_when_nothing_is_orphaned(): void
    {
        Sanctum::actingAs($this->admin);

        // Well-formed draft, real pipeline (no mocks): the token sits in
        // content position, so nothing should ever end up in
        // TemplateRenderer::lastRenderWarnings().
        $response = $this->postJson('/api/document-templates/invoice/preview', [
            'body_html' => '<div>{{factura.tabla_items}}</div>',
        ]);

        $response->assertStatus(200);
        $this->assertFalse($response->headers->has('X-Template-Warnings'));
    }

    /**
     * Contrato implícito con el frontend (DocumentTemplatesSection.vue):
     * cuando TemplateRenderer::lastRenderWarnings() reporta un token
     * huérfano, el header X-Template-Warnings debe ser un JSON array de
     * objetos {token, label} — exactamente esa forma, sin envoltorio extra
     * ni renombrar claves. Este test debe fallar si esa forma cambia sin
     * querer en un refactor futuro.
     *
     * Nota: reproducir un huérfano real de punta a punta (a través del
     * TemplateSanitizer real) resultó más difícil de lo esperado — todos los
     * atributos permitidos hoy (span[style], a[href]) pasan por validadores
     * estructurados (CSS/URI) que no preservan un token {{...}} como texto
     * literal (ver hallazgo en el resumen). Por eso este test fuerza el
     * escenario vía TemplateRenderer::lastRenderWarnings() en vez de
     * fabricar un body_html "malicioso" que hoy no logra atravesar el
     * sanitizer real — el objetivo aquí es fijar el CONTRATO del header, no
     * re-probar el mecanismo de detección (eso ya lo cubre
     * BlockMarkerInjectorTest).
     */
    public function test_preview_reports_orphaned_block_placeholders_via_x_template_warnings_header(): void
    {
        Sanctum::actingAs($this->admin);

        $fakePdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class)->shouldIgnoreMissing(\Mockery::self());
        $fakePdf->shouldReceive('stream')
            ->once()
            ->with('vista-previa.pdf')
            ->andReturn(response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']));

        $renderer = \Mockery::mock(\App\Services\Templates\TemplateRenderer::class);
        $renderer->shouldReceive('previewInvoice')->once()->andReturn($fakePdf);
        $renderer->shouldReceive('lastRenderWarnings')->once()->andReturn(['factura.tabla_items']);
        $this->app->instance(\App\Services\Templates\TemplateRenderer::class, $renderer);

        $response = $this->postJson('/api/document-templates/invoice/preview', [
            'body_html' => '<p>Hola</p>',
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->headers->has('X-Template-Warnings'));

        $decoded = json_decode($response->headers->get('X-Template-Warnings'), true);

        // OJO: 'factura.tabla_items' es una sola clave de array que contiene
        // un punto — NO dos niveles anidados. config('...invoice.factura.tabla_items')
        // rompería (Laravel interpretaría cada punto como un nivel), por eso
        // se busca el array del tipo primero y se indexa con la clave literal.
        $this->assertSame([
            [
                'token' => 'factura.tabla_items',
                'label' => config('document_placeholder_blocks.invoice')['factura.tabla_items'],
            ],
        ], $decoded);
    }

    public function test_a_user_without_manage_document_templates_permission_is_forbidden(): void
    {
        $role = Role::create(['name' => 'Soporte', 'permissions' => ['view_support']]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/document-templates')->assertStatus(403);
    }

    /**
     * manage_tenant is deliberately NOT enough on its own: a custom role
     * scoped to "edit our company config" must not automatically be able to
     * rewrite legal contract clauses / invoice footers.
     */
    public function test_manage_tenant_alone_does_not_grant_access_to_document_templates(): void
    {
        $role = Role::create(['name' => 'Config Empresa', 'permissions' => ['manage_tenant']]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/document-templates')->assertStatus(403);
    }

    public function test_tenants_are_fully_isolated_from_each_other(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherRole = Role::create(['name' => 'Admin2', 'permissions' => ['*']]);
        $otherAdmin = User::factory()->create(['tenant_id' => $otherTenant->id, 'role_id' => $otherRole->id]);

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/document-templates/invoice', ['body_html' => '<p>Del tenant A</p>']);

        Sanctum::actingAs($otherAdmin);
        $response = $this->getJson('/api/document-templates/invoice');

        $response->assertStatus(200)->assertJsonPath('has_draft', false);
    }
}
