<?php

namespace App\Support;

use Illuminate\Support\Str;

final class PrefixedIds
{
    public static function make(string $prefix): string
    {
        return $prefix.'_'.strtolower((string) Str::ulid());
    }
}
