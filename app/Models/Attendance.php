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

/** الحضور والانصراف */
class Attendance extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'attendance';
    public const MODULE = 'attend';
    public const DISPLAY = 'date';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'date' => 'date',
        'hours' => 'decimal:3',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function emp(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'emp_id');
    }
}
