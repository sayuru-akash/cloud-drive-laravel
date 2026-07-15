<?php

use App\Enums\FileStatus;
use App\Enums\ResourceVisibility;
use App\Enums\UploadStatus;
use App\Models\DriveFile;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function createUsageFile(User $owner, int $size, bool $deleted = false, string $name = 'file.pdf'): DriveFile
{
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => $name,
        'display_name' => $name,
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => $size,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
        'is_deleted' => $deleted,
        'deleted_at' => $deleted ? now() : null,
    ]);
    $version = FileVersion::query()->create([
        'file_id' => $file->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => "usage/{$file->id}",
        'size_bytes' => $size,
        'mime_type' => 'application/pdf',
        'uploaded_by_user_id' => $owner->id,
    ]);
    $file->update(['current_version_id' => $version->id]);

    return $file;
}

it('shows members only their own storage usage', function (): void {
    $member = User::factory()->create();
    $otherMember = User::factory()->create();
    createUsageFile($member, 120, name: 'member.pdf');
    createUsageFile($member, 30, deleted: true, name: 'trash.pdf');
    createUsageFile($otherMember, 900, name: 'private-other.pdf');
    Folder::query()->create([
        'name' => 'Member folder',
        'owner_user_id' => $member->id,
        'created_by_user_id' => $member->id,
        'visibility' => ResourceVisibility::Private,
        'is_deleted' => false,
    ]);
    Upload::query()->create([
        'file_id' => DriveFile::query()->where('owner_user_id', $member->id)->firstOrFail()->id,
        'initiated_by_user_id' => $member->id,
        'upload_status' => UploadStatus::Uploading,
        'storage_key' => 'usage/pending',
        'content_type' => 'application/pdf',
        'size_bytes' => 45,
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($member)
        ->get(route('usage.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Usage')
            ->where('usage.scope', 'personal')
            ->where('usage.storedBytes', 150)
            ->where('usage.activeBytes', 120)
            ->where('usage.trashBytes', 30)
            ->where('usage.activeFiles', 1)
            ->where('usage.activeFolders', 1)
            ->where('usage.trashItems', 1)
            ->where('usage.activeUploadBytes', 45)
            ->has('usage.largestFiles', 1)
            ->where('usage.largestFiles.0.display_name', 'member.pdf')
            ->where('policy.maxUploadSizeBytes', 10 * 1024 * 1024 * 1024));
});

it('shows administrators workspace-wide storage usage', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);
    $member = User::factory()->create();
    createUsageFile($admin, 100, name: 'admin.pdf');
    createUsageFile($member, 250, name: 'member.pdf');
    createUsageFile($member, 50, deleted: true, name: 'member-trash.pdf');

    $this->actingAs($admin)
        ->get(route('usage.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('usage.scope', 'workspace')
            ->where('usage.storedBytes', 400)
            ->where('usage.activeBytes', 350)
            ->where('usage.trashBytes', 50)
            ->where('usage.activeFiles', 2)
            ->where('usage.trashItems', 1)
            ->has('usage.largestFiles', 2));
});

it('requires a verified authenticated user to view usage', function (): void {
    $this->get(route('usage.index'))->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();

    $this->actingAs($unverified)
        ->get(route('usage.index'))
        ->assertRedirect(route('verification.notice'));
});
