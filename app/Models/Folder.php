<?php

namespace App\Models;

use App\Enums\ResourceVisibility;
use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['parent_folder_id', 'name', 'owner_user_id', 'created_by_user_id', 'visibility', 'is_deleted', 'deleted_at'])]
class Folder extends Model
{
    use HasPrefixedId;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function idPrefix(): string
    {
        return 'folder';
    }

    protected function casts(): array
    {
        return [
            'visibility' => ResourceVisibility::class,
            'is_deleted' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_folder_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_folder_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(DriveFile::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
