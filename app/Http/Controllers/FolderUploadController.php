<?php

namespace App\Http\Controllers;

use App\Enums\ResourceVisibility;
use App\Models\Folder;
use App\Services\AuditLogger;
use App\Services\DrivePermissionService;
use App\Services\DriveQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FolderUploadController extends Controller
{
    private const MAX_FOLDER_COUNT = 2000;

    private const MAX_FOLDER_DEPTH = 32;

    public function __invoke(Request $request, DrivePermissionService $permissions, DriveQueryService $drive, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'parent_folder_id' => ['nullable', 'string', 'exists:folders,id'],
            'paths' => ['required', 'array', 'min:1', 'max:'.self::MAX_FOLDER_COUNT],
            'paths.*' => ['required', 'string', 'max:8192'],
        ]);
        $parentFolderId = $data['parent_folder_id'] ?? null;

        if ($parentFolderId) {
            $parent = Folder::query()->where('is_deleted', false)->findOrFail($parentFolderId);
            abort_unless($permissions->canManage($request->user(), $parent), 403);
        }

        $paths = $this->expandedPaths($data['paths']);
        $folders = DB::transaction(function () use ($request, $parentFolderId, $paths, $drive): array {
            $folderIds = [];

            foreach ($paths as $path) {
                $separator = strrpos($path, '/');
                $parentPath = $separator === false ? null : substr($path, 0, $separator);
                $name = $separator === false ? $path : substr($path, $separator + 1);
                $resolvedParentId = $parentPath ? $folderIds[$parentPath] : $parentFolderId;
                $resolvedName = $drive->uniqueName('folders', 'parent_folder_id', $resolvedParentId, $name);
                $folder = Folder::query()->create([
                    'parent_folder_id' => $resolvedParentId,
                    'name' => $resolvedName,
                    'owner_user_id' => $request->user()->id,
                    'created_by_user_id' => $request->user()->id,
                    'visibility' => ResourceVisibility::Private,
                ]);
                $folderIds[$path] = $folder->id;
            }

            return $folderIds;
        });

        $rootIds = collect($folders)
            ->filter(fn (string $folderId, string $path): bool => ! str_contains($path, '/'))
            ->values()
            ->all();
        $audit->log('folder.upload_tree.created', 'folder', $rootIds[0], [
            'folderCount' => count($folders),
            'rootFolderIds' => $rootIds,
        ], $request);

        return response()->json([
            'folders' => $folders,
            'folderCount' => count($folders),
        ]);
    }

    /** @param array<int, string> $requestedPaths
     * @return array<int, string>
     */
    private function expandedPaths(array $requestedPaths): array
    {
        $paths = [];

        foreach ($requestedPaths as $requestedPath) {
            abort_if(str_contains($requestedPath, '\\'), 422, 'Folder paths must use forward slashes.');
            $path = trim($requestedPath, '/');
            $segments = explode('/', $path);
            abort_if($path === '' || count($segments) > self::MAX_FOLDER_DEPTH, 422, 'A selected folder path is empty or too deeply nested.');

            foreach ($segments as $index => $segment) {
                abort_if(
                    trim($segment) === ''
                    || in_array($segment, ['.', '..'], true)
                    || Str::length($segment) > 255
                    || preg_match('/[\x00-\x1F\x7F]/u', $segment) === 1,
                    422,
                    'A selected folder contains an unsupported name.',
                );

                $paths[implode('/', array_slice($segments, 0, $index + 1))] = true;
            }
        }

        abort_if(count($paths) > self::MAX_FOLDER_COUNT, 422, 'The selected folder contains too many nested folders.');

        $expanded = array_keys($paths);
        usort($expanded, fn (string $left, string $right): int => substr_count($left, '/') <=> substr_count($right, '/'));

        return $expanded;
    }
}
