<?php

namespace Tests\Feature;

use App\Enums\FileStatus;
use App\Enums\ResourceVisibility;
use App\Enums\UploadStatus;
use App\Models\DriveFile;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_member_dashboard_counts_owned_and_workspace_visible_files(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();

        DriveFile::query()->create([
            'owner_user_id' => $member->id,
            'created_by_user_id' => $member->id,
            'original_name' => 'owned.pdf',
            'display_name' => 'owned.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
            'status' => FileStatus::Ready,
            'visibility' => ResourceVisibility::Private,
        ]);
        DriveFile::query()->create([
            'owner_user_id' => $other->id,
            'created_by_user_id' => $other->id,
            'original_name' => 'workspace.pdf',
            'display_name' => 'workspace.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
            'status' => FileStatus::Ready,
            'visibility' => ResourceVisibility::Workspace,
        ]);
        DriveFile::query()->create([
            'owner_user_id' => $other->id,
            'created_by_user_id' => $other->id,
            'original_name' => 'private.pdf',
            'display_name' => 'private.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
            'status' => FileStatus::Ready,
            'visibility' => ResourceVisibility::Private,
        ]);

        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.files', 2)
                ->has('recentFiles', 2)
            );
    }

    public function test_dashboard_expires_stale_uploads_and_hides_them_from_pending_activity(): void
    {
        $user = User::factory()->create();
        $file = DriveFile::query()->create([
            'owner_user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'original_name' => 'stale.pdf',
            'display_name' => 'stale.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'status' => FileStatus::Pending,
            'visibility' => ResourceVisibility::Private,
        ]);
        $staleUpload = Upload::query()->create([
            'file_id' => $file->id,
            'initiated_by_user_id' => $user->id,
            'upload_status' => UploadStatus::Initiated,
            'storage_key' => 'uploads/stale.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 1024,
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.pending', 0)
                ->has('recentUploads', 0)
            );

        $this->assertSame(UploadStatus::Failed, $staleUpload->fresh()->upload_status);
        $this->assertSame(FileStatus::Failed, $file->fresh()->status);
        $this->assertTrue($file->fresh()->is_deleted);
    }

    public function test_dashboard_shows_latest_five_upload_activity_records(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 6) as $index) {
            $file = DriveFile::query()->create([
                'owner_user_id' => $user->id,
                'created_by_user_id' => $user->id,
                'original_name' => "upload-{$index}.pdf",
                'display_name' => "upload-{$index}.pdf",
                'extension' => 'pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => $index * 100,
                'status' => FileStatus::Ready,
                'visibility' => ResourceVisibility::Private,
                'created_at' => now()->subMinutes(10 - $index),
                'updated_at' => now()->subMinutes(10 - $index),
            ]);
            Upload::query()->create([
                'file_id' => $file->id,
                'initiated_by_user_id' => $user->id,
                'upload_status' => UploadStatus::Completed,
                'storage_key' => "uploads/upload-{$index}.pdf",
                'content_type' => 'application/pdf',
                'size_bytes' => $index * 100,
                'expires_at' => now()->addDay(),
                'completed_at' => now()->subMinutes(10 - $index),
                'created_at' => now()->subMinutes(10 - $index),
                'updated_at' => now()->subMinutes(10 - $index),
            ]);
        }

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recentUploads', 5)
                ->where('recentUploads.0.display_name', 'upload-6.pdf')
                ->where('recentUploads.4.display_name', 'upload-2.pdf')
            );
    }

    public function test_unverified_users_are_redirected_to_the_email_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }
}
