<?php

namespace App\Http\Controllers;

use App\Enums\FileStatus;
use App\Models\ShareLink;
use App\Services\AuditLogger;
use App\Services\ObjectStorageService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PublicShareController extends Controller
{
    public function show(string $token): Response
    {
        $resolved = $this->resolve($token);
        $share = $resolved['share'];
        $file = $share?->file;

        return Inertia::render('public/Share', [
            'available' => (bool) $share,
            'status' => $resolved['status'],
            'file' => $file ? [
                'display_name' => $file->display_name,
                'size_bytes' => $file->size_bytes,
                'mime_type' => $file->mime_type,
            ] : null,
            'downloadUrl' => $share ? route('public-share.download', ['token' => $token]) : null,
            'expiresAt' => $share?->expires_at,
        ]);
    }

    public function download(string $token, ObjectStorageService $storage, AuditLogger $audit): RedirectResponse
    {
        $share = $this->resolve($token)['share'];
        abort_unless($share, 404);
        abort_unless($storage->isConfigured(), 503, 'Object storage is not configured.');
        $file = $share->file()->with('currentVersion')->firstOrFail();
        abort_unless($file->currentVersion, 404);
        $audit->log('file.downloaded', 'file', $file->id, ['publicShareId' => $share->id], request());

        return redirect()->away($storage->createDownloadUrl($file->currentVersion->storage_key, $file->display_name), 307);
    }

    /** @return array{share:?ShareLink,status:string} */
    private function resolve(string $token): array
    {
        $share = ShareLink::query()
            ->with('file.currentVersion')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $share) {
            return ['share' => null, 'status' => 'invalid'];
        }

        if ($share->is_revoked) {
            return ['share' => null, 'status' => 'revoked'];
        }

        if ($share->expires_at?->isPast()) {
            return ['share' => null, 'status' => 'expired'];
        }

        $file = $share->file;
        if (! $file || $file->status !== FileStatus::Ready || $file->is_deleted || ! $file->currentVersion) {
            return ['share' => null, 'status' => 'unavailable'];
        }

        return ['share' => $share, 'status' => 'active'];
    }
}
