<?php

namespace App\Services;

use App\Enums\FileStatus;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DriveQueryService
{
    public function __construct(private readonly DrivePermissionService $permissions) {}

    /** @return array{folders:Collection<int, Folder>, files:Collection<int, DriveFile>} */
    public function browse(User $user, ?string $folderId, array $filters): array
    {
        $folderQuery = Folder::query()
            ->where('is_deleted', false)
            ->where('parent_folder_id', $folderId)
            ->orderBy('updated_at', 'desc');

        $fileQuery = DriveFile::query()
            ->with('currentVersion')
            ->where('is_deleted', false)
            ->where('status', FileStatus::Ready)
            ->where('folder_id', $folderId);

        if (! $this->permissions->isAdmin($user)) {
            $folderQuery->where(fn (Builder $query) => $query->where('owner_user_id', $user->id)->orWhere('visibility', 'workspace'));
            $fileQuery->where(fn (Builder $query) => $query->where('owner_user_id', $user->id)->orWhere('visibility', 'workspace'));
        }

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $folderQuery->where('name', 'like', "%{$search}%");
            $fileQuery->where('display_name', 'like', "%{$search}%");
        }

        if (($filters['visibility'] ?? '') !== '') {
            $folderQuery->where('visibility', $filters['visibility']);
            $fileQuery->where('visibility', $filters['visibility']);
        }

        if (($filters['type'] ?? '') !== '') {
            $fileQuery->where('mime_type', 'like', $filters['type'].'%');
        }

        $sort = (string) ($filters['sort'] ?? 'updated-desc');
        $direction = str_ends_with($sort, 'asc') ? 'asc' : 'desc';
        $column = match (true) {
            str_starts_with($sort, 'name') => 'display_name',
            str_starts_with($sort, 'size') => 'size_bytes',
            default => 'updated_at',
        };

        return [
            'folders' => $folderQuery->get(),
            'files' => $fileQuery->orderBy($column, $direction)->get(),
        ];
    }

    /** @return array<int, array{id:string,name:string}> */
    public function breadcrumbs(?string $folderId): array
    {
        $trail = [];

        while ($folderId) {
            $folder = Folder::query()->find($folderId);
            if (! $folder) {
                break;
            }

            array_unshift($trail, ['id' => $folder->id, 'name' => $folder->name]);
            $folderId = $folder->parent_folder_id;
        }

        return $trail;
    }

    /** @return array<int, string> */
    public function descendantFolderIds(string $folderId): array
    {
        $ids = [$folderId];
        $queue = [$folderId];

        while ($current = array_shift($queue)) {
            $children = Folder::query()->where('parent_folder_id', $current)->pluck('id')->all();
            foreach ($children as $child) {
                $ids[] = $child;
                $queue[] = $child;
            }
        }

        return $ids;
    }

    public function uniqueName(string $table, ?string $parentColumn, ?string $parentId, string $name, ?string $excludeId = null): string
    {
        $query = match ($table) {
            'folders' => Folder::query()->where('parent_folder_id', $parentId)->where('is_deleted', false),
            default => DriveFile::query()->where('folder_id', $parentId)->where('is_deleted', false),
        };

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $column = $table === 'folders' ? 'name' : 'display_name';
        $existing = $query->pluck($column)->map(fn (string $value): string => strtolower($value))->all();
        $candidate = trim($name);

        if (! in_array(strtolower($candidate), $existing, true)) {
            return $candidate;
        }

        $dot = strrpos($candidate, '.');
        $base = $dot && $dot > 0 ? substr($candidate, 0, $dot) : $candidate;
        $extension = $dot && $dot > 0 ? substr($candidate, $dot) : '';
        $counter = 1;

        do {
            $resolved = "{$base} ({$counter}){$extension}";
            $counter++;
        } while (in_array(strtolower($resolved), $existing, true));

        return $resolved;
    }
}
