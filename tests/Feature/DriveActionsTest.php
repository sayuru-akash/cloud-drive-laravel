<?php

use App\Enums\FileStatus;
use App\Enums\ResourceVisibility;
use App\Enums\ShareMode;
use App\Enums\ShareResourceType;
use App\Enums\UploadStatus;
use App\Models\AuditLog;
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

it('marks workspace-visible resources as view only for non owners on the files page', function (): void {
    $member = User::factory()->create();
    $other = User::factory()->create();
    $folder = Folder::query()->create([
        'name' => 'Team folder',
        'owner_user_id' => $other->id,
        'created_by_user_id' => $other->id,
        'visibility' => ResourceVisibility::Workspace,
    ]);
    $file = DriveFile::query()->create([
        'owner_user_id' => $other->id,
        'created_by_user_id' => $other->id,
        'original_name' => 'team.pdf',
        'display_name' => 'team.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 512,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Workspace,
    ]);

    $this->actingAs($member)
        ->get('/files')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManageCurrentLocation', true)
            ->where('folders.data.0.id', $folder->id)
            ->where('folders.data.0.can_manage', false)
            ->where('files.data.0.id', $file->id)
            ->where('files.data.0.can_manage', false)
        );

    $this->actingAs($member)
        ->get("/files?folder={$folder->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManageCurrentLocation', false)
        );
});

it('filters the files page from database queries with validated URL state', function (): void {
    $member = User::factory()->create();
    $other = User::factory()->create();
    $matchingFolder = Folder::query()->create([
        'name' => 'Reports',
        'owner_user_id' => $member->id,
        'created_by_user_id' => $member->id,
        'visibility' => ResourceVisibility::Workspace,
    ]);
    Folder::query()->create([
        'name' => 'Invoices',
        'owner_user_id' => $member->id,
        'created_by_user_id' => $member->id,
        'visibility' => ResourceVisibility::Private,
    ]);
    $matchingFile = DriveFile::query()->create([
        'owner_user_id' => $other->id,
        'created_by_user_id' => $other->id,
        'original_name' => 'Q1 Report.pdf',
        'display_name' => 'Q1 Report.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 512,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Workspace,
    ]);
    DriveFile::query()->create([
        'owner_user_id' => $member->id,
        'created_by_user_id' => $member->id,
        'original_name' => 'report-notes.txt',
        'display_name' => 'report-notes.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 256,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Workspace,
    ]);

    $this->actingAs($member)
        ->get('/files?q=report&type=application/pdf&visibility=workspace&sort=name-asc')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', 'report')
            ->where('filters.type', 'application/pdf')
            ->where('filters.visibility', 'workspace')
            ->where('filters.sort', 'name-asc')
            ->where('folders.data.0.id', $matchingFolder->id)
            ->where('files.data.0.id', $matchingFile->id)
            ->where('files.data.0.display_name', 'Q1 Report.pdf')
            ->has('files.data', 1)
        );
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

it('completes an upload only after the stored object metadata matches policy', function (): void {
    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'ready.txt',
        'display_name' => 'ready.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 12,
        'status' => FileStatus::Pending,
        'visibility' => ResourceVisibility::Private,
    ]);
    $upload = Upload::query()->create([
        'file_id' => $file->id,
        'initiated_by_user_id' => $owner->id,
        'upload_status' => UploadStatus::Initiated,
        'storage_key' => 'objects/ready.txt',
        'content_type' => 'text/plain',
        'size_bytes' => 12,
        'expires_at' => now()->addHour(),
    ]);

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('objectMetadata')
        ->once()
        ->with('objects/ready.txt')
        ->andReturn(['contentLength' => 12, 'contentType' => 'text/plain', 'etag' => '"ready"']);
    $storage->shouldReceive('bucket')
        ->once()
        ->andReturn('test-bucket');

    $this->actingAs($owner)
        ->postJson("/api/files/{$file->id}/complete-upload")
        ->assertOk();

    $version = FileVersion::query()->where('file_id', $file->id)->sole();

    expect($upload->fresh()->upload_status)->toBe(UploadStatus::Completed)
        ->and($file->fresh()->status)->toBe(FileStatus::Ready)
        ->and($file->fresh()->size_bytes)->toBe(12)
        ->and($file->fresh()->current_version_id)->toBe($version->id)
        ->and($version->size_bytes)->toBe(12);
});

