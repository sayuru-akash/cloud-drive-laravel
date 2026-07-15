<?php

namespace App\Http\Controllers\Settings;

use App\Enums\FileStatus;
use App\Enums\UploadStatus;
use App\Http\Controllers\Controller;
use App\Models\DriveFile;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\Upload;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\DrivePermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UsageController extends Controller
{
    public function __invoke(
        Request $request,
        DrivePermissionService $permissions,
        AppSettingsService $settings,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $isWorkspaceScope = $permissions->isAdmin($user);
        $activeBytes = $this->storedBytes($user, $isWorkspaceScope, false);
        $trashBytes = $this->storedBytes($user, $isWorkspaceScope, true);
        $fileScope = $this->fileScope($user, $isWorkspaceScope);
        $folderScope = $this->folderScope($user, $isWorkspaceScope);
        $uploadScope = Upload::query()
            ->when(! $isWorkspaceScope, fn (Builder $query) => $query->where('initiated_by_user_id', $user->id));

        return Inertia::render('settings/Usage', [
            'usage' => [
                'scope' => $isWorkspaceScope ? 'workspace' : 'personal',
                'storedBytes' => $activeBytes + $trashBytes,
                'activeBytes' => $activeBytes,
                'trashBytes' => $trashBytes,
                'activeFiles' => (clone $fileScope)
                    ->where('is_deleted', false)
                    ->where('status', FileStatus::Ready)
                    ->count(),
                'activeFolders' => (clone $folderScope)->where('is_deleted', false)->count(),
                'trashItems' => (clone $fileScope)->where('is_deleted', true)->count()
                    + (clone $folderScope)->where('is_deleted', true)->count(),
                'activeUploadBytes' => $uploadScope
                    ->whereIn('upload_status', [UploadStatus::Initiated, UploadStatus::Uploading])
                    ->where('expires_at', '>', now())
                    ->sum('size_bytes'),
                'largestFiles' => (clone $fileScope)
                    ->where('is_deleted', false)
                    ->where('status', FileStatus::Ready)
                    ->latest('size_bytes')
                    ->limit(5)
                    ->get(['id', 'display_name', 'mime_type', 'size_bytes', 'updated_at']),
            ],
            'policy' => [
                'maxUploadSizeBytes' => $settings->values()['maxUploadSizeBytes'],
                'multipartThresholdBytes' => (int) config('drive.multipart_threshold_bytes'),
                'multipartChunkSizeBytes' => (int) config('drive.multipart_chunk_size_bytes'),
                'parallelFileUploads' => (int) config('drive.parallel_file_uploads'),
            ],
        ]);
    }

    private function fileScope(User $user, bool $isWorkspaceScope): Builder
    {
        return DriveFile::query()
            ->when(! $isWorkspaceScope, fn (Builder $query) => $query->where('owner_user_id', $user->id));
    }

    private function folderScope(User $user, bool $isWorkspaceScope): Builder
    {
        return Folder::query()
            ->when(! $isWorkspaceScope, fn (Builder $query) => $query->where('owner_user_id', $user->id));
    }

    private function storedBytes(User $user, bool $isWorkspaceScope, bool $deleted): int
    {
        return (int) FileVersion::query()
            ->whereHas('file', fn (Builder $query) => $query
                ->when(! $isWorkspaceScope, fn (Builder $owned) => $owned->where('owner_user_id', $user->id))
                ->where('is_deleted', $deleted))
            ->sum('size_bytes');
    }
}
