<?php

namespace App\Models\Concerns;

use App\Support\PrefixedIds;

trait HasPrefixedId
{
    public static function bootHasPrefixedId(): void
    {
        static::creating(function ($model): void {
            if (! $model->getKey()) {
                $model->{$model->getKeyName()} = PrefixedIds::make($model->idPrefix());
            }
        });
    }

    abstract protected function idPrefix(): string;
}
