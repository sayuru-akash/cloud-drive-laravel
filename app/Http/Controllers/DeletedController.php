<?php

namespace App\Http\Controllers;

use App\Enums\FileStatus;
use App\Enums\ShareResourceType;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\ShareLink;
use App\Services\AppSettingsService;
use App\Services\AuditLogger;
use App\Services\DrivePermissionService;
use App\Services\DriveQueryService;
use App\Services\ObjectStorageService;
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
                ->get(),
            'folders' => Folder::query()
                ->when(! $admin, fn ($query) => $query->where('owner_user_id', $user->id))
                ->where('is_deleted', true)
                ->latest('deleted_at')
                ->get(),
            'canHardDelete' => $admin,
            'retentionDays' => $settings->values()['retentionDays'],
        ]);
    }

    public function restoreFile(Request $request, DriveFile $file, AppSettingsService $settings, DrivePermissionService $permissions, AuditLogger $audit): RedirectResponse
    {
        abort_unless($permissions->canManage($request->user(), $file), 403);
        abort_unless($permissions->isAdmin($request->user()) || ! $file->deleted_at || $file->deleted_at->addDays($settings->values()['retentionDays'])->isFuture(), 403);
        $file->update(['is_deleted' => false, 'status' => FileStatus::Ready, 'deleted_at' => null]);
        $audit->log('file.restored', 'file', $file->id, [], $request);

        return back()->with('success', 'File restored.');
    }

    public function restoreFolder(Request $request, Folder $folder, AppSettingsService $settings, DrivePermissionService $permissions, DriveQueryService $drive, AuditLogger $audit): RedirectResponse
    {
        abort_unless($permissions->canManage($request->user(), $folder), 403);
        abort_unless($permissions->isAdmin($request->user()) || ! $folder->deleted_at || $folder->deleted_at->addDays($settings->values()['retentionDays'])->isFuture(), 403);
        $folderIds = $drive->descendantFolderIds($folder->id);

        Folder::query()->whereIn('id', $folderIds)->update(['is_deleted' => false, 'deleted_at' => null]);
        DriveFile::query()->whereIn('folder_id', $folderIds)->update(['is_deleted' => false, 'status' => FileStatus::Ready, 'deleted_at' => null]);
        $audit->log('folder.restored', 'folder', $folder->id, ['folderIds' => $folderIds], $request);

        return back()->with('success', 'Folder restored.');
    }

    public function hardDeleteFile(Request $request, DriveFile $file, ObjectStorageService $storage, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        foreach ($file->versions as $version) {
            rescue(fn () => $storage->deleteObject($version->storage_key), report: false);
        }
        ShareLink::query()
            ->where('resource_type', ShareResourceType::File->value)
            ->where('resource_id', $file->id)
            ->delete();
        $audit->log('file.hard_deleted', 'file', $file->id, ['name' => $file->display_name], $request);
        $file->delete();

        return back()->with('success', 'File permanently deleted.');
    }

    public function hardDeleteFolder(Request $request, Folder $folder, DriveQueryService $drive, ObjectStorageService $storage, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $folderIds = $drive->descendantFolderIds($folder->id);
        $files = DriveFile::query()->with('versions')->whereIn('folder_id', $folderIds)->get();

        foreach ($files as $file) {
            foreach ($file->versions as $version) {
                rescue(fn () => $storage->deleteObject($version->storage_key), report: false);
            }
        }

        ShareLink::query()
            ->where('resource_type', ShareResourceType::File->value)
            ->whereIn('resource_id', $files->pluck('id'))
            ->delete();
        DriveFile::query()->whereIn('id', $files->pluck('id'))->delete();
        Folder::query()->whereIn('id', $folderIds)->delete();
        $audit->log('folder.hard_deleted', 'folder', $folder->id, ['folderIds' => $folderIds, 'fileCount' => $files->count()], $request);

        return back()->with('success', 'Folder permanently deleted.');
    }
}
