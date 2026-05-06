<?php

namespace App\Models;

use App\Enums\UploadStatus;
use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['file_id', 'initiated_by_user_id', 'upload_status', 'storage_key', 'provider_upload_id', 'content_type', 'size_bytes', 'expires_at', 'completed_at'])]
class Upload extends Model
{
    use HasPrefixedId;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function idPrefix(): string
    {
        return 'upload';
    }

    protected function casts(): array
    {
        return [
            'upload_status' => UploadStatus::class,
            'size_bytes' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(DriveFile::class, 'file_id');
    }
}
