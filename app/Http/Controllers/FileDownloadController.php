<?php

namespace App\Http\Controllers;

use App\Enums\FileStatus;
use App\Models\DriveFile;
use App\Services\AuditLogger;
use App\Services\DrivePermissionService;
use App\Services\ObjectStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FileDownloadController extends Controller
{
    public function __invoke(Request $request, DriveFile $file, ObjectStorageService $storage, DrivePermissionService $permissions, AuditLogger $audit): RedirectResponse
    {
        abort_unless($file->status === FileStatus::Ready && ! $file->is_deleted, 404);
        abort_unless($permissions->canView($request->user(), $file), 403);
        abort_unless($storage->isConfigured(), 503, 'Object storage is not configured.');
        $version = $file->currentVersion()->firstOrFail();
        $audit->log('file.downloaded', 'file', $file->id, ['name' => $file->display_name], $request);

        return redirect()->away($storage->createDownloadUrl($version->storage_key, $file->display_name), 307);
    }
}