it('rejects upload completion when the stored object size differs from the declared upload size', function (): void {
    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'oversized.txt',
        'display_name' => 'oversized.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 12,
        'status' => FileStatus::Pending,
        'visibility' => ResourceVisibility::Private,
    ]);
    $upload = Upload::query()->create([
        'file_id' => $file->id,
        'initiated_by_user_id' => $owner->id,
        'upload_status' => UploadStatus::Initiated,
        'storage_key' => 'objects/oversized.txt',
        'content_type' => 'text/plain',
        'size_bytes' => 12,
        'expires_at' => now()->addHour(),
    ]);

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('objectMetadata')
        ->once()
        ->with('objects/oversized.txt')
        ->andReturn(['contentLength' => 48, 'contentType' => 'text/plain', 'etag' => '"oversized"']);
    $storage->shouldReceive('deleteObject')
        ->once()
        ->with('objects/oversized.txt')
        ->andReturnNull();

    $this->actingAs($owner)
        ->postJson("/api/files/{$file->id}/complete-upload")
        ->assertUnprocessable()
        ->assertJson(['message' => 'Uploaded object did not match the expected upload policy.']);

    expect($upload->fresh()->upload_status)->toBe(UploadStatus::Failed)
        ->and($file->fresh()->status)->toBe(FileStatus::Failed)
        ->and($file->fresh()->current_version_id)->toBeNull()
        ->and(FileVersion::query()->where('file_id', $file->id)->exists())->toBeFalse();
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

