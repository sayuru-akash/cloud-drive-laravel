<?php

use App\Enums\FileStatus;
use App\Enums\ResourceVisibility;
use App\Enums\ShareMode;
use App\Enums\ShareResourceType;
use App\Enums\UploadStatus;
use App\Models\DriveFile;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\ShareLink;
use App\Models\Upload;
use App\Models\User;
use App\Services\ObjectStorageService;
use Inertia\Testing\AssertableInertia as Assert;

it('blocks moving a file into a folder the user cannot manage', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $targetFolder = Folder::query()->create([
        'name' => 'Private target',
        'owner_user_id' => $other->id,
        'created_by_user_id' => $other->id,
        'visibility' => ResourceVisibility::Private,
    ]);
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'plan.pdf',
        'display_name' => 'plan.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 512,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);

    $this->actingAs($owner)
        ->patch("/files/{$file->id}", ['folder_id' => $targetFolder->id])
        ->assertForbidden();

    expect($file->fresh()->folder_id)->toBeNull();
});

it('redirects stale file actions back to files instead of showing a 404', function (): void {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->from('/files')
        ->delete('/files/file_01missing')
        ->assertRedirect('/files')
        ->assertSessionHas('error', 'That file is no longer available.');
});

it('redirects stale folder actions back to files instead of showing a 404', function (): void {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->from('/files')
        ->delete('/folders/folder_01missing')
        ->assertRedirect('/files')
        ->assertSessionHas('error', 'That folder is no longer available.');
});

it('redirects unavailable folder browsing back to the file root instead of showing a 404', function (): void {
    $owner = User::factory()->create();
    $folder = Folder::query()->create([
        'name' => 'Already trashed',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'visibility' => ResourceVisibility::Private,
        'is_deleted' => true,
        'deleted_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get("/files?folder={$folder->id}")
        ->assertRedirect('/files')
        ->assertSessionHas('error', 'That folder is no longer available.');
});

it('blocks moving a folder into a parent the user cannot manage', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $folder = Folder::query()->create([
        'name' => 'Owned',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'visibility' => ResourceVisibility::Private,
    ]);
    $targetParent = Folder::query()->create([
        'name' => 'Other private',
        'owner_user_id' => $other->id,
        'created_by_user_id' => $other->id,
        'visibility' => ResourceVisibility::Private,
    ]);

    $this->actingAs($owner)
        ->patch("/folders/{$folder->id}", ['parent_folder_id' => $targetParent->id])
        ->assertForbidden();

    expect($folder->fresh()->parent_folder_id)->toBeNull();
});

it('restores a deleted folder together with contained folders and files', function (): void {
    $owner = User::factory()->create();
    $folder = Folder::query()->create([
        'name' => 'Deleted folder',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'visibility' => ResourceVisibility::Private,
        'is_deleted' => true,
        'deleted_at' => now(),
    ]);
    $child = Folder::query()->create([
        'parent_folder_id' => $folder->id,
        'name' => 'Child',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'visibility' => ResourceVisibility::Private,
        'is_deleted' => true,
        'deleted_at' => now(),
    ]);
    $file = DriveFile::query()->create([
        'folder_id' => $child->id,
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'inside.txt',
        'display_name' => 'inside.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 12,
        'status' => FileStatus::Deleted,
        'visibility' => ResourceVisibility::Private,
        'is_deleted' => true,
        'deleted_at' => now(),
    ]);
    $version = FileVersion::query()->create([
        'file_id' => $file->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/inside.txt',
        'size_bytes' => 12,
        'mime_type' => 'text/plain',
        'uploaded_by_user_id' => $owner->id,
    ]);
    $file->update(['current_version_id' => $version->id]);

    $this->actingAs($owner)
        ->patch("/deleted/folders/{$folder->id}/restore")
        ->assertRedirect();

    expect($folder->fresh()->is_deleted)->toBeFalse()
        ->and($child->fresh()->is_deleted)->toBeFalse()
        ->and($file->fresh()->is_deleted)->toBeFalse()
        ->and($file->fresh()->status)->toBe(FileStatus::Ready);
});

it('does not restore a cancelled upload as a ready file', function (): void {
    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'cancelled.txt',
        'display_name' => 'cancelled.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 12,
        'status' => FileStatus::Failed,
        'visibility' => ResourceVisibility::Private,
        'is_deleted' => true,
        'deleted_at' => now(),
    ]);

    $this->actingAs($owner)
        ->patch("/deleted/files/{$file->id}/restore")
        ->assertRedirect();

    expect($file->fresh()->is_deleted)->toBeFalse()
        ->and($file->fresh()->status)->toBe(FileStatus::Failed);
});

