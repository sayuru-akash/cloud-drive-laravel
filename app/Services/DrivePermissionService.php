<?php

namespace App\Services;

use App\Enums\ResourceVisibility;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\User;

class DrivePermissionService
{
    public function canView(User $user, DriveFile|Folder $resource): bool
    {
        return $this->isAdmin($user)
            || (int) $resource->owner_user_id === (int) $user->id
            || $resource->visibility === ResourceVisibility::Workspace;
    }

    public function canManage(User $user, DriveFile|Folder $resource): bool
    {
        return $this->isAdmin($user) || (int) $resource->owner_user_id === (int) $user->id;
    }

    public function isAdmin(User $user): bool
    {
        return $user->isAdmin();
    }
}
