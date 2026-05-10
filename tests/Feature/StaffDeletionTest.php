<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_delete_endpoint_soft_disables_the_user(): void
    {
        $actor = User::factory()->create([
            'status' => true,
        ]);

        $staffMember = User::factory()->create([
            'email' => 'staff-delete@example.com',
            'status' => true,
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
