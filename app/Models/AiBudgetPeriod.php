<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * **عدّادُ فترةٍ واحدة** (المرحلة ٤ · P4-W4) — ومِرساةُ التزامنِ كلِّها.
 *
 * **ولا `Auditable` هنا عمداً:** هذا عدّادٌ تُحرّكه آلافُ العمليّات، لا قرارَ
 * إنسانٍ يُراجَع. وأثرُ تدقيقٍ لكلِّ حجزٍ يُغرِق السجلَّ الذي بُني ليُقرَأ.
 * **وسجلُّ ما جرى في `ai_usage_events`** صفّاً لكلِّ محاولة — وهو أدقُّ من أيِّ
 * أثرٍ على عدّاد.
 */
class AiBudgetPeriod extends Model
{
    use HasUuid;

    protected $table = 'ai_budget_periods';
    public const MODULE = 'ai_budget_periods';

    protected $guarded = ['id'];

    protected $casts = [
        'reserved_micro'      => 'integer',
        'spent_micro'         => 'integer',
        'requests'            => 'integer',
        'tokens'              => 'integer',
        'unknown_cost_events' => 'integer',
        'opened_at'           => 'datetime',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(AiBudget::class, 'budget_id');
    }
}
