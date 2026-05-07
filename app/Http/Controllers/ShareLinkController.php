<?php

namespace App\Http\Controllers;

use App\Enums\FileStatus;
use App\Enums\ShareMode;
use App\Enums\ShareResourceType;
use App\Models\DriveFile;
use App\Models\ShareLink;
use App\Services\AppSettingsService;
use App\Services\AuditLogger;
use App\Services\DrivePermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ShareLinkController extends Controller
{
    public function index(DrivePermissionService $permissions): Response
    {
        $query = ShareLink::query()->with(['creator', 'file.currentVersion'])->latest();
        if (! $permissions->isAdmin(request()->user())) {
            $query->where('created_by_user_id', request()->user()->id);
        }

        return Inertia::render('shared/Index', ['shares' => $query->paginate(20)->withQueryString()]);
    }

    public function store(Request $request, DriveFile $file, DrivePermissionService $permissions, AppSettingsService $settings, AuditLogger $audit): RedirectResponse
    {
        abort_unless($permissions->canManage($request->user(), $file), 403);
        abort_unless($file->status === FileStatus::Ready && ! $file->is_deleted && $file->current_version_id, 422, 'Only ready files can be shared.');
        $data = $request->validate(['expires_days' => ['nullable', 'integer', 'min:1', 'max:90']]);
        $days = (int) ($data['expires_days'] ?? $settings->values()['shareExpiryDays']);
        $token = Str::random(48);
        $share = ShareLink::query()->create([
            'resource_type' => ShareResourceType::File,
            'resource_id' => $file->id,
            'token_hash' => hash('sha256', $token),
            'created_by_user_id' => $request->user()->id,
            'mode' => ShareMode::Download,
            'expires_at' => now()->addDays($days),
        ]);
        $audit->log('share.created', 'share', $share->id, ['fileId' => $file->id], $request);

        return back()->with('shareUrl', route('public-share.show', ['token' => $token]));
    }

    public function revoke(Request $request, ShareLink $share, DrivePermissionService $permissions, AuditLogger $audit): RedirectResponse
    {
        abort_unless($permissions->isAdmin($request->user()) || (int) $share->created_by_user_id === (int) $request->user()->id, 403);
        $share->update(['is_revoked' => true]);
        $audit->log('share.revoked', 'share', $share->id, [], $request);

        return back()->with('success', 'Share revoked.');
    }
}
