<?php

use App\Models\User;

it('blocks admins from promoting users to super admin', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);
    $member = User::factory()->create(['role' => 'member']);

    $this->actingAs($admin)
        ->patch("/admin/users/{$member->id}", [
            'role' => 'super_admin',
            'is_active' => true,
        ])
        ->assertForbidden();

    expect($member->fresh()->role)->toBe('member');
});

it('keeps at least one active super admin account', function (): void {
    $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

    $this->actingAs($superAdmin)
        ->patch("/admin/users/{$superAdmin->id}", [
            'role' => 'member',
            'is_active' => true,
        ])
        ->assertStatus(422);

    expect($superAdmin->fresh()->role)->toBe('super_admin')
        ->and($superAdmin->fresh()->is_active)->toBeTrue();
});