it('cancels an active uploading record and moves its pending file to trash', function (): void {
    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'stuck.bin',
        'display_name' => 'stuck.bin',
        'mime_type' => 'application/octet-stream',
        'size_bytes' => 12,
        'status' => FileStatus::Pending,
        'visibility' => ResourceVisibility::Private,
    ]);
    $upload = Upload::query()->create([
        'file_id' => $file->id,
        'initiated_by_user_id' => $owner->id,
        'upload_status' => UploadStatus::Uploading,
        'storage_key' => 'objects/stuck.bin',
        'content_type' => 'application/octet-stream',
        'size_bytes' => 12,
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($owner)
        ->postJson("/api/files/{$file->id}/cancel-upload")
        ->assertOk();

    expect($upload->fresh()->upload_status)->toBe(UploadStatus::Cancelled)
        ->and($file->fresh()->status)->toBe(FileStatus::Failed)
        ->and($file->fresh()->is_deleted)->toBeTrue();
});

it('blocks sharing files that are not ready for download', function (): void {
    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'pending.txt',
        'display_name' => 'pending.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 12,
        'status' => FileStatus::Pending,
        'visibility' => ResourceVisibility::Private,
    ]);

    $this->actingAs($owner)
        ->from('/files')
        ->post("/files/{$file->id}/shares")
        ->assertRedirect('/files')
        ->assertSessionHas('error', 'Only ready files with a completed upload can be shared.');

    expect(ShareLink::query()->count())->toBe(0);
});

it('creates a download share with a chosen expiry and exposes only safe public props', function (): void {
    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'share.pdf',
        'display_name' => 'share.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 512,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);
    $version = FileVersion::query()->create([
        'file_id' => $file->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/share.pdf',
        'size_bytes' => 512,
        'mime_type' => 'application/pdf',
        'uploaded_by_user_id' => $owner->id,
    ]);
    $file->update(['current_version_id' => $version->id]);

    $response = $this->actingAs($owner)
        ->from('/files')
        ->post("/files/{$file->id}/shares", ['expires_days' => 14, 'mode' => 'download'])
        ->assertRedirect('/files')
        ->assertSessionHas('shareUrl');

    $share = ShareLink::query()->sole();
    $shareUrl = $response->baseResponse->getSession()->get('shareUrl');
    $token = basename((string) parse_url((string) $shareUrl, PHP_URL_PATH));

    expect($share->mode)->toBe(ShareMode::Download)
        ->and($share->expires_at?->greaterThan(now()->addDays(13)))->toBeTrue()
        ->and($share->token_encrypted)->toBe($token)
        ->and(hash_equals($share->token_hash, hash('sha256', $token)))->toBeTrue();

    $this->get("/s/{$token}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/Share')
            ->where('available', true)
            ->where('status', 'active')
            ->where('file.display_name', 'share.pdf')
            ->missing('file.owner_user_id')
            ->missing('file.current_version_id')
            ->missing('token_hash'),
        );
});

