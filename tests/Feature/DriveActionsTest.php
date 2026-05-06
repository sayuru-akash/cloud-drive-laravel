<?php

use App\Enums\FileStatus;
use App\Enums\ResourceVisibility;
use App\Enums\ShareMode;
use App\Enums\ShareResourceType;
use App\Models\DriveFile;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\ObjectStorageService;

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

    $this->actingAs($owner)
        ->patch("/deleted/folders/{$folder->id}/restore")
        ->assertRedirect();

    expect($folder->fresh()->is_deleted)->toBeFalse()
        ->and($child->fresh()->is_deleted)->toBeFalse()
        ->and($file->fresh()->is_deleted)->toBeFalse()
        ->and($file->fresh()->status)->toBe(FileStatus::Ready);
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
