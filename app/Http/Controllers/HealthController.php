<?php

namespace App\Http\Controllers;

use App\Services\AppSettingsService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(AppSettingsService $settings): JsonResponse
    {
        $values = $settings->values();

        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toISOString(),
            'appUrl' => config('app.url'),
            'uploadLimitBytes' => $values['maxUploadSizeBytes'],
            'trashRetentionDays' => $values['retentionDays'],
            'defaultShareExpiryDays' => $values['shareExpiryDays'],
            'ready' => [
                'database' => true,
                'storageConfigured' => filled(config('drive.storage.endpoint')) && filled(config('drive.storage.bucket')),
            ],
        ]);
    }
}
