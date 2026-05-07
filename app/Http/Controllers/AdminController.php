<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function index(AppSettingsService $settings): Response
    {
        return Inertia::render('admin/Index', [
            'users' => User::query()->latest()->paginate(20)->withQueryString(),
            'settings' => $settings->values(),
        ]);
    }

    public function updateUser(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'in:member,admin,super_admin'],
            'is_active' => ['required', 'boolean'],
        ]);

        abort_if($user->role === 'super_admin' && $request->user()->role !== 'super_admin', 403);
        abort_if($data['role'] === 'super_admin' && $request->user()->role !== 'super_admin', 403);
        abort_if((int) $request->user()->id === (int) $user->id && ! $data['is_active'], 422, 'You cannot disable your own account.');

        $activeSuperAdminsAfterUpdate = User::query()
            ->where('role', 'super_admin')
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->count();

        if ($data['role'] === 'super_admin' && $data['is_active']) {
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
