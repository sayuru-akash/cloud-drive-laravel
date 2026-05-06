<?php

namespace App\Http\Controllers;

use App\Models\DriveFile;
use App\Models\Folder;
use App\Services\AuditLogger;
use App\Services\DrivePermissionService;
use App\Services\DriveQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FileController extends Controller
{
    public function update(Request $request, DriveFile $file, DrivePermissionService $permissions, DriveQueryService $drive, AuditLogger $audit): RedirectResponse
    {
        abort_unless($permissions->canManage($request->user(), $file), 403);
        $data = $request->validate([
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'visibility' => ['sometimes', 'required', 'in:private,workspace'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'exists:folders,id'],
        ]);

        if (array_key_exists('folder_id', $data) && $data['folder_id']) {
            $targetFolder = Folder::query()->findOrFail($data['folder_id']);
            abort_unless($permissions->canManage($request->user(), $targetFolder), 403);
        }

        if (array_key_exists('display_name', $data) || array_key_exists('folder_id', $data)) {
            $targetFolderId = array_key_exists('folder_id', $data) ? $data['folder_id'] : $file->folder_id;
            $data['display_name'] = $drive->uniqueName('files', 'folder_id', $targetFolderId, $data['display_name'] ?? $file->display_name, $file->id);
        }

        $file->update($data);
        $audit->log('file.updated', 'file', $file->id, $data, $request);

        return back()->with('success', 'File updated.');
    }

    public function destroy(Request $request, DriveFile $file, DrivePermissionService $permissions, AuditLogger $audit): RedirectResponse
    {
        abort_unless($permissions->canManage($request->user(), $file), 403);
        $file->update(['is_deleted' => true, 'status' => 'deleted', 'deleted_at' => now()]);
        $audit->log('file.deleted', 'file', $file->id, ['name' => $file->display_name], $request);

        return back()->with('success', 'File moved to trash.');
    }
}
