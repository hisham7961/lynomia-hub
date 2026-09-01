<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** دفعةٌ في جدول مدفوعات العرض — نسبةٌ أو مبلغٌ عند محفّزٍ (قبول/مرحلة/تسليم). */
class QuoteMilestone extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'quote_milestones';
    protected $guarded = ['id'];

    protected $casts = [
        'pct' => 'decimal:3',
        'amount' => 'decimal:3',
        'meta' => 'array',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }
}
