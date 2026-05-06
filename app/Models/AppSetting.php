<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value', 'updated_by_user_id'])]
class AppSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
