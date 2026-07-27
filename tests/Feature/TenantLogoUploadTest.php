<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantLogoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->tenant = Tenant::factory()->create();

        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);
    }

    public function test_uploads_and_wires_the_logo_to_the_tenant(): void
    {
        Sanctum::actingAs($this->admin);

        $file = UploadedFile::fake()->image('logo.png', 200, 200);

        $response = $this->postJson('/api/tenant/logo', ['logo' => $file]);

        $response->assertStatus(200);

        $path = $response->json('data.logo');
        Storage::disk('public')->assertExists($path);

        $this->assertDatabaseHas('tenant', [
            'id'   => $this->tenant->id,
            'logo' => $path,
        ]);
    }

    public function test_replacing_the_logo_deletes_the_previous_file(): void
    {
        Sanctum::actingAs($this->admin);

        $first = $this->postJson('/api/tenant/logo', ['logo' => UploadedFile::fake()->image('logo1.png')]);
        $firstPath = $first->json('data.logo');

        $second = $this->postJson('/api/tenant/logo', ['logo' => UploadedFile::fake()->image('logo2.png')]);
        $secondPath = $second->json('data.logo');

        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_rejects_a_non_image_file(): void
    {
        Sanctum::actingAs($this->admin);

        $file = UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf');

        $this->postJson('/api/tenant/logo', ['logo' => $file])->assertStatus(422);
    }

    public function test_requires_manage_tenant_permission(): void
    {
        $role = Role::create(['name' => 'Soporte', 'permissions' => ['view_support']]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]);
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->image('logo.png');

        $this->postJson('/api/tenant/logo', ['logo' => $file])->assertStatus(403);
    }
}
