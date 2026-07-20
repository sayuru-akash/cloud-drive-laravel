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
    public function update(Request $request, string $file, DrivePermissionService $permissions, DriveQueryService $drive, AuditLogger $audit): RedirectResponse
    {
        $file = $this->resolveActionFile($file);
        if (! $file) {
            return $this->missingFileRedirect();
        }

        abort_unless($permissions->canManage($request->user(), $file), 403);
        $data = $request->validate([
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'visibility' => ['sometimes', 'required', 'in:private,workspace'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'exists:folders,id'],
        ]);
        $previousFolderId = $file->folder_id;
        $previousName = $file->display_name;

        if (array_key_exists('folder_id', $data) && $data['folder_id']) {
            $targetFolder = Folder::query()->where('is_deleted', false)->findOrFail($data['folder_id']);
            abort_unless($permissions->canManage($request->user(), $targetFolder), 403);
        }

        if (array_key_exists('display_name', $data) || array_key_exists('folder_id', $data)) {
            $targetFolderId = array_key_exists('folder_id', $data) ? $data['folder_id'] : $file->folder_id;
            $data['display_name'] = $drive->uniqueName('files', 'folder_id', $targetFolderId, $data['display_name'] ?? $file->display_name, $file->id);
        }

        $file->update($data);
        $moved = array_key_exists('folder_id', $data) && $previousFolderId !== $file->folder_id;
        $audit->log($moved ? 'file.moved' : 'file.updated', 'file', $file->id, $moved ? [
            'fromFolderId' => $previousFolderId,
            'toFolderId' => $file->folder_id,
            'name' => $file->display_name,
        ] : $data, $request);

        $renamedForConflict = $moved && $previousName !== $file->display_name;

        return back()->with('success', match (true) {
            $renamedForConflict => "File moved as \"{$file->display_name}\" to avoid a name conflict.",
            $moved => 'File moved.',
            default => 'File updated.',
        });
    }

    public function destroy(Request $request, string $file, DrivePermissionService $permissions, AuditLogger $audit): RedirectResponse
    {
        $file = $this->resolveActionFile($file);
        if (! $file) {
            return $this->missingFileRedirect();
        }

        abort_unless($permissions->canManage($request->user(), $file), 403);
        $file->update(['is_deleted' => true, 'status' => 'deleted', 'deleted_at' => now()]);
        $audit->log('file.deleted', 'file', $file->id, ['name' => $file->display_name], $request);

        return back()->with('success', 'File moved to trash.');
    }

    private function resolveActionFile(string $fileId): ?DriveFile
    {
        return DriveFile::query()
            ->where('is_deleted', false)
            ->find($fileId);
    }

    private function missingFileRedirect(): RedirectResponse
    {
        return redirect()
            ->route('files.index')
            ->with('error', 'That file is no longer available.');
    }
}
