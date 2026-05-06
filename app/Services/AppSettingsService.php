<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;

class AppSettingsService
{
    /** @return array{maxUploadSizeBytes:int, retentionDays:int, shareExpiryDays:int, blockedExtensions:array<int,string>, parallelFileUploads:int, parallelPartUploads:int} */
    public function values(): array
    {
        $stored = AppSetting::query()->pluck('value', 'key');

        return [
            'maxUploadSizeBytes' => (int) ($stored['maxUploadSizeBytes'] ?? config('drive.max_upload_size_bytes')),
            'retentionDays' => (int) ($stored['retentionDays'] ?? config('drive.soft_delete_retention_days')),
            'shareExpiryDays' => (int) ($stored['shareExpiryDays'] ?? config('drive.default_share_expiry_days')),
            'blockedExtensions' => $stored['blockedExtensions'] ?? config('drive.blocked_extensions'),
            'parallelFileUploads' => (int) config('drive.parallel_file_uploads'),
            'parallelPartUploads' => (int) config('drive.parallel_part_uploads'),
        ];
    }

    /** @param array<string, mixed> $input */
    public function update(array $input, User $user): array
    {
        $settings = [
            'maxUploadSizeBytes' => min(max((int) ($input['max_upload_size_bytes'] ?? 0), 1), 50 * 1024 * 1024 * 1024),
            'retentionDays' => min(max((int) ($input['retention_days'] ?? 30), 1), 365),
            'shareExpiryDays' => min(max((int) ($input['share_expiry_days'] ?? 7), 1), 90),
            'blockedExtensions' => collect(explode(',', (string) ($input['blocked_extensions'] ?? '')))
                ->map(fn (string $extension): string => strtolower(ltrim(trim($extension), '.')))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];

        foreach ($settings as $key => $value) {
            AppSetting::query()->updateOrCreate(['key' => $key], [
                'value' => $value,
                'updated_by_user_id' => $user->id,
                'updated_at' => now(),
            ]);
        }

        return $settings;
    }
}
