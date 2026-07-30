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

/** الملكية الفكرية (Intellectual Property) */
class IpAsset extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'ip_assets';
    public const MODULE = 'ip';
    public const DISPLAY = 'title';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'reg_date' => 'date',
        'expiry' => 'date',
        'auto_renew' => 'boolean',
        'cost' => 'decimal:3',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'author_id');
    }
}
