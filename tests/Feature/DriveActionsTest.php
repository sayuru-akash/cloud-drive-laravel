<?php

use App\Enums\FileStatus;
use App\Enums\ResourceVisibility;
use App\Enums\ShareMode;
use App\Enums\ShareResourceType;
use App\Enums\UploadStatus;
use App\Exceptions\DownloadUnavailableException;
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

it('prepares a collision-safe nested folder tree for direct browser uploads', function (): void {
    $owner = User::factory()->create();
    Folder::query()->create([
        'name' => 'Campaign',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'visibility' => ResourceVisibility::Private,
    ]);

    $response = $this->actingAs($owner)
        ->postJson('/api/folders/upload-tree', [
            'paths' => [
                'Campaign',
                'Campaign/Video',
                'Campaign/Video/Exports',
                'Campaign/Documents',
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('folderCount', 4);

    $folderIds = $response->json('folders');
    $root = Folder::query()->findOrFail($folderIds['Campaign']);
    $video = Folder::query()->findOrFail($folderIds['Campaign/Video']);
    $exports = Folder::query()->findOrFail($folderIds['Campaign/Video/Exports']);
    $documents = Folder::query()->findOrFail($folderIds['Campaign/Documents']);

    expect($root->name)->toBe('Campaign (1)')
        ->and($root->parent_folder_id)->toBeNull()
        ->and($root->owner_user_id)->toBe($owner->id)
        ->and($root->visibility)->toBe(ResourceVisibility::Private)
        ->and($video->parent_folder_id)->toBe($root->id)
        ->and($exports->parent_folder_id)->toBe($video->id)
        ->and($documents->parent_folder_id)->toBe($root->id)
        ->and(AuditLog::query()->where('action_type', 'folder.upload_tree.created')->exists())->toBeTrue();
});

it('rejects folder upload trees outside manageable locations or with unsafe paths', function (): void {
    $member = User::factory()->create();
    $other = User::factory()->create();
    $privateParent = Folder::query()->create([
        'name' => 'Private destination',
        'owner_user_id' => $other->id,
        'created_by_user_id' => $other->id,
        'visibility' => ResourceVisibility::Private,
    ]);

    $this->actingAs($member)
        ->postJson('/api/folders/upload-tree', [
            'parent_folder_id' => $privateParent->id,
            'paths' => ['Selected folder'],
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->postJson('/api/folders/upload-tree', [
            'paths' => ['Selected folder/../Outside'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A selected folder contains an unsupported name.');

    $this->actingAs($member)
        ->postJson('/api/folders/upload-tree', [
            'paths' => [implode('/', array_fill(0, 33, 'Nested'))],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A selected folder path is empty or too deeply nested.');

    expect(Folder::query()->where('owner_user_id', $member->id)->exists())->toBeFalse();
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

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('deleteObject')
        ->once()
        ->with('objects/stuck.bin')
        ->andReturnNull();

    $this->actingAs($owner)
        ->postJson("/api/files/{$file->id}/cancel-upload")
        ->assertOk();

    expect($upload->fresh()->upload_status)->toBe(UploadStatus::Cancelled)
        ->and($file->fresh()->status)->toBe(FileStatus::Failed)
        ->and($file->fresh()->is_deleted)->toBeTrue();
});

it('does not cancel a file when no active upload exists', function (): void {
    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'ready.pdf',
        'display_name' => 'ready.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 512,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);

    $this->actingAs($owner)
        ->postJson("/api/files/{$file->id}/cancel-upload")
        ->assertConflict()
        ->assertJson(['message' => 'There is no active upload to cancel.']);

    expect($file->fresh()->status)->toBe(FileStatus::Ready)
        ->and($file->fresh()->is_deleted)->toBeFalse();
});

it('marks multipart uploads as uploading while continuing to sign later parts', function (): void {
    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'large-video.mp4',
        'display_name' => 'large-video.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 120 * 1024 * 1024,
        'status' => FileStatus::Pending,
        'visibility' => ResourceVisibility::Private,
    ]);
    $upload = Upload::query()->create([
        'file_id' => $file->id,
        'initiated_by_user_id' => $owner->id,
        'upload_status' => UploadStatus::Initiated,
        'storage_key' => 'objects/large-video.mp4',
        'provider_upload_id' => 'provider-upload-id',
        'content_type' => 'video/mp4',
        'size_bytes' => 120 * 1024 * 1024,
        'expires_at' => now()->addHour(),
    ]);

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('createMultipartPartUploadUrl')
        ->once()
        ->with('objects/large-video.mp4', 'provider-upload-id', 1)
        ->andReturn('https://storage.example/part-1');
    $storage->shouldReceive('createMultipartPartUploadUrl')
        ->once()
        ->with('objects/large-video.mp4', 'provider-upload-id', 2)
        ->andReturn('https://storage.example/part-2');

    $this->actingAs($owner)
        ->postJson("/api/files/{$file->id}/multipart-part", ['partNumber' => 1])
        ->assertOk()
        ->assertJson(['uploadUrl' => 'https://storage.example/part-1']);

    expect($upload->fresh()->upload_status)->toBe(UploadStatus::Uploading);

    $this->actingAs($owner)
        ->postJson("/api/files/{$file->id}/multipart-part", ['partNumber' => 2])
        ->assertOk()
        ->assertJson(['uploadUrl' => 'https://storage.example/part-2']);
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
        'upload_status' => UploadStatus::Uploading,
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

it('normalizes and validates multipart parts before completion', function (): void {
    config(['drive.multipart_chunk_size_bytes' => 32]);

    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'multipart.mp4',
        'display_name' => 'multipart.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 80,
        'status' => FileStatus::Pending,
        'visibility' => ResourceVisibility::Private,
    ]);
    Upload::query()->create([
        'file_id' => $file->id,
        'initiated_by_user_id' => $owner->id,
        'upload_status' => UploadStatus::Uploading,
        'storage_key' => 'objects/multipart.mp4',
        'provider_upload_id' => 'provider-upload-id',
        'content_type' => 'video/mp4',
        'size_bytes' => 80,
        'expires_at' => now()->addHour(),
    ]);

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('completeMultipartUpload')
        ->once()
        ->with('objects/multipart.mp4', 'provider-upload-id', [
            ['partNumber' => 1, 'etag' => 'etag-1'],
            ['partNumber' => 2, 'etag' => 'etag-2'],
            ['partNumber' => 3, 'etag' => 'etag-3'],
        ]);
    $storage->shouldReceive('objectMetadata')
        ->once()
        ->with('objects/multipart.mp4')
        ->andReturn(['contentLength' => 80, 'contentType' => 'video/mp4', 'etag' => 'etag-complete']);
    $storage->shouldReceive('bucket')->once()->andReturn('test-bucket');

    $this->actingAs($owner)
        ->postJson("/api/files/{$file->id}/complete-upload", [
            'parts' => [
                ['partNumber' => 3, 'etag' => 'etag-3'],
                ['partNumber' => 1, 'etag' => 'etag-1'],
                ['partNumber' => 2, 'etag' => 'etag-2'],
            ],
        ])
        ->assertOk();
});

it('treats repeated completion of a ready file as successful', function (): void {
    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'already-ready.mp4',
        'display_name' => 'already-ready.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 80,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);
    $version = FileVersion::query()->create([
        'file_id' => $file->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/already-ready.mp4',
        'size_bytes' => 80,
        'mime_type' => 'video/mp4',
        'uploaded_by_user_id' => $owner->id,
        'created_at' => now(),
    ]);
    $file->update(['current_version_id' => $version->id]);

    $this->actingAs($owner)
        ->postJson("/api/files/{$file->id}/complete-upload", [
            'parts' => [['partNumber' => 1, 'etag' => 'etag-1']],
        ])
        ->assertOk();
});

it('does not create a duplicate version when another request finishes first', function (): void {
    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'racing.mp4',
        'display_name' => 'racing.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 80,
        'status' => FileStatus::Pending,
        'visibility' => ResourceVisibility::Private,
    ]);
    $upload = Upload::query()->create([
        'file_id' => $file->id,
        'initiated_by_user_id' => $owner->id,
        'upload_status' => UploadStatus::Uploading,
        'storage_key' => 'objects/racing.mp4',
        'content_type' => 'video/mp4',
        'size_bytes' => 80,
        'expires_at' => now()->addHour(),
    ]);

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('objectMetadata')
        ->once()
        ->with('objects/racing.mp4')
        ->andReturnUsing(function () use ($file, $owner, $upload): array {
            $version = FileVersion::query()->create([
                'file_id' => $file->id,
                'version_number' => 1,
                'storage_bucket' => 'test-bucket',
                'storage_key' => 'objects/racing.mp4',
                'size_bytes' => 80,
                'mime_type' => 'video/mp4',
                'uploaded_by_user_id' => $owner->id,
                'created_at' => now(),
            ]);
            $file->update([
                'status' => FileStatus::Ready,
                'current_version_id' => $version->id,
            ]);
            $upload->update([
                'upload_status' => UploadStatus::Completed,
                'completed_at' => now(),
            ]);

            return ['contentLength' => 80, 'contentType' => 'video/mp4', 'etag' => 'etag-complete'];
        });
    $storage->shouldNotReceive('bucket');

    $this->actingAs($owner)
        ->postJson("/api/files/{$file->id}/complete-upload")
        ->assertOk();

    expect(FileVersion::query()->where('file_id', $file->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action_type', 'file.upload.completed')->count())->toBe(0);
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
    $storage->shouldReceive('ensureDownloadAvailable')
        ->once()
        ->with('objects/inside.txt');

    $this->get("/api/public-share/folder-token/files/{$inside->id}/download")
        ->assertRedirect('https://storage.example/inside');

    $this->get("/api/public-share/folder-token/files/{$outside->id}/download")
        ->assertNotFound();
});

it('previews a shared video through an active public token without exposing storage metadata', function (): void {
    $owner = User::factory()->create();
    $video = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'shared-video.mp4',
        'display_name' => 'shared-video.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 20_000_000,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);
    $version = FileVersion::query()->create([
        'file_id' => $video->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/shared-video.mp4',
        'size_bytes' => 20_000_000,
        'mime_type' => 'video/mp4',
        'uploaded_by_user_id' => $owner->id,
    ]);
    $video->update(['current_version_id' => $version->id]);
    ShareLink::query()->create([
        'resource_type' => ShareResourceType::File,
        'resource_id' => $video->id,
        'token_hash' => hash('sha256', 'video-token'),
        'token_encrypted' => 'video-token',
        'created_by_user_id' => $owner->id,
        'mode' => ShareMode::Download,
        'expires_at' => now()->addDay(),
    ]);

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('isConfigured')->once()->andReturnTrue();
    $storage->shouldReceive('ensureDownloadAvailable')->once()->with('objects/shared-video.mp4');
    $storage->shouldReceive('createPreviewUrl')
        ->once()
        ->with('objects/shared-video.mp4', 'shared-video.mp4')
        ->andReturn('https://storage.example/shared-video');

    $this->getJson('/api/public-share/video-token/preview')
        ->assertSuccessful()
        ->assertExactJson([
            'url' => 'https://storage.example/shared-video',
            'expiresIn' => 3600,
        ]);

    expect(AuditLog::query()->where('action_type', 'file.preview.opened')->exists())->toBeTrue();
});

it('returns folder share visitors to the file list when the storage download cap is exceeded', function (): void {
    $owner = User::factory()->create();
    $folder = Folder::query()->create([
        'name' => 'Shared root',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'visibility' => ResourceVisibility::Private,
    ]);
    $file = DriveFile::query()->create([
        'folder_id' => $folder->id,
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'large-video.mp4',
        'display_name' => 'large-video.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 250_000_000,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);
    $version = FileVersion::query()->create([
        'file_id' => $file->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/large-video.mp4',
        'size_bytes' => 250_000_000,
        'mime_type' => 'video/mp4',
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
    $storage->shouldReceive('isConfigured')->once()->andReturnTrue();
    $storage->shouldReceive('ensureDownloadAvailable')
        ->once()
        ->with('objects/large-video.mp4')
        ->andThrow(DownloadUnavailableException::capacityExceeded(new RuntimeException('Provider cap exceeded.')));
    $storage->shouldNotReceive('createDownloadUrl');

    $message = 'Downloads are temporarily unavailable because the storage download limit has been reached. Please try again later or contact the link owner.';

    $this->get("/api/public-share/folder-token/files/{$file->id}/download")
        ->assertRedirect("/s/folder-token?folder={$folder->id}")
        ->assertSessionHas('downloadError', $message);

    $this->get("/s/folder-token?folder={$folder->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/Share')
            ->where('downloadError', $message)
        );

    expect(AuditLog::query()->where('action_type', 'file.downloaded')->exists())->toBeFalse();
});

it('returns authenticated users to their folder when storage cannot serve a download', function (): void {
    $owner = User::factory()->create();
    $folder = Folder::query()->create([
        'name' => 'Videos',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'visibility' => ResourceVisibility::Private,
    ]);
    $file = DriveFile::query()->create([
        'folder_id' => $folder->id,
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'video.mp4',
        'display_name' => 'video.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 250_000_000,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);
    $version = FileVersion::query()->create([
        'file_id' => $file->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/video.mp4',
        'size_bytes' => 250_000_000,
        'mime_type' => 'video/mp4',
        'uploaded_by_user_id' => $owner->id,
    ]);
    $file->update(['current_version_id' => $version->id]);

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('isConfigured')->once()->andReturnTrue();
    $storage->shouldReceive('ensureDownloadAvailable')
        ->once()
        ->with('objects/video.mp4')
        ->andThrow(DownloadUnavailableException::temporary(new RuntimeException('Storage unavailable.')));
    $storage->shouldNotReceive('createDownloadUrl');

    $this->actingAs($owner)
        ->get("/api/files/{$file->id}/download")
        ->assertRedirect("/files?folder={$folder->id}")
        ->assertSessionHas('error', 'The storage service could not prepare this download. Please try again shortly.');

    expect(AuditLog::query()->where('action_type', 'file.downloaded')->exists())->toBeFalse();
});

it('returns an authorized short-lived preview URL only for ready video files', function (): void {
    $owner = User::factory()->create();
    $video = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'preview.mp4',
        'display_name' => 'preview.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 50_000_000,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);
    $version = FileVersion::query()->create([
        'file_id' => $video->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/preview.mp4',
        'size_bytes' => 50_000_000,
        'mime_type' => 'video/mp4',
        'uploaded_by_user_id' => $owner->id,
    ]);
    $video->update(['current_version_id' => $version->id]);

    $storage = $this->mock(ObjectStorageService::class);
    $storage->shouldReceive('isConfigured')->once()->andReturnTrue();
    $storage->shouldReceive('ensureDownloadAvailable')->once()->with('objects/preview.mp4');
    $storage->shouldReceive('createPreviewUrl')
        ->once()
        ->with('objects/preview.mp4', 'preview.mp4')
        ->andReturn('https://storage.example/preview');

    $this->actingAs($owner)
        ->getJson("/api/files/{$video->id}/preview")
        ->assertSuccessful()
        ->assertJson([
            'url' => 'https://storage.example/preview',
            'expiresIn' => 3600,
        ]);

    expect(AuditLog::query()->where('action_type', 'file.preview.opened')->exists())->toBeTrue();
});

it('rejects private or non-video preview requests before signing storage access', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'document.pdf',
        'display_name' => 'document.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 500,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);

    $this->actingAs($other)
        ->getJson("/api/files/{$file->id}/preview")
        ->assertForbidden();

    $this->actingAs($owner)
        ->getJson("/api/files/{$file->id}/preview")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only video files can be previewed.');
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
