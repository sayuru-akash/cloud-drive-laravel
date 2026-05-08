<?php

namespace App\Http\Controllers;

use App\Enums\FileStatus;
use App\Enums\ResourceVisibility;
use App\Enums\UploadStatus;
use App\Models\DriveFile;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\Upload;
use App\Services\AppSettingsService;
use App\Services\AuditLogger;
use App\Services\DrivePermissionService;
use App\Services\DriveQueryService;
use App\Services\ObjectStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FileUploadController extends Controller
{
    public function initiate(Request $request, AppSettingsService $settings, ObjectStorageService $storage, DrivePermissionService $permissions, DriveQueryService $drive, AuditLogger $audit): JsonResponse
    {
        abort_unless($storage->isConfigured(), 503, 'Object storage is not configured.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'type' => ['nullable', 'string', 'max:255'],
            'folderId' => ['nullable', 'string', 'exists:folders,id'],
        ]);
        $values = $settings->values();
        $extension = strtolower(pathinfo($data['name'], PATHINFO_EXTENSION));

        abort_if(in_array($extension, $values['blockedExtensions'], true), 422, 'This file type is blocked.');
        abort_if((int) $data['size'] > $values['maxUploadSizeBytes'], 422, 'This file is larger than the workspace limit.');

        if ($data['folderId'] ?? null) {
            $folder = Folder::query()->findOrFail($data['folderId']);
            abort_unless($permissions->canManage($request->user(), $folder), 403);
        }

        $payload = DB::transaction(function () use ($request, $data, $extension, $drive, $storage) {
            $displayName = $drive->uniqueName('files', 'folder_id', $data['folderId'] ?? null, $data['name']);
            $file = DriveFile::query()->create([
                'folder_id' => $data['folderId'] ?? null,
                'owner_user_id' => $request->user()->id,
                'created_by_user_id' => $request->user()->id,
                'original_name' => $data['name'],
                'display_name' => $displayName,
                'extension' => $extension ?: null,
                'mime_type' => $data['type'] ?: 'application/octet-stream',
                'size_bytes' => $data['size'],
                'status' => FileStatus::Pending,
                'visibility' => ResourceVisibility::Private,
            ]);

            $storageKey = $storage->buildStorageKey($file->id, 1, $displayName);
            $multipart = (int) $data['size'] >= (int) config('drive.multipart_threshold_bytes');
            $uploadId = null;

            if ($multipart) {
                $uploadId = $storage->createMultipartUpload($storageKey, $file->mime_type)['uploadId'];
            }

            $upload = Upload::query()->create([
                'file_id' => $file->id,
                'initiated_by_user_id' => $request->user()->id,
                'upload_status' => UploadStatus::Initiated,
                'storage_key' => $storageKey,
                'provider_upload_id' => $uploadId,
                'content_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
                'expires_at' => now()->addDay(),
            ]);

            return compact('file', 'upload', 'multipart');
        });

        $audit->log('file.upload.created', 'file', $payload['file']->id, ['name' => $payload['file']->display_name], $request);

        return response()->json([
            'fileId' => $payload['file']->id,
            'uploadId' => $payload['upload']->id,
            'multipart' => $payload['multipart'],
            'uploadUrl' => $payload['multipart'] ? null : $storage->createUploadUrl($payload['upload']->storage_key, $payload['upload']->content_type),
            'providerUploadId' => $payload['upload']->provider_upload_id,
            'chunkSize' => config('drive.multipart_chunk_size_bytes'),
            'totalParts' => $payload['multipart'] ? (int) ceil($payload['upload']->size_bytes / config('drive.multipart_chunk_size_bytes')) : 1,
        ]);
    }

    public function multipartPart(Request $request, DriveFile $file, ObjectStorageService $storage, DrivePermissionService $permissions): JsonResponse
    {
        abort_unless($permissions->canManage($request->user(), $file), 403);
        $data = $request->validate(['partNumber' => ['required', 'integer', 'min:1', 'max:10000']]);
        $upload = $file->uploads()->where('upload_status', UploadStatus::Initiated)->latest()->firstOrFail();
        abort_unless($upload->provider_upload_id, 422);

        return response()->json([
            'uploadUrl' => $storage->createMultipartPartUploadUrl($upload->storage_key, $upload->provider_upload_id, (int) $data['partNumber']),
        ]);
    }

    public function complete(Request $request, DriveFile $file, ObjectStorageService $storage, DrivePermissionService $permissions, AppSettingsService $settings, AuditLogger $audit): JsonResponse
    {
        abort_unless($permissions->canManage($request->user(), $file), 403);
        $data = $request->validate([
            'parts' => ['nullable', 'array'],
            'parts.*.partNumber' => ['required_with:parts', 'integer'],
            'parts.*.etag' => ['required_with:parts', 'string'],
        ]);
        $upload = $file->uploads()->where('upload_status', UploadStatus::Initiated)->latest()->firstOrFail();

        if ($upload->provider_upload_id) {
            abort_if(empty($data['parts']), 422, 'Multipart parts are required.');
            $storage->completeMultipartUpload($upload->storage_key, $upload->provider_upload_id, $data['parts']);
        }

        $metadata = $storage->objectMetadata($upload->storage_key);

        if (! $metadata) {
            return $this->failCompletion($file, $upload, 'Uploaded object was not found.', 400);
        }

        $actualSizeBytes = $metadata['contentLength'];
        $maxUploadSizeBytes = $settings->values()['maxUploadSizeBytes'];

        if ($actualSizeBytes !== $upload->size_bytes || $actualSizeBytes > $maxUploadSizeBytes) {
            rescue(fn () => $storage->deleteObject($upload->storage_key), report: false);

            return $this->failCompletion($file, $upload, 'Uploaded object did not match the expected upload policy.', 422);
        }

        DB::transaction(function () use ($request, $file, $upload, $storage, $metadata): void {
            $version = FileVersion::query()->create([
                'file_id' => $file->id,
                'version_number' => 1,
                'storage_bucket' => $storage->bucket(),
                'storage_key' => $upload->storage_key,
                'size_bytes' => $metadata['contentLength'],
                'mime_type' => $metadata['contentType'] ?? $upload->content_type,
                'uploaded_by_user_id' => $request->user()->id,
                'created_at' => now(),
            ]);

            $file->update([
                'status' => FileStatus::Ready,
                'current_version_id' => $version->id,
                'size_bytes' => $metadata['contentLength'],
                'mime_type' => $metadata['contentType'] ?? $upload->content_type,
            ]);
            $upload->update(['upload_status' => UploadStatus::Completed, 'completed_at' => now()]);
        });

        $audit->log('file.upload.completed', 'file', $file->id, ['sizeBytes' => $upload->size_bytes], $request);

        return response()->json(['ok' => true]);
    }

    private function failCompletion(DriveFile $file, Upload $upload, string $message, int $status): JsonResponse
    {
        $upload->update(['upload_status' => UploadStatus::Failed]);
        $file->update(['status' => FileStatus::Failed]);

        return response()->json(['message' => $message], $status);
    }

    public function cancel(Request $request, DriveFile $file, ObjectStorageService $storage, DrivePermissionService $permissions, AuditLogger $audit): JsonResponse
    {
        abort_unless($permissions->canManage($request->user(), $file), 403);
        $upload = $file->uploads()
            ->whereIn('upload_status', [
                UploadStatus::Initiated->value,
                UploadStatus::Uploading->value,
            ])
            ->latest()
            ->first();

        if ($upload) {
            if ($upload->provider_upload_id) {
                rescue(fn () => $storage->abortMultipartUpload($upload->storage_key, $upload->provider_upload_id), report: false);
            }
            $upload->update(['upload_status' => UploadStatus::Cancelled]);
        }

        $file->update(['status' => FileStatus::Failed, 'is_deleted' => true, 'deleted_at' => now()]);
        $audit->log('file.upload.cancelled', 'file', $file->id, [], $request);

        return response()->json(['ok' => true]);
    }
}
