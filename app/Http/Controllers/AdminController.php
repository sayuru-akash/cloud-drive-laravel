<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function index(Request $request, AppSettingsService $settings): Response
    {
        return Inertia::render('admin/Index', [
            'users' => User::query()
                ->latest()
                ->paginate(20)
                ->through(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'is_active' => $user->is_active,
                    'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                    'created_at' => $user->created_at,
                ])
                ->withQueryString(),
            'settings' => $settings->values(),
            'currentUserId' => $request->user()->id,
            'canCreateSuperAdmin' => $request->user()->role === UserRole::SuperAdmin->value,
        ]);
    }

    public function storeUser(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'role' => ['required', Rule::in(UserRole::values())],
            'is_active' => ['required', 'boolean'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        abort_if($data['role'] === UserRole::SuperAdmin->value && $request->user()->role !== UserRole::SuperAdmin->value, 403);

        $user = new User;
        $user->forceFill([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'password' => $data['password'],
            'role' => $data['role'],
            'is_active' => $data['is_active'],
            'email_verified_at' => now(),
        ])->save();

        $audit->log('user.created', 'user', (string) $user->id, [
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
        ], $request);

        return back()->with('success', 'User created.');
    }

    public function updateUser(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(UserRole::values())],
            'is_active' => ['required', 'boolean'],
        ]);

        abort_if($user->role === UserRole::SuperAdmin->value && $request->user()->role !== UserRole::SuperAdmin->value, 403);
        abort_if($data['role'] === UserRole::SuperAdmin->value && $request->user()->role !== UserRole::SuperAdmin->value, 403);
        abort_if((int) $request->user()->id === (int) $user->id && ! $data['is_active'], 422, 'You cannot disable your own account.');

        $activeSuperAdminsAfterUpdate = User::query()
            ->where('role', 'super_admin')
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->count();

        if ($data['role'] === UserRole::SuperAdmin->value && $data['is_active']) {
            $activeSuperAdminsAfterUpdate++;
        }

        abort_if($activeSuperAdminsAfterUpdate === 0, 422, 'At least one active super admin is required.');

        $user->update($data);
        $audit->log('user.updated', 'user', (string) $user->id, $data, $request);

        return back()->with('success', 'User updated.');
    }

    public function updateSettings(Request $request, AppSettingsService $settings, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'max_upload_size_bytes' => ['required', 'integer', 'min:1'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'share_expiry_days' => ['required', 'integer', 'min:1', 'max:90'],
            'blocked_extensions' => ['nullable', 'string'],
        ]);
        $saved = $settings->update($data, $request->user());
        $audit->log('settings.updated', 'settings', 'workspace', $saved, $request);

        return back()->with('success', 'Settings saved.');
    }
}
