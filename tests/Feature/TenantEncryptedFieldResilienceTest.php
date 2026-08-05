<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Auditoría 2026-08-04: un google_maps_api_key que no se puede desencriptar
 * (ciphertext cifrado con una APP_KEY distinta a la actual — pasó de verdad
 * en dev al leer datos sincronizados desde producción, `The MAC is invalid`)
 * tumbaba con 500 TODA la respuesta de GET /api/tenants/{id} y
 * GET /api/tenant/maps-config, incluyendo campos sin ninguna relación con
 * Google Maps (brand_color, document_footer_text, name...). El campo es
 * completamente ajeno al resto del payload y no debería poder arrastrarlo.
 *
 * Simulamos el ciphertext roto escribiendo directo con DB::table() (bypass
 * del cast `encrypted` del modelo) — así el valor guardado NO es válido para
 * la APP_KEY que usa el test, igual que en el incidente real.
 */
class TenantEncryptedFieldResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'brand_color'           => '#125869',
            'document_footer_text' => 'Pie de página de prueba',
        ]);

        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        DB::table('tenant')->where('id', $this->tenant->id)->update([
            'google_maps_api_key' => 'not-a-valid-encrypted-payload',
        ]);
    }

    public function test_show_still_returns_the_rest_of_the_tenant_when_the_maps_key_cannot_be_decrypted(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/tenants/{$this->tenant->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.brand_color', '#125869');
        $response->assertJsonPath('data.document_footer_text', 'Pie de página de prueba');
        $response->assertJsonPath('data.has_google_maps_key', false);
    }

    public function test_maps_config_degrades_to_no_key_instead_of_a_500(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/tenant/maps-config');

        $response->assertStatus(200);
        $response->assertJsonPath('data.has_key', false);
        $response->assertJsonPath('data.google_maps_api_key', null);
    }
}
