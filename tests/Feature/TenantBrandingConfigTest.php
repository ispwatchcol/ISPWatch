<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers PUT /api/tenant/config for the 2 branding fields added in Fase 1
 * (brand_color, document_footer_text) — UpdateTenantRequest didn't validate
 * them yet, so $tenant->update($request->only(array_keys($request->rules())))
 * silently dropped them regardless of what the frontend sent.
 */
class TenantBrandingConfigTest extends TestCase
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

    public function test_saves_brand_color_and_footer_text(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/tenant/config', [
            'name'                  => $this->tenant->name,
            'brand_color'           => '#1e5fa8',
            'document_footer_text' => 'Empresa vigilada por la SIC.',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('tenant', [
            'id'                    => $this->tenant->id,
            'brand_color'           => '#1e5fa8',
            'document_footer_text' => 'Empresa vigilada por la SIC.',
        ]);
    }

    public function test_rejects_an_invalid_hex_color(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/tenant/config', [
            'name'        => $this->tenant->name,
            'brand_color' => 'not-a-color',
        ]);

        $response->assertStatus(422);
    }

    public function test_saves_the_contract_prefix(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/tenant/config', [
            'name'            => $this->tenant->name,
            'contract_prefix' => 'FIBRAX',
        ])->assertStatus(200);

        $this->assertDatabaseHas('tenant', [
            'id'              => $this->tenant->id,
            'contract_prefix' => 'FIBRAX',
        ]);
    }

    /**
     * El prefijo acaba dentro del nombre del archivo y de la ruta en S3, así
     * que se rechaza en la puerta en vez de saneado silenciosamente.
     */
    public function test_rejects_a_contract_prefix_with_unsafe_characters(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/tenant/config', [
            'name'            => $this->tenant->name,
            'contract_prefix' => '../etc/passwd',
        ])->assertStatus(422);
    }
}
