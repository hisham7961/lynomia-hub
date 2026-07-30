<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    /** يُستدعى تلقائياً لكل نسخة موديل يستخدم هذا الـtrait */
    public function initializeHasUuid(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    protected static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            $model->{$model->getKeyName()} = $model->{$model->getKeyName()} ?: (string) Str::uuid();
        });
    }
}
