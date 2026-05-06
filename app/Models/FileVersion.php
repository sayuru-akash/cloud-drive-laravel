<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['file_id', 'version_number', 'storage_bucket', 'storage_key', 'size_bytes', 'mime_type', 'checksum', 'uploaded_by_user_id'])]
class FileVersion extends Model
{
    use HasPrefixedId;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function idPrefix(): string
    {
        return 'version';
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(DriveFile::class, 'file_id');
    }
}
