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

/** الإجازات والطلبات */
class LeaveRequest extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'leave_requests';
    public const MODULE = 'leaves';
    public const DISPLAY = 'type';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'days' => 'decimal:3',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function emp(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'emp_id');
    }

    public function mgr(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'mgr_id');
    }
}
