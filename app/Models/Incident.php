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

/** الحوادث التقنية (Incident Management) — انقطاع أو تدهور فعلي في الإنتاج */
class Incident extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'incidents';
    public const MODULE = 'incidents';
    public const DISPLAY = 'title';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
        'review_date' => 'date',
        'postmortem' => 'boolean',
        'parts' => 'array',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Application::class, 'app_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Server::class, 'server_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'lead_id');
    }
}
