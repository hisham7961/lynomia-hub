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

/** النتائج الرئيسية (KR) — ما يجعل الهدف قابلاً للقياس */
class KeyResult extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'key_results';
    public const MODULE = 'krs';
    public const DISPLAY = 'title';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'start_value' => 'decimal:3',
        'target_value' => 'decimal:3',
        'current_value' => 'decimal:3',
        'weight' => 'decimal:3',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class, 'objective_id');
    }

    /** نسبة الإنجاز: من البداية إلى الهدف — تقبل الاتجاه النازل (الهدف أقل من البداية) */
    public function pct(): ?int
    {
        $target = $this->target_value === null ? null : (float) $this->target_value;
        $current = $this->current_value === null ? null : (float) $this->current_value;
        if ($target === null || $current === null) return null;

        $start = (float) ($this->start_value ?? 0);
        $span = $target - $start;
        if (abs($span) < 0.000001) return $current >= $target ? 100 : 0;

        return max(0, min(100, (int) round(($current - $start) / $span * 100)));
    }
}
