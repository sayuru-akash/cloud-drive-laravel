<?php

namespace App\Models;

use App\Enums\FileStatus;
use App\Enums\ResourceVisibility;
use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['folder_id', 'owner_user_id', 'created_by_user_id', 'original_name', 'display_name', 'extension', 'mime_type', 'size_bytes', 'checksum', 'status', 'visibility', 'is_deleted', 'deleted_at', 'current_version_id'])]
class DriveFile extends Model
{
    use HasPrefixedId;

    protected $table = 'files';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function idPrefix(): string
    {
        return 'file';
    }

    protected function casts(): array
    {
        return [
            'status' => FileStatus::class,
            'visibility' => ResourceVisibility::class,
            'is_deleted' => 'boolean',
            'deleted_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FileVersion::class, 'file_id');
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class, 'file_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FileVersion::class, 'current_version_id');
    }
}
