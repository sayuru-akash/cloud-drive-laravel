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
        $share = $this->resolve($token);

        return Inertia::render('public/Share', [
            'available' => (bool) $share,
            'file' => $share?->file,
            'downloadUrl' => $share ? route('public-share.download', ['token' => $token]) : null,
        ]);
    }

    public function download(string $token, ObjectStorageService $storage, AuditLogger $audit): RedirectResponse
    {
        $share = $this->resolve($token);
        abort_unless($share, 404);
        $file = $share->file()->with('currentVersion')->firstOrFail();
        $audit->log('file.downloaded', 'file', $file->id, ['publicShareId' => $share->id], request());

        return redirect()->away($storage->createDownloadUrl($file->currentVersion->storage_key, $file->display_name), 307);
    }

    private function resolve(string $token): ?ShareLink
    {
        $share = ShareLink::query()->where('token_hash', hash('sha256', $token))->first();
        if (! $share || $share->is_revoked || ($share->expires_at && $share->expires_at->isPast())) {
            return null;
        }
        $file = $share->file()->first();

        return $file && $file->status === FileStatus::Ready && ! $file->is_deleted ? $share : null;
    }
}
