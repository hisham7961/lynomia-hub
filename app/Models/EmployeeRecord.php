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

/** سجلات الموظفين */
class EmployeeRecord extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'employee_records';
    public const MODULE = 'hrlog';
    public const DISPLAY = 'title';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'date' => 'date',
        'expiry' => 'date',
        'cost' => 'decimal:3',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function emp(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'emp_id');
    }

    public function by(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'by_id');
    }
}