it('does not expose share token hashes in the shared links page', function (): void {
    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'shared.txt',
        'display_name' => 'shared.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 12,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);
    $version = FileVersion::query()->create([
        'file_id' => $file->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/shared.txt',
        'size_bytes' => 12,
        'mime_type' => 'text/plain',
        'uploaded_by_user_id' => $owner->id,
    ]);
    $file->update(['current_version_id' => $version->id]);
    ShareLink::query()->create([
        'resource_type' => ShareResourceType::File,
        'resource_id' => $file->id,
        'token_hash' => hash('sha256', 'token'),
        'token_encrypted' => 'token',
        'created_by_user_id' => $owner->id,
        'mode' => ShareMode::Download,
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($owner)
        ->get('/shared')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('shared/Index')
            ->where('shares.data.0.status', 'active')
            ->where('shares.data.0.public_url', route('public-share.show', ['token' => 'token']))
            ->where('shares.data.0.file.display_name', 'shared.txt')
            ->missing('shares.data.0.token_hash')
            ->missing('shares.data.0.token_encrypted')
            ->missing('shares.data.0.password_hash'),
        );
});

it('reports revoked and expired public shares without leaking file metadata', function (): void {
    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'private.txt',
        'display_name' => 'private.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 12,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);
    $version = FileVersion::query()->create([
        'file_id' => $file->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/private.txt',
        'size_bytes' => 12,
        'mime_type' => 'text/plain',
        'uploaded_by_user_id' => $owner->id,
    ]);
    $file->update(['current_version_id' => $version->id]);
    ShareLink::query()->create([
        'resource_type' => ShareResourceType::File,
        'resource_id' => $file->id,
        'token_hash' => hash('sha256', 'revoked-token'),
        'created_by_user_id' => $owner->id,
        'mode' => ShareMode::Download,
        'expires_at' => now()->addDay(),
        'is_revoked' => true,
    ]);
    ShareLink::query()->create([
        'resource_type' => ShareResourceType::File,
        'resource_id' => $file->id,
        'token_hash' => hash('sha256', 'expired-token'),
        'created_by_user_id' => $owner->id,
        'mode' => ShareMode::Download,
        'expires_at' => now()->subMinute(),
    ]);

    $this->get('/s/revoked-token')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('available', false)
            ->where('status', 'revoked')
            ->where('file', null),
        );

    $this->get('/s/expired-token')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('available', false)
            ->where('status', 'expired')
            ->where('file', null),
        );
});

it('redirects stale share creation and revoke actions back into the app', function (): void {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->from('/files')
        ->post('/files/file_01missing/shares', ['expires_days' => 7])
        ->assertRedirect('/files')
        ->assertSessionHas('error', 'That file is no longer available.');

    $this->actingAs($owner)
        ->from('/shared')
        ->patch('/shares/share_01missing/revoke')
        ->assertRedirect('/shared')
        ->assertSessionHas('error', 'That share link is no longer available.');
});

it('hard deletes a folder subtree with file versions and share links', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);
    $folder = Folder::query()->create([
        'name' => 'Purge',
        'owner_user_id' => $admin->id,
        'created_by_user_id' => $admin->id,
        'visibility' => ResourceVisibility::Private,
        'is_deleted' => true,
        'deleted_at' => now(),
    ]);
    $file = DriveFile::query()->create([
        'folder_id' => $folder->id,
        'owner_user_id' => $admin->id,
        'created_by_user_id' => $admin->id,
        'original_name' => 'purge.txt',
        'display_name' => 'purge.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 12,
        'status' => FileStatus::Deleted,
        'visibility' => ResourceVisibility::Private,
        'is_deleted' => true,
        'deleted_at' => now(),
    ]);
    FileVersion::query()->create([
        'file_id' => $file->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/purge.txt',
        'size_bytes' => 12,
        'mime_type' => 'text/plain',
        'uploaded_by_user_id' => $admin->id,
    ]);
    ShareLink::query()->create([
        'resource_type' => ShareResourceType::File,
        'resource_id' => $file->id,
        'token_hash' => hash('sha256', 'token'),
        'created_by_user_id' => $admin->id,
        'mode' => ShareMode::Download,
        'expires_at' => now()->addDay(),
    ]);

    $this->mock(ObjectStorageService::class)
        ->shouldReceive('deleteObject')
        ->once()
        ->with('objects/purge.txt');

    $this->actingAs($admin)
        ->delete("/deleted/folders/{$folder->id}/hard-delete")
        ->assertRedirect();

    expect(Folder::query()->find($folder->id))->toBeNull()
        ->and(DriveFile::query()->find($file->id))->toBeNull()
        ->and(FileVersion::query()->where('file_id', $file->id)->exists())->toBeFalse()
        ->and(ShareLink::query()->where('resource_id', $file->id)->exists())->toBeFalse();
});
