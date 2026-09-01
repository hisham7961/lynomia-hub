<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * جلسة تتبّع المسار — نافذةٌ صريحةٌ مصرَّحٌ بها: لا تتبّع خارجها.
 */
class TrackSession extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions;

    protected $table = 'track_sessions';
    public const MODULE = 'tracks';
    public const DISPLAY = 'id';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'field_day' => 'date',
        'consent_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'simplified' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'emp_id');
    }

    public function points(): HasMany
    {
        return $this->hasMany(TrackPoint::class, 'session_id')->orderBy('captured_at');
    }

    public function active(): bool
    {
        return $this->status === 'نشطة';
    }
}
