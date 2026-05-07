<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canDeleteAccount', true)
            );
    }

    public function test_super_admin_profile_page_hides_account_deletion(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);

        $this
            ->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canDeleteAccount', false)
            );
    }

    public function test_legacy_theme_settings_page_is_not_available(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->get('/settings/'.implode('', ['appear', 'ance']))
            ->assertNotFound();
    }

    public function test_inactive_users_are_logged_out_before_accessing_profile_settings(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this
            ->actingAs($user)
            ->get(route('profile.edit'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
                'delete_confirmation' => 'DELETE MY ACCOUNT',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
                'delete_confirmation' => 'DELETE MY ACCOUNT',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }

    public function test_delete_account_requires_typed_confirmation(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'password',
                'delete_confirmation' => 'delete my account',
            ])
            ->assertSessionHasErrors('delete_confirmation')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
        $this->assertAuthenticatedAs($user);
    }

    public function test_super_admin_cannot_delete_their_account(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);

        $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'password',
                'delete_confirmation' => 'DELETE MY ACCOUNT',
            ])
            ->assertSessionHasErrors('account')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
        $this->assertAuthenticatedAs($user);
    }
}
