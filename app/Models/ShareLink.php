<?php

namespace App\Models;

use App\Enums\ShareMode;
use App\Enums\ShareResourceType;
use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['resource_type', 'resource_id', 'token_hash', 'token_encrypted', 'created_by_user_id', 'mode', 'password_hash', 'expires_at', 'is_revoked'])]
class ShareLink extends Model
{
    use HasPrefixedId;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function idPrefix(): string
    {
        return 'share';
    }

    protected function casts(): array
    {
        return [
            'resource_type' => ShareResourceType::class,
            'mode' => ShareMode::class,
            'token_encrypted' => 'encrypted',
            'expires_at' => 'datetime',
            'is_revoked' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(DriveFile::class, 'resource_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'resource_id');
    }
}
