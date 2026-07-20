<?php

namespace App\Http\Controllers;

use App\Enums\FileStatus;
use App\Exceptions\DownloadUnavailableException;
use App\Models\DriveFile;
use App\Services\AuditLogger;
use App\Services\DrivePermissionService;
use App\Services\ObjectStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FilePreviewController extends Controller
{
    public function __invoke(Request $request, DriveFile $file, ObjectStorageService $storage, DrivePermissionService $permissions, AuditLogger $audit): JsonResponse
    {
        abort_unless($file->status === FileStatus::Ready && ! $file->is_deleted, 404);
        abort_unless($permissions->canView($request->user(), $file), 403);
        abort_unless(str_starts_with(strtolower($file->mime_type), 'video/'), 422, 'Only video files can be previewed.');
        abort_unless($storage->isConfigured(), 503, 'Object storage is not configured.');
        $version = $file->currentVersion()->firstOrFail();

        try {
            $storage->ensureDownloadAvailable($version->storage_key);
        } catch (DownloadUnavailableException $exception) {
            report($exception);

            return response()->json(['message' => $exception->userMessage()], 503);
        }

        $audit->log('file.preview.opened', 'file', $file->id, ['name' => $file->display_name], $request);

        return response()->json([
            'url' => $storage->createPreviewUrl($version->storage_key, $file->display_name),
            'expiresIn' => 3600,
        ]);
    }
}
