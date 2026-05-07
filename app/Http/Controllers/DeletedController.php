<?php

namespace App\Http\Controllers;

use App\Enums\FileStatus;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Services\AppSettingsService;
use App\Services\AuditLogger;
use App\Services\DrivePermissionService;
use App\Services\DriveQueryService;
use App\Services\TrashRetentionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeletedController extends Controller
{
    public function index(DrivePermissionService $permissions, AppSettingsService $settings): Response
    {
        $user = request()->user();
        $admin = $permissions->isAdmin($user);

        return Inertia::render('deleted/Index', [
            'files' => DriveFile::query()
                ->when(! $admin, fn ($query) => $query->where('owner_user_id', $user->id))
                ->where('is_deleted', true)
                ->latest('deleted_at')
                ->paginate(30, ['*'], 'files_page')
                ->withQueryString(),
            'folders' => Folder::query()
                ->when(! $admin, fn ($query) => $query->where('owner_user_id', $user->id))
                ->where('is_deleted', true)
                ->latest('deleted_at')
                ->paginate(30, ['*'], 'folders_page')
                ->withQueryString(),
            'canHardDelete' => $admin,
            'retentionDays' => $settings->values()['retentionDays'],
        ]);
    }

    public function restoreFile(Request $request, string $file, AppSettingsService $settings, DrivePermissionService $permissions, AuditLogger $audit): RedirectResponse
    {
        $file = DriveFile::query()->where('is_deleted', true)->find($file);
        if (! $file) {
            return $this->missingTrashRedirect();
        }

        abort_unless($permissions->canManage($request->user(), $file), 403);
        abort_unless($permissions->isAdmin($request->user()) || ! $file->deleted_at || $file->deleted_at->addDays($settings->values()['retentionDays'])->isFuture(), 403);
        $file->update([
            'is_deleted' => false,
            'status' => $file->current_version_id ? FileStatus::Ready : FileStatus::Failed,
            'deleted_at' => null,
        ]);
        $audit->log('file.restored', 'file', $file->id, [], $request);

        return back()->with('success', 'File restored.');
    }

    public function restoreFolder(Request $request, string $folder, AppSettingsService $settings, DrivePermissionService $permissions, DriveQueryService $drive, AuditLogger $audit): RedirectResponse
    {
        $folder = Folder::query()->where('is_deleted', true)->find($folder);
        if (! $folder) {
            return $this->missingTrashRedirect();
        }

        abort_unless($permissions->canManage($request->user(), $folder), 403);
        abort_unless($permissions->isAdmin($request->user()) || ! $folder->deleted_at || $folder->deleted_at->addDays($settings->values()['retentionDays'])->isFuture(), 403);
        $folderIds = $drive->descendantFolderIds($folder->id);

        Folder::query()->whereIn('id', $folderIds)->update(['is_deleted' => false, 'deleted_at' => null]);
        DriveFile::query()
            ->whereIn('folder_id', $folderIds)
            ->whereNotNull('current_version_id')
            ->update(['is_deleted' => false, 'status' => FileStatus::Ready, 'deleted_at' => null]);
        DriveFile::query()
            ->whereIn('folder_id', $folderIds)
            ->whereNull('current_version_id')
            ->update(['is_deleted' => false, 'status' => FileStatus::Failed, 'deleted_at' => null]);
        $audit->log('folder.restored', 'folder', $folder->id, ['folderIds' => $folderIds], $request);

        return back()->with('success', 'Folder restored.');
    }

    public function hardDeleteFile(Request $request, string $file, TrashRetentionService $trash, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $file = DriveFile::query()
            ->with(['uploads', 'versions'])
            ->where('is_deleted', true)
            ->find($file);
        if (! $file) {
            return $this->missingTrashRedirect();
        }

        $trash->purgeFile($file);
        $audit->log('file.hard_deleted', 'file', $file->id, ['name' => $file->display_name], $request);

        return back()->with('success', 'File permanently deleted.');
    }

    public function hardDeleteFolder(Request $request, string $folder, TrashRetentionService $trash, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $folder = Folder::query()->where('is_deleted', true)->find($folder);
        if (! $folder) {
            return $this->missingTrashRedirect();
        }

        $result = $trash->purgeFolder($folder);
        $audit->log('folder.hard_deleted', 'folder', $folder->id, ['folderCount' => $result['folders'], 'fileCount' => $result['files']], $request);

        return back()->with('success', 'Folder permanently deleted.');
    }

    private function missingTrashRedirect(): RedirectResponse
    {
        return redirect()
            ->route('deleted.index')
            ->with('error', 'That deleted item is no longer available.');
    }
}
