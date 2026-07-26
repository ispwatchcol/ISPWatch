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

    public function test_a_user_without_manage_tenant_permission_is_forbidden(): void
    {
        $role = Role::create(['name' => 'Soporte', 'permissions' => ['view_support']]);
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
