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

/** سجل النشر والإصدارات (Deployments) */
class Deployment extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'deployments';
    public const MODULE = 'deploys';
    public const DISPLAY = 'ver';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'deployed_at' => 'datetime',
        'rollbackable' => 'boolean',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Application::class, 'app_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Change::class, 'change_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Incident::class, 'incident_id');
    }
}
