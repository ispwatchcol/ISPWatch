<?php

namespace Tests\Feature\Documents;

use App\Models\DocumentTemplate;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Templates\TemplateDiagnostics;
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
     * objetos {kind, token, label, message} — exactamente esa forma, sin
     * envoltorio extra ni renombrar claves. Este test debe fallar si esa
     * forma cambia sin querer en un refactor futuro.
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

        $this->assertCount(1, $decoded);
        // OJO: 'factura.tabla_items' es una sola clave de array que contiene
        // un punto — NO dos niveles anidados. config('...invoice.factura.tabla_items')
        // rompería (Laravel interpretaría cada punto como un nivel), por eso
        // se busca el array del tipo primero y se indexa con la clave literal.
        $this->assertSame(TemplateDiagnostics::KIND_ORPHANED_BLOCK, $decoded[0]['kind']);
        $this->assertSame('factura.tabla_items', $decoded[0]['token']);
        $this->assertSame(
            config('document_placeholder_blocks.invoice')['factura.tabla_items'],
            $decoded[0]['label']
        );
        $this->assertNotEmpty($decoded[0]['message']);
        $this->assertSame(['kind', 'token', 'label', 'message'], array_keys($decoded[0]));
    }

    /**
     * P-13: el caso que originó todo esto — un contrato exportado de WispHub
     * pegado tal cual. Ninguno de estos marcadores existe en ISPwatch, así
     * que hasta el 2026-08-06 el PDF salía con los datos en blanco y sin
     * ninguna señal de por qué.
     */
    public function test_preview_warns_about_placeholders_migrated_from_another_system(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/document-templates/contract/preview', [
            'body_html' => '<p>Precio: {{ plan_internet.precio }} — Fecha: {{ fecha_instalacion }}</p>'
                . '<p>Contrato No. CO-NUMERO_CONTRATO_TAG</p>'
                . '<p><img src="https://wisphub.app/media/logo.jpg" /></p>',
            'is_advanced_mode' => true,
        ]);

        $response->assertStatus(200);
        $warnings = json_decode($response->headers->get('X-Template-Warnings'), true);
        $byToken = collect($warnings)->keyBy('token');

        $this->assertSame(
            TemplateDiagnostics::KIND_FOREIGN_PLACEHOLDER,
            $byToken['plan_internet.precio']['kind']
        );
        $this->assertStringContainsString('{{plan.valor_mensual}}', $byToken['plan_internet.precio']['message']);
        $this->assertStringContainsString('{{contrato.fecha}}', $byToken['fecha_instalacion']['message']);

        $this->assertSame(
            TemplateDiagnostics::KIND_FOREIGN_MARKER,
            $byToken['NUMERO_CONTRATO_TAG']['kind']
        );
        $this->assertStringContainsString('{{contrato.numero}}', $byToken['NUMERO_CONTRATO_TAG']['message']);

        $this->assertSame(
            TemplateDiagnostics::KIND_REMOTE_IMAGE,
            $byToken['https://wisphub.app/media/logo.jpg']['kind']
        );
    }

    /**
     * Guardar activa la plantilla de inmediato, así que el aviso no puede
     * vivir sólo en la vista previa: quien pega el HTML y le da directo a
     * "Guardar y activar" es exactamente quien más lo necesita.
     */
    public function test_update_returns_the_same_warnings_in_the_json_response(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/document-templates/contract', [
            'body_html' => '<p>Hola {{ cliente_nombre }}</p>',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('warnings.0.token', 'cliente_nombre')
            ->assertJsonPath('warnings.0.kind', TemplateDiagnostics::KIND_FOREIGN_PLACEHOLDER);
    }

    public function test_update_returns_an_empty_warnings_array_for_a_clean_template(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/document-templates/contract', [
            'body_html' => '<p>Hola {{cliente.nombre}}, tu plan es {{plan.nombre}}.</p>',
        ])->assertStatus(200)->assertJsonPath('warnings', []);
    }

    public function test_show_lists_the_starter_templates_without_their_bodies(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/document-templates/contract');

        $response->assertStatus(200);
        $starters = $response->json('starters');

        $this->assertNotEmpty($starters);
        $this->assertContains('crc-colombia', array_column($starters, 'slug'));
        foreach ($starters as $starter) {
            $this->assertArrayNotHasKey('body_html', $starter);
        }
    }

    public function test_the_starter_endpoint_returns_the_body_and_persists_nothing(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/document-templates/contract/starters/crc-colombia');

        $response->assertStatus(200)
            ->assertJsonPath('data.slug', 'crc-colombia')
            ->assertJsonPath('data.advanced', true)
            ->assertJsonPath('data.page_orientation', 'landscape');

        $this->assertStringContainsString('{{contrato.numero}}', $response->json('data.body_html'));
        $this->assertDatabaseCount('document_templates', 0);
    }

    public function test_an_unknown_starter_slug_is_a_404(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/document-templates/contract/starters/no-existe')->assertStatus(404);
        $this->getJson('/api/document-templates/not-a-type/starters/crc-colombia')->assertStatus(404);
    }

    public function test_the_starter_endpoint_requires_the_document_templates_permission(): void
    {
        $role = Role::create(['name' => 'Soporte2', 'permissions' => ['view_support']]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/document-templates/contract/starters/crc-colombia')->assertStatus(403);
    }

    /**
     * Una plantilla base que no llega a producir un PDF sería peor que no
     * ofrecerla: el tenant la carga confiando en que funciona y descubre el
     * problema con un cliente delante. Se renderizan todas de verdad, con el
     * pipeline real, y se exige que ninguna dispare avisos.
     */
    public function test_every_starter_renders_a_real_pdf_without_warnings(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (DocumentTemplate::TYPES as $type) {
            foreach (config("document_template_starters.{$type}", []) as $meta) {
                $starter = $this->getJson("/api/document-templates/{$type}/starters/{$meta['slug']}")
                    ->json('data');

                $response = $this->postJson("/api/document-templates/{$type}/preview", [
                    'body_html'        => $starter['body_html'],
                    'is_advanced_mode' => $starter['advanced'],
                    'page_size'        => $starter['page_size'],
                    'page_orientation' => $starter['page_orientation'],
                ]);

                $response->assertStatus(200);
                $this->assertStringStartsWith('%PDF-', $response->getContent(), "{$type}/{$meta['slug']}");
                $this->assertFalse(
                    $response->headers->has('X-Template-Warnings'),
                    "La plantilla base {$type}/{$meta['slug']} genera avisos: "
                        . $response->headers->get('X-Template-Warnings')
                );
            }
        }
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
