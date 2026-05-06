<?php

use App\Enums\ResourceVisibility;
use App\Models\DriveFile;
use App\Models\User;
use App\Services\DrivePermissionService;

it('lets admins view and manage any resource', function (): void {
    $service = new DrivePermissionService;
    $admin = new User(['role' => 'admin']);
    $admin->id = 1;
    $file = new DriveFile(['owner_user_id' => 2, 'visibility' => ResourceVisibility::Private]);

    expect($service->canView($admin, $file))->toBeTrue()
        ->and($service->canManage($admin, $file))->toBeTrue();
});

it('lets workspace users view workspace resources but not manage them', function (): void {
    $service = new DrivePermissionService;
    $user = new User(['role' => 'member']);
    $user->id = 1;
    $file = new DriveFile(['owner_user_id' => 2, 'visibility' => ResourceVisibility::Workspace]);

    expect($service->canView($user, $file))->toBeTrue()
        ->and($service->canManage($user, $file))->toBeFalse();
});
