<?php

namespace App\Http\Controllers;

use App\Models\DriveFile;
use App\Models\Folder;
use App\Services\DrivePermissionService;
use App\Services\DriveQueryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MoveDestinationController extends Controller
{
    public function __invoke(Request $request, DrivePermissionService $permissions, DriveQueryService $drive): JsonResponse
    {
        $data = $request->validate([
            'target_kind' => ['required', Rule::in(['file', 'folder'])],
            'target_id' => ['required', 'string', 'max:64'],
            'parent_folder_id' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $target = $data['target_kind'] === 'folder'
            ? Folder::query()->where('is_deleted', false)->findOrFail($data['target_id'])
            : DriveFile::query()->where('is_deleted', false)->findOrFail($data['target_id']);
        abort_unless($permissions->canManage($request->user(), $target), 403);

        $excludedFolderIds = $target instanceof Folder
            ? $drive->descendantFolderIds($target->id)
            : [];
        $parentFolderId = $data['parent_folder_id'] ?? null;

        if ($parentFolderId) {
            abort_if(in_array($parentFolderId, $excludedFolderIds, true), 422, 'That folder cannot be used as a destination.');
            $parent = Folder::query()->where('is_deleted', false)->findOrFail($parentFolderId);
            abort_unless($permissions->canManage($request->user(), $parent), 403);
        }

        $folders = Folder::query()
            ->where('is_deleted', false)
            ->where('parent_folder_id', $parentFolderId)
            ->when(
                ! $permissions->isAdmin($request->user()),
                fn (Builder $query): Builder => $query->where('owner_user_id', $request->user()->id),
            )
            ->when(
                $excludedFolderIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn('id', $excludedFolderIds),
            );

        if ($search = trim((string) ($data['q'] ?? ''))) {
            $needle = '%'.Str::of($search)->lower()->replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_']).'%';
            $folders->whereRaw('lower(name) like ?', [$needle]);
        }

        $page = $folders
            ->orderBy('name')
            ->simplePaginate(50, ['id', 'name'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'parentFolderId' => $parentFolderId,
            'breadcrumbs' => $this->manageableBreadcrumbs($parentFolderId, $request, $permissions),
            'folders' => collect($page->items())->map(fn (Folder $folder): array => [
                'id' => $folder->id,
                'name' => $folder->name,
            ])->values(),
            'page' => $page->currentPage(),
            'hasMore' => $page->hasMorePages(),
        ]);
    }

    /** @return array<int, array{id:string,name:string}> */
    private function manageableBreadcrumbs(?string $folderId, Request $request, DrivePermissionService $permissions): array
    {
        $breadcrumbs = [];

        while ($folderId) {
            $folder = Folder::query()->where('is_deleted', false)->find($folderId);

            if (! $folder || ! $permissions->canManage($request->user(), $folder)) {
                break;
            }

            array_unshift($breadcrumbs, ['id' => $folder->id, 'name' => $folder->name]);
            $folderId = $folder->parent_folder_id;
        }

        return $breadcrumbs;
    }
}
