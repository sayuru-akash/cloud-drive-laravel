<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Services\AppSettingsService;
use App\Services\DrivePermissionService;
use App\Services\DriveQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FilesController extends Controller
{
    public function index(Request $request, DriveQueryService $drive, DrivePermissionService $permissions, AppSettingsService $settings): Response|RedirectResponse
    {
        $data = $request->validate([
            'folder' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:80'],
            'visibility' => ['nullable', Rule::in(['private', 'workspace'])],
            'sort' => ['nullable', Rule::in(['updated-desc', 'updated-asc', 'name-asc', 'name-desc', 'size-asc', 'size-desc'])],
            'folders_page' => ['nullable', 'integer', 'min:1'],
            'files_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $folderId = $data['folder'] ?? null;
        $folder = $folderId ? Folder::query()->find($folderId) : null;

        if ($folderId && (! $folder || $folder->is_deleted)) {
            return redirect()
                ->route('files.index')
                ->with('error', 'That folder is no longer available.');
        }

        if ($folder) {
            abort_unless($permissions->canView($request->user(), $folder), 403);
        }

        $filters = collect($data)
            ->only(['q', 'type', 'visibility', 'sort'])
            ->filter(fn (mixed $value): bool => filled($value))
            ->all();
        $items = $drive->browse($request->user(), $folderId, $filters);

        return Inertia::render('files/Index', [
            'folderId' => $folderId,
            'breadcrumbs' => $drive->breadcrumbs($folderId),
            'folders' => $items['folders'],
            'files' => $items['files'],
            'filters' => $filters,
            'settings' => $settings->values(),
            'canManageCurrentLocation' => $folder ? $permissions->canManage($request->user(), $folder) : true,
        ]);
    }
}
