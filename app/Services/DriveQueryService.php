<?php

namespace App\Services;

use App\Enums\FileStatus;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class DriveQueryService
{
    public function __construct(private readonly DrivePermissionService $permissions) {}

    /** @return array{folders:LengthAwarePaginator<int, array<string, mixed>>, files:LengthAwarePaginator<int, array<string, mixed>>} */
    public function browse(User $user, ?string $folderId, array $filters): array
    {
        $folderQuery = Folder::query()
            ->where('is_deleted', false)
            ->where('parent_folder_id', $folderId);

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
            $needle = '%'.Str::of($search)->lower()->replace(['\\', '%', '_'], ['\\\\', '\%', '\_']).'%';
            $folderQuery->whereRaw('lower(name) like ?', [$needle]);
            $fileQuery->whereRaw('lower(display_name) like ?', [$needle]);
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
        $fileColumn = match (true) {
            str_starts_with($sort, 'name') => 'display_name',
            str_starts_with($sort, 'size') => 'size_bytes',
            default => 'updated_at',
        };
        $folderColumn = str_starts_with($sort, 'name') ? 'name' : 'updated_at';

        return [
            'folders' => $folderQuery
                ->orderBy($folderColumn, $direction)
                ->paginate(50, ['*'], 'folders_page')
                ->through(fn (Folder $folder): array => [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'visibility' => $folder->visibility->value,
                    'updated_at' => $folder->updated_at,
                    'can_manage' => $this->permissions->canManage($user, $folder),
                ])
                ->withQueryString(),
            'files' => $fileQuery
                ->orderBy($fileColumn, $direction)
                ->paginate(50, ['*'], 'files_page')
                ->through(fn (DriveFile $file): array => [
                    'id' => $file->id,
                    'display_name' => $file->display_name,
                    'visibility' => $file->visibility->value,
                    'size_bytes' => $file->size_bytes,
                    'mime_type' => $file->mime_type,
                    'updated_at' => $file->updated_at,
                    'can_manage' => $this->permissions->canManage($user, $file),
                ])
                ->withQueryString(),
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
        $level = [$folderId];

        while ($level !== []) {
            $level = Folder::query()
                ->whereIn('parent_folder_id', $level)
                ->pluck('id')
                ->all();
            $ids = [...$ids, ...$level];
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
