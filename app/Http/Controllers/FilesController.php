<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Services\AppSettingsService;
use App\Services\DrivePermissionService;
use App\Services\DriveQueryService;
use Inertia\Inertia;
use Inertia\Response;

class FilesController extends Controller
{
    public function index(DriveQueryService $drive, DrivePermissionService $permissions, AppSettingsService $settings): Response
    {
        $folderId = request('folder');
        $folder = $folderId ? Folder::query()->findOrFail($folderId) : null;

        if ($folder) {
            abort_unless($permissions->canView(request()->user(), $folder), 403);
        }

        $items = $drive->browse(request()->user(), $folderId, request()->only(['q', 'type', 'visibility', 'sort']));

        return Inertia::render('files/Index', [
            'folderId' => $folderId,
            'breadcrumbs' => $drive->breadcrumbs($folderId),
            'folders' => $items['folders'],
            'files' => $items['files'],
            'filters' => request()->only(['q', 'type', 'visibility', 'sort']),
            'settings' => $settings->values(),
            'canManage' => request()->user()->isAdmin(),
        ]);
    }
}
