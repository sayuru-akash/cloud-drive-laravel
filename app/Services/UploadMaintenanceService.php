<?php

namespace App\Services;

use App\Enums\FileStatus;
use App\Enums\UploadStatus;
use App\Models\DriveFile;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class UploadMaintenanceService
{
    public function expireStaleUploads(?User $user = null): int
    {
        $uploads = Upload::query()
            ->with('file')
            ->whereIn('upload_status', [
                UploadStatus::Initiated->value,
                UploadStatus::Uploading->value,
            ])
            ->where('expires_at', '<=', now())
            ->when($user, fn (Builder $query) => $query->where('initiated_by_user_id', $user->id))
            ->get();

        if ($uploads->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($uploads): void {
            Upload::query()
                ->whereIn('id', $uploads->pluck('id'))
                ->update(['upload_status' => UploadStatus::Failed->value]);

            DriveFile::query()
                ->whereIn('id', $uploads->pluck('file_id')->filter()->unique())
                ->where('status', FileStatus::Pending)
                ->update([
                    'status' => FileStatus::Failed->value,
                    'is_deleted' => true,
                    'deleted_at' => now(),
                ]);
        });

        return $uploads->count();
    }
}
