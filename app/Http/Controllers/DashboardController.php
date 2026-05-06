<?php

namespace App\Http\Controllers;

use App\Enums\FileStatus;
use App\Models\DriveFile;
use App\Models\ShareLink;
use App\Models\Upload;
use App\Services\DrivePermissionService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(DrivePermissionService $permissions): Response
    {
        $user = request()->user();
        $admin = $permissions->isAdmin($user);
        $scope = fn ($query) => $admin ? $query : $query->where('owner_user_id', $user->id);

        return Inertia::render('Dashboard', [
            'stats' => [
                'files' => $scope(DriveFile::query()->where('is_deleted', false)->where('status', FileStatus::Ready))->count(),
                'shares' => ($admin ? ShareLink::query() : ShareLink::query()->where('created_by_user_id', $user->id))
                    ->where('is_revoked', false)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->count(),
                'trash' => $scope(DriveFile::query()->where('is_deleted', true))->count(),
                'pending' => Upload::query()->where('initiated_by_user_id', $user->id)->whereIn('upload_status', ['initiated', 'uploading'])->count(),
            ],
            'recentFiles' => $scope(DriveFile::query()->with('currentVersion')->latest('updated_at'))->limit(6)->get(),
            'recentUploads' => Upload::query()->with('file')->where('initiated_by_user_id', $user->id)->latest()->limit(5)->get(),
        ]);
    }
}
