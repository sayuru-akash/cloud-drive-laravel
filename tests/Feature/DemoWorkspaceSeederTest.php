<?php

use App\Models\AuditLog;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\User;
use Database\Seeders\DemoWorkspaceSeeder;

it('requires an existing active super admin', function (): void {
    expect(fn () => $this->seed(DemoWorkspaceSeeder::class))
        ->toThrow(RuntimeException::class, 'Create an active super admin account before running the demo workspace seeder.');
});

it('seeds demo workspace data for the existing super admin without creating credentials', function (): void {
    $superAdmin = User::factory()->create([
        'email' => 'owner@example.com',
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    $this->seed(DemoWorkspaceSeeder::class);

    expect(User::query()->count())->toBe(1)
        ->and(Folder::query()->where('owner_user_id', $superAdmin->id)->count())->toBe(3)
        ->and(DriveFile::query()->where('owner_user_id', $superAdmin->id)->count())->toBe(4)
        ->and(AuditLog::query()->where('actor_user_id', $superAdmin->id)->count())->toBe(4);
});
