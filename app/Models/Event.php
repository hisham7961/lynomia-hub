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

/** الأحداث والمعارض (Events & Exhibitions) */
class Event extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'events';
    public const MODULE = 'events';
    public const DISPLAY = 'title';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'date_start' => 'date',
        'date_end' => 'date',
        'team' => 'array',
        'budget' => 'decimal:3',
        'actual_cost' => 'decimal:3',
        'roi' => 'decimal:3',
        'leads' => 'integer',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Brand::class, 'brand_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_id');
    }
}
