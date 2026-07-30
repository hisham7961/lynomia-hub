<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** التطبيقات */
class Application extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'applications';
    public const MODULE = 'apps';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'auto_store' => 'boolean',
        'last_up' => 'date',
        'next_up' => 'date',
        'published' => 'boolean',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function dev(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'dev_id');
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(\App\Models\VaultSecret::class, 'vault_id');
    }
}
