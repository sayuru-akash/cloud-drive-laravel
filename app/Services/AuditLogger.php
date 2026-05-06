<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /** @param array<string, mixed> $metadata */
    public function log(string $action, ?string $resourceType = null, ?string $resourceId = null, array $metadata = [], ?Request $request = null): void
    {
        $user = Auth::user();

        AuditLog::query()->create([
            'actor_user_id' => $user?->id,
            'actor_email' => $user?->email,
            'action_type' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'ip_address' => $request?->ips()[0] ?? $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata_json' => $metadata,
            'created_at' => now(),
        ]);
    }
}
