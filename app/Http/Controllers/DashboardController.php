<?php

namespace App\Http\Controllers;

use App\Enums\FileStatus;
use App\Enums\ResourceVisibility;
use App\Enums\UploadStatus;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\ShareLink;
use App\Models\Upload;
use App\Services\DrivePermissionService;
use App\Services\UploadMaintenanceService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(DrivePermissionService $permissions, UploadMaintenanceService $uploads): Response
    {
        $user = request()->user();
        $admin = $permissions->isAdmin($user);
        $visibleScope = fn ($query) => $admin
            ? $query
            : $query->where(fn ($nested) => $nested
                ->where('owner_user_id', $user->id)
                ->orWhere('visibility', ResourceVisibility::Workspace));
        $ownedScope = fn ($query) => $admin ? $query : $query->where('owner_user_id', $user->id);
        $uploads->expireStaleUploads($admin ? null : $user);
        $activeUploadStatuses = [
            UploadStatus::Initiated->value,
            UploadStatus::Uploading->value,
        ];

        return Inertia::render('Dashboard', [
            'stats' => [
                'files' => $visibleScope(DriveFile::query()->where('is_deleted', false)->where('status', FileStatus::Ready))->count(),
                'shares' => ($admin ? ShareLink::query() : ShareLink::query()->where('created_by_user_id', $user->id))
                    ->where('is_revoked', false)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->count(),
                'trash' => $ownedScope(DriveFile::query()->where('is_deleted', true))->count()
                    + $ownedScope(Folder::query()->where('is_deleted', true))->count(),
                'pending' => Upload::query()
                    ->where('initiated_by_user_id', $user->id)
                    ->whereIn('upload_status', $activeUploadStatuses)
                    ->where('expires_at', '>', now())
                    ->count(),
            ],
            'recentFiles' => $visibleScope(DriveFile::query()
                ->with('currentVersion')
                ->where('is_deleted', false)
                ->where('status', FileStatus::Ready)
                ->latest('updated_at'))->limit(6)->get(),
            'recentUploads' => Upload::query()
                ->with('file')
                ->where('initiated_by_user_id', $user->id)
                ->whereHas('file', fn ($query) => $query->where('is_deleted', false))
                ->where(fn ($query) => $query
                    ->whereNotIn('upload_status', $activeUploadStatuses)
                    ->orWhere('expires_at', '>', now()))
                ->latest('created_at')
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (Upload $upload): array => [
                    'id' => $upload->id,
                    'file_id' => $upload->file_id,
                    'upload_status' => $upload->upload_status->value,
                    'display_name' => $upload->file?->display_name ?? 'Removed upload',
                    'mime_type' => $upload->file?->mime_type,
                    'size_bytes' => $upload->size_bytes,
                    'created_at' => $upload->created_at,
                    'completed_at' => $upload->completed_at,
                    'can_cancel' => in_array($upload->upload_status->value, $activeUploadStatuses, true),
                ]),
        ]);
    }
}
