<?php

namespace App\Services;

use App\Enums\ShareResourceType;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\ShareLink;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TrashRetentionService
{
    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly DriveQueryService $drive,
        private readonly ObjectStorageService $storage,
    ) {}

    /** @return array{files:int,folders:int,objects:int} */
    public function pruneExpired(): array
    {
        $cutoff = now()->subDays($this->settings->values()['retentionDays']);
        $stats = ['files' => 0, 'folders' => 0, 'objects' => 0];

        $folders = Folder::query()
            ->where('is_deleted', true)
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<=', $cutoff)
            ->oldest('deleted_at')
            ->get();

        foreach ($folders as $folder) {
            $folder = Folder::query()->where('is_deleted', true)->find($folder->id);

            if (! $folder) {
                continue;
            }

            $result = $this->purgeFolder($folder);
            $stats['folders'] += $result['folders'];
            $stats['files'] += $result['files'];
            $stats['objects'] += $result['objects'];
        }

        $files = DriveFile::query()
            ->with(['uploads', 'versions'])
            ->where('is_deleted', true)
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<=', $cutoff)
            ->oldest('deleted_at')
            ->get();

        foreach ($files as $file) {
            $file = DriveFile::query()
                ->with(['uploads', 'versions'])
                ->where('is_deleted', true)
                ->find($file->id);

            if (! $file) {
                continue;
            }

            $stats['objects'] += $this->purgeFile($file);
            $stats['files']++;
        }

        return $stats;
    }

    public function purgeFile(DriveFile $file): int
    {
        $file->loadMissing(['uploads', 'versions']);
        $storageKeys = $this->storageKeysForFile($file);
        $this->deleteObjects($storageKeys);

        DB::transaction(function () use ($file): void {
            ShareLink::query()
                ->where('resource_type', ShareResourceType::File->value)
                ->where('resource_id', $file->id)
                ->delete();

            $file->delete();
        });

        return count($storageKeys);
    }

    /** @return array{folders:int,files:int,objects:int} */
    public function purgeFolder(Folder $folder): array
    {
        $folderIds = $this->drive->descendantFolderIds($folder->id);
        $files = DriveFile::query()
            ->with(['uploads', 'versions'])
            ->whereIn('folder_id', $folderIds)
            ->get();
        $storageKeys = $files
            ->flatMap(fn (DriveFile $file): array => $this->storageKeysForFile($file))
            ->unique()
            ->values()
            ->all();

        $this->deleteObjects($storageKeys);

        DB::transaction(function () use ($files, $folderIds): void {
            ShareLink::query()
                ->where('resource_type', ShareResourceType::File->value)
                ->whereIn('resource_id', $files->pluck('id'))
                ->delete();

            DriveFile::query()->whereIn('id', $files->pluck('id'))->delete();
            Folder::query()->whereIn('id', $folderIds)->delete();
        });

        return [
            'folders' => count($folderIds),
            'files' => $files->count(),
            'objects' => count($storageKeys),
        ];
    }

    /** @param array<int, string> $storageKeys */
    private function deleteObjects(array $storageKeys): void
    {
        if ($storageKeys !== [] && ! $this->storage->isConfigured()) {
            throw new RuntimeException('Object storage is not configured.');
        }

        foreach ($storageKeys as $storageKey) {
            $this->storage->deleteObject($storageKey);
        }
    }

    /** @return array<int, string> */
    private function storageKeysForFile(DriveFile $file): array
    {
        return $file->versions
            ->pluck('storage_key')
            ->merge($file->uploads->pluck('storage_key'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
