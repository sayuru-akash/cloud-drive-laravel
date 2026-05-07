<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

it('renders admin user management with create-user permissions', function (): void {
    $admin = User::factory()->create([
        'role' => 'admin',
        'created_at' => now()->subMinute(),
    ]);
    $member = User::factory()->withTwoFactor()->create([
        'role' => 'member',
        'created_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Index')
            ->where('currentUserId', $admin->id)
            ->has('users.data', 2)
            ->where('users.data.0.email', $member->email)
            ->where('users.data.0.two_factor_enabled', true)
            ->has('settings')
        );
});

it('lets admins create active verified workspace users', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->post('/admin/users', [
            'name' => 'New Member',
            'email' => 'NEW.MEMBER@example.com',
            'role' => 'member',
            'is_active' => true,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect();

    $user = User::query()->where('email', 'new.member@example.com')->firstOrFail();

    expect($user->name)->toBe('New Member')
        ->and($user->role)->toBe('member')
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeTrue()
        ->and(AuditLog::query()->where('action_type', 'user.created')->where('resource_id', (string) $user->id)->exists())->toBeTrue();
});

it('validates duplicate emails when admins create users', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($admin)
        ->from('/admin')
        ->post('/admin/users', [
            'name' => 'Taken Email',
            'email' => 'taken@example.com',
            'role' => 'member',
            'is_active' => true,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect('/admin')
        ->assertSessionHasErrors('email');
});

it('blocks regular admins from creating super admin users', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->from('/admin')
        ->post('/admin/users', [
            'name' => 'Super Admin',
            'email' => 'super@example.com',
            'role' => 'super_admin',
            'is_active' => true,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect('/admin')
        ->assertSessionHasErrors('role');

    expect(User::query()->where('email', 'super@example.com')->exists())->toBeFalse();
});

it('blocks super admins from creating another super admin user', function (): void {
    $superAdmin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($superAdmin)
        ->from('/admin')
        ->post('/admin/users', [
            'name' => 'Second Super Admin',
            'email' => 'second-super@example.com',
            'role' => 'super_admin',
            'is_active' => true,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect('/admin')
        ->assertSessionHasErrors('role');

    expect(User::query()->where('email', 'second-super@example.com')->exists())->toBeFalse();
});

it('blocks members from creating users', function (): void {
    $member = User::factory()->create(['role' => 'member']);

    $this->actingAs($member)
        ->post('/admin/users', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'role' => 'member',
            'is_active' => true,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertForbidden();
});

it('blocks admins from promoting users to super admin', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);
    $member = User::factory()->create(['role' => 'member']);

    $this->actingAs($admin)
        ->from('/admin')
        ->patch("/admin/users/{$member->id}", [
            'role' => 'super_admin',
            'is_active' => true,
        ])
        ->assertRedirect('/admin')
        ->assertSessionHasErrors('role');

    expect($member->fresh()->role)->toBe('member');
});

it('blocks super admins from promoting users to super admin', function (): void {
    $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $member = User::factory()->create(['role' => 'member']);

    $this->actingAs($superAdmin)
        ->from('/admin')
        ->patch("/admin/users/{$member->id}", [
            'role' => 'super_admin',
            'is_active' => true,
        ])->assertRedirect('/admin')
        ->assertSessionHasErrors('role');

    expect($member->fresh()->role)->toBe('member');
});

it('blocks changes to the protected super admin account', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);
    $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

    $this->actingAs($admin)
        ->patch("/admin/users/{$superAdmin->id}", [
            'role' => 'admin',
            'is_active' => false,
        ])
        ->assertStatus(422);

    expect($superAdmin->fresh()->role)->toBe('super_admin')
        ->and($superAdmin->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action_type', 'user.updated')->where('resource_id', (string) $superAdmin->id)->exists())->toBeFalse();
});

it('enforces one super admin at the database level', function (): void {
    User::factory()->create(['role' => 'super_admin']);

    expect(fn () => User::factory()->create(['role' => 'super_admin']))
        ->toThrow(QueryException::class);
});
