<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['actor_user_id', 'actor_email', 'action_type', 'resource_type', 'resource_id', 'ip_address', 'user_agent', 'metadata_json'])]
class AuditLog extends Model
{
    use HasPrefixedId;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function idPrefix(): string
    {
        return 'audit';
    }

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
