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

/** التوظيف */
class Candidate extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'candidates';
    public const MODULE = 'recruit';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'expect' => 'decimal:3',
        'next_date' => 'datetime',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'interviewer');
    }
}
