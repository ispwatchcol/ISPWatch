<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_delete_endpoint_soft_disables_the_user(): void
    {
        // Staff routes sit behind permission:view_staff and scope by tenant,
        // so the actor needs a tenant + a role granting it (same pattern as
        // RouterOutageTest).
        $tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'StaffManager', 'permissions' => ['view_staff']]);

        $actor = User::factory()->create([
            'status' => true,
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
        ]);

        $staffMember = User::factory()->create([
            'email' => 'staff-delete@example.com',
            'status' => true,
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($actor);

        $this->deleteJson("/api/staff/{$staffMember->id}")
            ->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $staffMember->refresh();

        $this->assertFalse($staffMember->status);
        $this->assertStringStartsWith('staff-delete@example.com_deleted_', $staffMember->email);
    }
}
