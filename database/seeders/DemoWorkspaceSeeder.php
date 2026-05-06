<?php

namespace Database\Seeders;

use App\Enums\FileStatus;
use App\Enums\ResourceVisibility;
use App\Models\AuditLog;
use App\Models\DriveFile;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Sayuru Admin',
                'password' => Hash::make('password'),
                'role' => 'super_admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        foreach ([
            ['Product Assets', ResourceVisibility::Private],
            ['Finance', ResourceVisibility::Private],
            ['Policies', ResourceVisibility::Workspace],
        ] as [$name, $visibility]) {
            Folder::query()->updateOrCreate(
                ['name' => $name, 'parent_folder_id' => null],
                [
                    'owner_user_id' => $user->id,
                    'created_by_user_id' => $user->id,
                    'visibility' => $visibility,
                    'is_deleted' => false,
                ],
            );
        }

        foreach ([
            ['Q2 board deck.pdf', 'application/pdf', 2840000, ResourceVisibility::Workspace],
            ['brand-kit.zip', 'application/zip', 18400000, ResourceVisibility::Private],
            ['team-photo.jpg', 'image/jpeg', 940000, ResourceVisibility::Workspace],
            ['student-records.csv', 'text/csv', 128000, ResourceVisibility::Private],
        ] as [$name, $mimeType, $sizeBytes, $visibility]) {
            $file = DriveFile::query()->updateOrCreate(
                ['display_name' => $name, 'folder_id' => null],
                [
                    'owner_user_id' => $user->id,
                    'created_by_user_id' => $user->id,
                    'original_name' => $name,
                    'extension' => pathinfo($name, PATHINFO_EXTENSION),
                    'mime_type' => $mimeType,
                    'size_bytes' => $sizeBytes,
                    'status' => FileStatus::Ready,
                    'visibility' => $visibility,
                    'is_deleted' => false,
                ],
            );

            $version = FileVersion::query()->updateOrCreate(
                ['file_id' => $file->id, 'version_number' => 1],
                [
                    'storage_bucket' => 'demo-b2-private',
                    'storage_key' => "demo/{$file->id}",
                    'size_bytes' => $sizeBytes,
                    'mime_type' => $mimeType,
                    'uploaded_by_user_id' => $user->id,
                ],
            );

            $file->update(['current_version_id' => $version->id]);
        }

        foreach (['file.upload.completed', 'share.created', 'folder.created', 'settings.updated'] as $action) {
            AuditLog::query()->create([
                'actor_user_id' => $user->id,
                'actor_email' => $user->email,
                'action_type' => $action,
                'resource_type' => 'demo',
                'resource_id' => 'seed',
                'metadata_json' => ['source' => 'demo-workspace'],
            ]);
        }
    }
}
