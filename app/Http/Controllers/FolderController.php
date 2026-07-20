<?php

namespace App\Http\Controllers;

use App\Enums\ResourceVisibility;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Services\AuditLogger;
use App\Services\DrivePermissionService;
use App\Services\DriveQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function store(Request $request, DriveQueryService $drive, DrivePermissionService $permissions, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_folder_id' => ['nullable', 'string', 'exists:folders,id'],
            'visibility' => ['nullable', 'in:private,workspace'],
        ]);

        if ($data['parent_folder_id'] ?? null) {
            $parent = Folder::query()->findOrFail($data['parent_folder_id']);
            abort_unless($permissions->canManage($request->user(), $parent), 403);
        }

        $folder = Folder::query()->create([
            'parent_folder_id' => $data['parent_folder_id'] ?? null,
            'name' => $drive->uniqueName('folders', 'parent_folder_id', $data['parent_folder_id'] ?? null, $data['name']),
            'owner_user_id' => $request->user()->id,
            'created_by_user_id' => $request->user()->id,
            'visibility' => $data['visibility'] ?? ResourceVisibility::Private,
        ]);

        $audit->log('folder.created', 'folder', $folder->id, ['name' => $folder->name], $request);

        return back()->with('success', 'Folder created.');
    }

    public function update(Request $request, string $folder, DrivePermissionService $permissions, DriveQueryService $drive, AuditLogger $audit): RedirectResponse
    {
        $folder = $this->resolveActionFolder($folder);
        if (! $folder) {
            return $this->missingFolderRedirect();
        }

        abort_unless($permissions->canManage($request->user(), $folder), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'visibility' => ['sometimes', 'required', 'in:private,workspace'],
            'parent_folder_id' => ['sometimes', 'nullable', 'string', 'exists:folders,id'],
        ]);
        $previousParentId = $folder->parent_folder_id;
        $previousName = $folder->name;

        if (array_key_exists('parent_folder_id', $data) && $data['parent_folder_id']) {
            $targetParent = Folder::query()->where('is_deleted', false)->findOrFail($data['parent_folder_id']);
            abort_unless($permissions->canManage($request->user(), $targetParent), 403);
            abort_if(in_array($data['parent_folder_id'], $drive->descendantFolderIds($folder->id), true), 422);
        }

        if (array_key_exists('name', $data) || array_key_exists('parent_folder_id', $data)) {
            $targetParentId = array_key_exists('parent_folder_id', $data) ? $data['parent_folder_id'] : $folder->parent_folder_id;
            $data['name'] = $drive->uniqueName('folders', 'parent_folder_id', $targetParentId, $data['name'] ?? $folder->name, $folder->id);
        }

        $folder->update($data);
        $moved = array_key_exists('parent_folder_id', $data) && $previousParentId !== $folder->parent_folder_id;
        $audit->log($moved ? 'folder.moved' : 'folder.updated', 'folder', $folder->id, $moved ? [
            'fromFolderId' => $previousParentId,
            'toFolderId' => $folder->parent_folder_id,
            'name' => $folder->name,
        ] : $data, $request);

        $renamedForConflict = $moved && $previousName !== $folder->name;

        return back()->with('success', match (true) {
            $renamedForConflict => "Folder moved as \"{$folder->name}\" to avoid a name conflict.",
            $moved => 'Folder moved.',
            default => 'Folder updated.',
        });
    }

    public function destroy(Request $request, string $folder, DriveQueryService $drive, DrivePermissionService $permissions, AuditLogger $audit): RedirectResponse
    {
        $folder = $this->resolveActionFolder($folder);
        if (! $folder) {
            return $this->missingFolderRedirect();
        }

        abort_unless($permissions->canManage($request->user(), $folder), 403);
        $ids = $drive->descendantFolderIds($folder->id);
        Folder::query()->whereIn('id', $ids)->update(['is_deleted' => true, 'deleted_at' => now()]);
        DriveFile::query()->whereIn('folder_id', $ids)->update(['is_deleted' => true, 'status' => 'deleted', 'deleted_at' => now()]);
        $audit->log('folder.deleted', 'folder', $folder->id, ['folderIds' => $ids], $request);

        return back()->with('success', 'Folder moved to trash.');
    }

    private function resolveActionFolder(string $folderId): ?Folder
    {
        return Folder::query()
            ->where('is_deleted', false)
            ->find($folderId);
    }

    private function missingFolderRedirect(): RedirectResponse
    {
        return redirect()
            ->route('files.index')
            ->with('error', 'That folder is no longer available.');
    }
}
