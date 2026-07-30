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

/** المصروفات المتكررة */
class RecurringDoc extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'recurring_docs';
    public const MODULE = 'recur';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'amount' => 'decimal:3',
        'next' => 'date',
        'auto_post' => 'boolean',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function cc(): BelongsTo
    {
        return $this->belongsTo(\App\Models\CostCenter::class, 'cc_id');
    }
}
