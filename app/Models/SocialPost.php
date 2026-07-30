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

/** منشورات ومشاهدات */
class SocialPost extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'social_posts';
    public const MODULE = 'posts';
    public const DISPLAY = 'title';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'pub_at' => 'datetime',
        'views' => 'decimal:3',
        'reach' => 'decimal:3',
        'impr' => 'decimal:3',
        'likes' => 'decimal:3',
        'comments2' => 'decimal:3',
        'shares' => 'decimal:3',
        'saves' => 'decimal:3',
        'clicks' => 'decimal:3',
        'spend' => 'decimal:3',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function social(): BelongsTo
    {
        return $this->belongsTo(\App\Models\SocialAccount::class, 'social_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'author_id');
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(\App\Models\DesignTask::class, 'design_id');
    }
}