it('creates a folder share with a public browser that exposes only folder contents', function (): void {
    $owner = User::factory()->create();
    $folder = Folder::query()->create([
        'name' => 'Client pack',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'visibility' => ResourceVisibility::Private,
    ]);
    $child = Folder::query()->create([
        'parent_folder_id' => $folder->id,
        'name' => 'Contracts',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'visibility' => ResourceVisibility::Private,
    ]);
    $file = DriveFile::query()->create([
        'folder_id' => $folder->id,
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'overview.pdf',
        'display_name' => 'overview.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 512,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);
    $version = FileVersion::query()->create([
        'file_id' => $file->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/overview.pdf',
        'size_bytes' => 512,
        'mime_type' => 'application/pdf',
        'uploaded_by_user_id' => $owner->id,
    ]);
    $file->update(['current_version_id' => $version->id]);
    DriveFile::query()->create([
        'folder_id' => $folder->id,
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'pending.pdf',
        'display_name' => 'pending.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 512,
        'status' => FileStatus::Pending,
        'visibility' => ResourceVisibility::Private,
    ]);

    $response = $this->actingAs($owner)
        ->from('/files')
        ->post("/folders/{$folder->id}/shares", ['expires_days' => 7, 'mode' => 'download'])
        ->assertRedirect('/files')
        ->assertSessionHas('shareUrl');

    $share = ShareLink::query()->sole();
    $shareUrl = $response->baseResponse->getSession()->get('shareUrl');
    $token = basename((string) parse_url((string) $shareUrl, PHP_URL_PATH));

    expect($share->resource_type)->toBe(ShareResourceType::Folder)
        ->and($share->resource_id)->toBe($folder->id)
        ->and($share->token_encrypted)->toBe($token);

    $this->get("/s/{$token}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/Share')
            ->where('available', true)
            ->where('resourceType', 'folder')
            ->where('folder.name', 'Client pack')
            ->where('folders.0.id', $child->id)
            ->where('files.0.id', $file->id)
            ->where('files.0.display_name', 'overview.pdf')
            ->where('folderDownload.file_count', 1)
            ->missing('files.0.owner_user_id')
            ->missing('files.0.current_version_id')
            ->missing('token_hash'),
        );
});

it('allows folder share downloads only for ready files inside the shared folder tree', function (): void {
    $owner = User::factory()->create();
    $folder = Folder::query()->create([
        'name' => 'Shared root',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'visibility' => ResourceVisibility::Private,
    ]);
    $inside = DriveFile::query()->create([
        'folder_id' => $folder->id,
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'inside.txt',
        'display_name' => 'inside.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 12,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);
    $version = FileVersion::query()->create([
        'file_id' => $inside->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/inside.txt',
        'size_bytes' => 12,
        'mime_type' => 'text/plain',
        'uploaded_by_user_id' => $owner->id,
    ]);
    $inside->update(['current_version_id' => $version->id]);
    $outside = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'outside.txt',
        'display_name' => 'outside.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 12,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);
    $outsideVersion = FileVersion::query()->create([
        'file_id' => $outside->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/outside.txt',
        'size_bytes' => 12,
        'mime_type' => 'text/plain',
        'uploaded_by_user_id' => $owner->id,
    ]);
    $outside->update(['current_version_id' => $outsideVersion->id]);
    ShareLink::query()->create([
        'resource_type' => ShareResourceType::Folder,
        'resource_id' => $folder->id,
        'token_hash' => hash('sha256', 'folder-token'),
        'token_encrypted' => 'folder-token',
        'created_by_user_id' => $owner->id,
        'mode' => ShareMode::Download,
        'expires_at' => now()->addDay(),
    ]);

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('isConfigured')
        ->twice()
        ->andReturnTrue();
    $storage->shouldReceive('createDownloadUrl')
        ->once()
        ->with('objects/inside.txt', 'inside.txt')
        ->andReturn('https://storage.example/inside');

    $this->get("/api/public-share/folder-token/files/{$inside->id}/download")
        ->assertRedirect('https://storage.example/inside');

    $this->get("/api/public-share/folder-token/files/{$outside->id}/download")
        ->assertNotFound();
});

it('downloads a folder share as a zip archive', function (): void {
    $owner = User::factory()->create();
    $folder = Folder::query()->create([
        'name' => 'Client pack',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'visibility' => ResourceVisibility::Private,
    ]);
    $child = Folder::query()->create([
        'parent_folder_id' => $folder->id,
        'name' => 'Contracts',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'visibility' => ResourceVisibility::Private,
    ]);
    $file = DriveFile::query()->create([
        'folder_id' => $child->id,
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'agreement.txt',
        'display_name' => 'agreement.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 9,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);
    $version = FileVersion::query()->create([
        'file_id' => $file->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/agreement.txt',
        'size_bytes' => 9,
        'mime_type' => 'text/plain',
        'uploaded_by_user_id' => $owner->id,
    ]);
    $file->update(['current_version_id' => $version->id]);
    ShareLink::query()->create([
        'resource_type' => ShareResourceType::Folder,
        'resource_id' => $folder->id,
        'token_hash' => hash('sha256', 'folder-token'),
        'token_encrypted' => 'folder-token',
        'created_by_user_id' => $owner->id,
        'mode' => ShareMode::Download,
        'expires_at' => now()->addDay(),
    ]);

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('isConfigured')
        ->once()
        ->andReturnTrue();
    $storage->shouldReceive('writeObjectToPath')
        ->once()
        ->with('objects/agreement.txt', Mockery::on(function (string $destination): bool {
            file_put_contents($destination, 'agreement');

            return true;
        }));

    $this->get('/api/public-share/folder-token/download')
        ->assertDownload('Client pack.zip');

    expect(AuditLog::query()->where('action_type', 'folder.downloaded')->exists())->toBeTrue();
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

it('shows active folder shares on the shared links page without token hashes', function (): void {
    $owner = User::factory()->create();
    $folder = Folder::query()->create([
        'name' => 'Folder share',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'visibility' => ResourceVisibility::Private,
    ]);
    ShareLink::query()->create([
        'resource_type' => ShareResourceType::Folder,
        'resource_id' => $folder->id,
        'token_hash' => hash('sha256', 'folder-token'),
        'token_encrypted' => 'folder-token',
        'created_by_user_id' => $owner->id,
        'mode' => ShareMode::Download,
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($owner)
        ->get('/shared')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('shared/Index')
            ->where('shares.data.0.resource_type', 'folder')
            ->where('shares.data.0.status', 'active')
            ->where('shares.data.0.folder.name', 'Folder share')
            ->where('shares.data.0.public_url', route('public-share.show', ['token' => 'folder-token']))
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
    Upload::query()->create([
        'file_id' => $file->id,
        'initiated_by_user_id' => $admin->id,
        'upload_status' => UploadStatus::Cancelled,
        'storage_key' => 'objects/purge-upload.txt',
        'content_type' => 'text/plain',
        'size_bytes' => 12,
        'expires_at' => now()->subDay(),
    ]);
    ShareLink::query()->create([
        'resource_type' => ShareResourceType::File,
        'resource_id' => $file->id,
        'token_hash' => hash('sha256', 'token'),
        'created_by_user_id' => $admin->id,
        'mode' => ShareMode::Download,
        'expires_at' => now()->addDay(),
    ]);

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('isConfigured')
        ->once()
        ->andReturnTrue();
    $storage->shouldReceive('deleteObject')
        ->once()
        ->with('objects/purge.txt')
        ->andReturnNull();
    $storage->shouldReceive('deleteObject')
        ->once()
        ->with('objects/purge-upload.txt')
        ->andReturnNull();

    $this->actingAs($admin)
        ->delete("/deleted/folders/{$folder->id}/hard-delete")
        ->assertRedirect();

    expect(Folder::query()->find($folder->id))->toBeNull()
        ->and(DriveFile::query()->find($file->id))->toBeNull()
        ->and(FileVersion::query()->where('file_id', $file->id)->exists())->toBeFalse()
        ->and(Upload::query()->where('file_id', $file->id)->exists())->toBeFalse()
        ->and(ShareLink::query()->where('resource_id', $file->id)->exists())->toBeFalse();
});

it('hard deletes a single trashed file from storage before removing metadata', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);
    $file = DriveFile::query()->create([
        'owner_user_id' => $admin->id,
        'created_by_user_id' => $admin->id,
        'original_name' => 'purge.pdf',
        'display_name' => 'purge.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 512,
        'status' => FileStatus::Deleted,
        'visibility' => ResourceVisibility::Private,
        'is_deleted' => true,
        'deleted_at' => now(),
    ]);
    FileVersion::query()->create([
        'file_id' => $file->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/purge.pdf',
        'size_bytes' => 512,
        'mime_type' => 'application/pdf',
        'uploaded_by_user_id' => $admin->id,
    ]);
    Upload::query()->create([
        'file_id' => $file->id,
        'initiated_by_user_id' => $admin->id,
        'upload_status' => UploadStatus::Cancelled,
        'storage_key' => 'objects/purge-upload.pdf',
        'content_type' => 'application/pdf',
        'size_bytes' => 512,
        'expires_at' => now()->subDay(),
    ]);

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('isConfigured')
        ->once()
        ->andReturnTrue();
    $storage->shouldReceive('deleteObject')
        ->once()
        ->with('objects/purge.pdf')
        ->andReturnNull();
    $storage->shouldReceive('deleteObject')
        ->once()
        ->with('objects/purge-upload.pdf')
        ->andReturnNull();

    $this->actingAs($admin)
        ->delete("/deleted/files/{$file->id}/hard-delete")
        ->assertRedirect();

    expect(DriveFile::query()->find($file->id))->toBeNull()
        ->and(FileVersion::query()->where('file_id', $file->id)->exists())->toBeFalse()
        ->and(Upload::query()->where('file_id', $file->id)->exists())->toBeFalse();
});

it('prunes expired trash through the retention command and keeps newer trash restorable', function (): void {
    $owner = User::factory()->create();
    $expiredFolder = Folder::query()->create([
        'name' => 'Expired folder',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'visibility' => ResourceVisibility::Private,
        'is_deleted' => true,
        'deleted_at' => now()->subDays(31),
    ]);
    $expiredFile = DriveFile::query()->create([
        'folder_id' => $expiredFolder->id,
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'expired.pdf',
        'display_name' => 'expired.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 512,
        'status' => FileStatus::Deleted,
        'visibility' => ResourceVisibility::Private,
        'is_deleted' => true,
        'deleted_at' => now()->subDays(31),
    ]);
    $newerFile = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'newer.pdf',
        'display_name' => 'newer.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 512,
        'status' => FileStatus::Deleted,
        'visibility' => ResourceVisibility::Private,
        'is_deleted' => true,
        'deleted_at' => now()->subDays(5),
    ]);
    FileVersion::query()->create([
        'file_id' => $expiredFile->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/expired.pdf',
        'size_bytes' => 512,
        'mime_type' => 'application/pdf',
        'uploaded_by_user_id' => $owner->id,
    ]);
    Upload::query()->create([
        'file_id' => $expiredFile->id,
        'initiated_by_user_id' => $owner->id,
        'upload_status' => UploadStatus::Cancelled,
        'storage_key' => 'objects/expired-upload.pdf',
        'content_type' => 'application/pdf',
        'size_bytes' => 512,
        'expires_at' => now()->subDay(),
    ]);
    ShareLink::query()->create([
        'resource_type' => ShareResourceType::File,
        'resource_id' => $expiredFile->id,
        'token_hash' => hash('sha256', 'expired-share'),
        'created_by_user_id' => $owner->id,
        'mode' => ShareMode::Download,
        'expires_at' => now()->addDay(),
    ]);

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('isConfigured')
        ->once()
        ->andReturnTrue();
    $storage->shouldReceive('deleteObject')
        ->once()
        ->with('objects/expired.pdf')
        ->andReturnNull();
    $storage->shouldReceive('deleteObject')
        ->once()
        ->with('objects/expired-upload.pdf')
        ->andReturnNull();

    $this->artisan('drive:prune-trash')
        ->assertSuccessful();

    expect(Folder::query()->find($expiredFolder->id))->toBeNull()
        ->and(DriveFile::query()->find($expiredFile->id))->toBeNull()
        ->and(ShareLink::query()->where('resource_id', $expiredFile->id)->exists())->toBeFalse()
        ->and(DriveFile::query()->find($newerFile->id))->not->toBeNull()
        ->and(AuditLog::query()->where('action_type', 'trash.pruned')->exists())->toBeTrue();
});

it('does not remove expired trash metadata if storage deletion fails', function (): void {
    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'keep.pdf',
        'display_name' => 'keep.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 512,
        'status' => FileStatus::Deleted,
        'visibility' => ResourceVisibility::Private,
        'is_deleted' => true,
        'deleted_at' => now()->subDays(31),
    ]);
    FileVersion::query()->create([
        'file_id' => $file->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/keep.pdf',
        'size_bytes' => 512,
        'mime_type' => 'application/pdf',
        'uploaded_by_user_id' => $owner->id,
    ]);

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('isConfigured')
        ->once()
        ->andReturnTrue();
    $storage->shouldReceive('deleteObject')
        ->once()
        ->with('objects/keep.pdf')
        ->andThrow(new RuntimeException('Storage refused deletion.'));

    $this->artisan('drive:prune-trash')
        ->assertFailed();

    expect(DriveFile::query()->find($file->id))->not->toBeNull()
        ->and(FileVersion::query()->where('file_id', $file->id)->exists())->toBeTrue();
});
