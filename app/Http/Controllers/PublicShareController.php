<?php

namespace App\Http\Controllers;

use App\Enums\FileStatus;
use App\Enums\ShareResourceType;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\ShareLink;
use App\Services\AuditLogger;
use App\Services\ObjectStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class PublicShareController extends Controller
{
    private const ZIP_FILE_LIMIT = 250;

    private const ZIP_SIZE_LIMIT_BYTES = 2_147_483_648;

    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $resolved = $this->resolve($token);
        $share = $resolved['share'];

        if (! $share) {
            return Inertia::render('public/Share', [
                'available' => false,
                'status' => $resolved['status'],
                'resourceType' => null,
                'file' => null,
                'folder' => null,
                'currentFolderId' => null,
                'breadcrumbs' => [],
                'folders' => [],
                'files' => [],
                'downloadUrl' => null,
                'fileDownloadBaseUrl' => null,
                'expiresAt' => null,
                'folderDownload' => null,
            ]);
        }

        if ($share->resource_type === ShareResourceType::File) {
            $file = $share->file;

            return Inertia::render('public/Share', [
                'available' => true,
                'status' => $resolved['status'],
                'resourceType' => ShareResourceType::File->value,
                'file' => $file ? [
                    'display_name' => $file->display_name,
                    'size_bytes' => $file->size_bytes,
                    'mime_type' => $file->mime_type,
                ] : null,
                'folder' => null,
                'currentFolderId' => null,
                'breadcrumbs' => [],
                'folders' => [],
                'files' => [],
                'downloadUrl' => route('public-share.download', ['token' => $token]),
                'fileDownloadBaseUrl' => null,
                'expiresAt' => $share->expires_at,
                'folderDownload' => null,
            ]);
        }

        $root = $share->folder;
        abort_unless($root, 404);

        $requestedFolderId = $request->string('folder')->toString();
        $currentFolder = $requestedFolderId ? Folder::query()->find($requestedFolderId) : $root;
        $folderIds = $this->availableFolderIds($root);

        if (! $currentFolder || ! in_array($currentFolder->id, $folderIds, true) || $currentFolder->is_deleted) {
            return redirect()->route('public-share.show', ['token' => $token]);
        }

        $downloadableFiles = $this->downloadableFiles($folderIds);
        $downloadableSize = (int) $downloadableFiles->sum('size_bytes');

        return Inertia::render('public/Share', [
            'available' => true,
            'status' => $resolved['status'],
            'resourceType' => ShareResourceType::Folder->value,
            'file' => null,
            'folder' => [
                'id' => $root->id,
                'name' => $root->name,
                'updated_at' => $root->updated_at,
            ],
            'currentFolderId' => $currentFolder->id,
            'breadcrumbs' => $this->folderBreadcrumbs($root, $currentFolder),
            'folders' => Folder::query()
                ->where('parent_folder_id', $currentFolder->id)
                ->where('is_deleted', false)
                ->orderBy('name')
                ->get()
                ->map(fn (Folder $folder): array => [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'updated_at' => $folder->updated_at,
                ]),
            'files' => DriveFile::query()
                ->where('folder_id', $currentFolder->id)
                ->where('is_deleted', false)
                ->where('status', FileStatus::Ready)
                ->whereNotNull('current_version_id')
                ->orderBy('display_name')
                ->get()
                ->map(fn (DriveFile $file): array => [
                    'id' => $file->id,
                    'display_name' => $file->display_name,
                    'size_bytes' => $file->size_bytes,
                    'mime_type' => $file->mime_type,
                    'updated_at' => $file->updated_at,
                ]),
            'downloadUrl' => route('public-share.download', ['token' => $token]),
            'fileDownloadBaseUrl' => url("/api/public-share/{$token}/files"),
            'expiresAt' => $share->expires_at,
            'folderDownload' => [
                'file_count' => $downloadableFiles->count(),
                'size_bytes' => $downloadableSize,
                'limit_exceeded' => $downloadableFiles->count() > self::ZIP_FILE_LIMIT || $downloadableSize > self::ZIP_SIZE_LIMIT_BYTES,
                'file_limit' => self::ZIP_FILE_LIMIT,
                'size_limit_bytes' => self::ZIP_SIZE_LIMIT_BYTES,
            ],
        ]);
    }

    public function download(string $token, ObjectStorageService $storage, AuditLogger $audit): RedirectResponse|BinaryFileResponse|HttpResponse
    {
        $share = $this->resolve($token)['share'];
        abort_unless($share, 404);
        abort_unless($storage->isConfigured(), 503, 'Object storage is not configured.');

        if ($share->resource_type === ShareResourceType::File) {
            $file = $share->file()->with('currentVersion')->firstOrFail();
            abort_unless($this->isFileShareable($file), 404);
            $audit->log('file.downloaded', 'file', $file->id, ['publicShareId' => $share->id], request());

            return redirect()->away($storage->createDownloadUrl($file->currentVersion->storage_key, $file->display_name), 307);
        }

        $root = $share->folder;
        abort_unless($root && ! $root->is_deleted, 404);

        $folderIds = $this->availableFolderIds($root);
        $files = $this->downloadableFiles($folderIds);
        abort_if($files->isEmpty(), 404, 'This folder does not contain downloadable files.');
        abort_if($files->count() > self::ZIP_FILE_LIMIT || $files->sum('size_bytes') > self::ZIP_SIZE_LIMIT_BYTES, 422, 'This folder is too large to download as a zip.');

        $zipPath = $this->createFolderZip($root, $files, $storage);
        $audit->log('folder.downloaded', 'folder', $root->id, ['publicShareId' => $share->id, 'fileCount' => $files->count()], request());

        return response()
            ->download($zipPath, $this->safeFilename($root->name).'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    public function downloadFile(string $token, string $file, ObjectStorageService $storage, AuditLogger $audit): RedirectResponse
    {
        $share = $this->resolve($token)['share'];
        abort_unless($share && $share->resource_type === ShareResourceType::Folder, 404);
        abort_unless($storage->isConfigured(), 503, 'Object storage is not configured.');

        $root = $share->folder;
        abort_unless($root && ! $root->is_deleted, 404);

        $folderIds = $this->availableFolderIds($root);
        $file = DriveFile::query()
            ->with('currentVersion')
            ->whereIn('folder_id', $folderIds)
            ->findOrFail($file);
        abort_unless($this->isFileShareable($file), 404);

        $audit->log('file.downloaded', 'file', $file->id, ['publicShareId' => $share->id, 'folderShareId' => $root->id], request());

        return redirect()->away($storage->createDownloadUrl($file->currentVersion->storage_key, $file->display_name), 307);
    }

    /** @return array{share:?ShareLink,status:string} */
    private function resolve(string $token): array
    {
        $share = ShareLink::query()
            ->with(['file.currentVersion', 'folder'])
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $share) {
            return ['share' => null, 'status' => 'invalid'];
        }

        if ($share->is_revoked) {
            return ['share' => null, 'status' => 'revoked'];
        }

        if ($share->expires_at?->isPast()) {
            return ['share' => null, 'status' => 'expired'];
        }

        if ($share->resource_type === ShareResourceType::Folder) {
            $folder = $share->folder;
            if (! $folder || $folder->is_deleted) {
                return ['share' => null, 'status' => 'unavailable'];
            }

            return ['share' => $share, 'status' => 'active'];
        }

        $file = $share->file;
        if (! $file || ! $this->isFileShareable($file)) {
            return ['share' => null, 'status' => 'unavailable'];
        }

        return ['share' => $share, 'status' => 'active'];
    }

    private function isFileShareable(DriveFile $file): bool
    {
        return $file->status === FileStatus::Ready
            && ! $file->is_deleted
            && (bool) $file->currentVersion;
    }

    /** @param array<int, string> $folderIds */
    private function downloadableFiles(array $folderIds): Collection
    {
        return DriveFile::query()
            ->with('currentVersion')
            ->whereIn('folder_id', $folderIds)
            ->where('is_deleted', false)
            ->where('status', FileStatus::Ready)
            ->whereNotNull('current_version_id')
            ->orderBy('display_name')
            ->get()
            ->filter(fn (DriveFile $file): bool => (bool) $file->currentVersion)
            ->values();
    }

    /** @return array<int, array{id:string,name:string}> */
    private function folderBreadcrumbs(Folder $root, Folder $current): array
    {
        $breadcrumbs = [];
        $folder = $current;

        while ($folder) {
            array_unshift($breadcrumbs, ['id' => $folder->id, 'name' => $folder->name]);
            if ($folder->id === $root->id) {
                break;
            }

            $folder = $folder->parent;
        }

        return $breadcrumbs;
    }

    private function createFolderZip(Folder $root, Collection $files, ObjectStorageService $storage): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'cloud-drive-share-');
        abort_unless($zipPath, 500, 'Could not prepare this folder download.');

        $zip = new ZipArchive;
        abort_unless($zip->open($zipPath, ZipArchive::OVERWRITE) === true, 500, 'Could not prepare this folder download.');

        $folderPaths = $this->folderPaths($root);
        $tempObjects = [];

        try {
            foreach ($files as $file) {
                $objectPath = tempnam(sys_get_temp_dir(), 'cloud-drive-object-');
                abort_unless($objectPath && $file->currentVersion, 500, 'Could not prepare this folder download.');
                $tempObjects[] = $objectPath;
                $storage->writeObjectToPath($file->currentVersion->storage_key, $objectPath);
                $folderPath = $folderPaths[$file->folder_id] ?? $this->safeFilename($root->name);
                $zip->addFile($objectPath, $folderPath.'/'.$this->safeFilename($file->display_name));
            }
        } catch (\Throwable $throwable) {
            $zip->close();
            @unlink($zipPath);

            foreach ($tempObjects as $objectPath) {
                @unlink($objectPath);
            }

            throw $throwable;
        }

        $closed = $zip->close();

        foreach ($tempObjects as $objectPath) {
            @unlink($objectPath);
        }

        abort_unless($closed, 500, 'Could not prepare this folder download.');

        return $zipPath;
    }

    /** @return array<string, string> */
    private function folderPaths(Folder $root): array
    {
        $folders = Folder::query()
            ->whereIn('id', $this->availableFolderIds($root))
            ->get()
            ->keyBy('id');

        $paths = [];
        foreach ($folders as $folder) {
            $segments = [];
            $current = $folder;
            while ($current) {
                array_unshift($segments, $this->safeFilename($current->name));
                if ($current->id === $root->id) {
                    break;
                }

                $current = $folders->get($current->parent_folder_id);
            }

            $paths[$folder->id] = implode('/', $segments);
        }

        return $paths;
    }

    /** @return array<int, string> */
    private function availableFolderIds(Folder $root): array
    {
        $ids = [$root->id];
        $queue = [$root->id];

        while ($current = array_shift($queue)) {
            $children = Folder::query()
                ->where('parent_folder_id', $current)
                ->where('is_deleted', false)
                ->pluck('id')
                ->all();

            foreach ($children as $child) {
                $ids[] = $child;
                $queue[] = $child;
            }
        }

        return $ids;
    }

    private function safeFilename(string $name): string
    {
        $name = Str::of($name)
            ->replace(["\0", '/', '\\', "\r", "\n"], ' ')
            ->squish()
            ->trim()
            ->toString();

        return $name !== '' ? $name : 'download';
    }
}
