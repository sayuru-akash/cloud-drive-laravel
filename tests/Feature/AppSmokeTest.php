<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('renders all public pages without server errors', function (): void {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Welcome'));
    $this->get('/privacy')->assertOk()->assertInertia(fn (Assert $page) => $page->component('public/Privacy'));
    $this->get('/s/not-a-real-token')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/Share')
            ->where('available', false)
            ->where('status', 'invalid')
            ->where('file', null));
});

it('redirects protected app pages for guests instead of rendering broken pages', function (string $path): void {
    $this->get($path)->assertRedirect(route('login'));
})->with([
    'dashboard' => '/dashboard',
    'files' => '/files',
    'shared' => '/shared',
    'deleted' => '/deleted',
    'profile settings' => '/settings/profile',
    'security settings' => '/settings/security',
    'admin' => '/admin',
    'audit' => '/audit',
]);

it('renders member workspace pages with their expected inertia components', function (string $path, string $component): void {
    $user = User::factory()->create();

    $request = $this->actingAs($user);

    if ($path === '/settings/security') {
        $request = $request->withSession(['auth.password_confirmed_at' => time()]);
    }

    $request->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'dashboard' => ['/dashboard', 'Dashboard'],
    'files' => ['/files', 'files/Index'],
    'shared' => ['/shared', 'shared/Index'],
    'deleted' => ['/deleted', 'deleted/Index'],
    'profile settings' => ['/settings/profile', 'settings/Profile'],
    'security settings' => ['/settings/security', 'settings/Security'],
]);

it('keeps admin surfaces forbidden for members and renderable for admins', function (string $path, string $component): void {
    $member = User::factory()->create(['role' => 'member']);

    $this->actingAs($member)
        ->get($path)
        ->assertForbidden();

    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'admin' => ['/admin', 'admin/Index'],
    'audit' => ['/audit', 'audit/Index'],
]);
