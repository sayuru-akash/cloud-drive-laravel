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
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ShareLinkController extends Controller
{
    public function index(DrivePermissionService $permissions): Response
    {
        $user = request()->user();
        $query = ShareLink::query()->with(['creator', 'file.currentVersion'])->latest();

        if (! $permissions->isAdmin(request()->user())) {
            $query->where('created_by_user_id', $user->id);
        }

        return Inertia::render('shared/Index', [
            'shares' => $query
                ->paginate(20)
                ->withQueryString()
                ->through(function (ShareLink $share) use ($permissions, $user): array {
                    $status = $this->status($share);

                    return [
                        'id' => $share->id,
                        'resource_id' => $share->resource_id,
                        'mode' => $share->mode->value,
                        'status' => $status,
                        'public_url' => $status === 'active' && $share->token_encrypted
                            ? route('public-share.show', ['token' => $share->token_encrypted])
                            : null,
                        'is_revoked' => $share->is_revoked,
                        'expires_at' => $share->expires_at,
                        'created_at' => $share->created_at,
                        'creator' => $permissions->isAdmin($user) ? [
                            'name' => $share->creator?->name,
                            'email' => $share->creator?->email,
                        ] : null,
                        'file' => $share->file ? [
                            'display_name' => $share->file->display_name,
                            'size_bytes' => $share->file->size_bytes,
                            'mime_type' => $share->file->mime_type,
                        ] : null,
                    ];
                }),
        ]);
    }

    public function store(Request $request, string $file, DrivePermissionService $permissions, AppSettingsService $settings, AuditLogger $audit): RedirectResponse
    {
        $file = DriveFile::query()->find($file);
        if (! $file || $file->is_deleted) {
            return redirect()
                ->route('files.index')
                ->with('error', 'That file is no longer available.');
        }

        abort_unless($permissions->canManage($request->user(), $file), 403);

        if (! $this->isFileShareable($file)) {
            return back()->with('error', 'Only ready files with a completed upload can be shared.');
        }

        $data = $request->validate([
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'mode' => ['nullable', Rule::in([ShareMode::Download->value])],
        ]);
        $days = (int) ($data['expires_days'] ?? $settings->values()['shareExpiryDays']);
        $token = Str::random(48);
        $share = ShareLink::query()->create([
            'resource_type' => ShareResourceType::File,
            'resource_id' => $file->id,
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => $token,
            'created_by_user_id' => $request->user()->id,
            'mode' => ShareMode::Download->value,
            'expires_at' => now()->addDays($days),
        ]);
        $audit->log('share.created', 'share', $share->id, ['fileId' => $file->id, 'expiresDays' => $days], $request);

        return back()
            ->with('success', 'Share link created.')
            ->with('shareUrl', route('public-share.show', ['token' => $token]));
    }

    public function revoke(Request $request, string $share, DrivePermissionService $permissions, AuditLogger $audit): RedirectResponse
    {
        $share = ShareLink::query()->find($share);
        if (! $share) {
            return redirect()
                ->route('shared.index')
                ->with('error', 'That share link is no longer available.');
        }

        abort_unless($permissions->isAdmin($request->user()) || (int) $share->created_by_user_id === (int) $request->user()->id, 403);

        if ($share->is_revoked) {
            return back()->with('success', 'Share link is already revoked.');
        }

        $share->update(['is_revoked' => true]);
        $audit->log('share.revoked', 'share', $share->id, [], $request);

        return back()->with('success', 'Share revoked.');
    }

    private function isFileShareable(DriveFile $file): bool
    {
        return $file->status === FileStatus::Ready
            && ! $file->is_deleted
            && filled($file->current_version_id);
    }

    private function status(ShareLink $share): string
    {
        if ($share->is_revoked) {
            return 'revoked';
        }

        if ($share->expires_at?->isPast()) {
            return 'expired';
        }

        if (! $share->file || ! $this->isFileShareable($share->file)) {
            return 'unavailable';
        }

        return 'active';
    }
}
