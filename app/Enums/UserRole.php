<?php

namespace App\Enums;

enum UserRole: string
{
    case Member = 'member';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<int, string> */
    public static function adminValues(): array
    {
        return [
            self::Admin->value,
            self::SuperAdmin->value,
        ];
    }

    public static function isAdminValue(?string $role): bool
    {
        return in_array($role, self::adminValues(), true);
    }
}
