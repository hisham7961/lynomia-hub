<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * **تعريفُ ميزانيّةٍ أو حصّة** (المرحلة ٤ · P4-W4).
 *
 * **والعدّادُ ليس هنا** بل في `AiBudgetPeriod`: التعريفُ قرارُ إنسانٍ يُدقَّق،
 * والعدّادُ رقمٌ يتغيّر آلافَ المرّات. وخلطُهما في صفٍّ واحدٍ كان سيُنتج
 * أثرَ تدقيقٍ لكلِّ نداءِ توليد — **فيغرق أثرُ القرارِ في ضجيجِ العدّ**.
 */
class AiBudget extends Model
{
    use HasUuid, Auditable, SoftDeletes;

    protected $table = 'ai_budgets';
    public const MODULE = 'ai_budgets';
    public const DISPLAY = 'label';

    protected $guarded = ['id'];

    protected $casts = [
        'limit_micro'    => 'integer',
        'limit_requests' => 'integer',
        'limit_tokens'   => 'integer',
        'enforce'        => 'boolean',
        'enabled'        => 'boolean',
    ];

    /** **الترتيبُ مطلوبٌ صراحةً** — وقائمةٌ بلا `ORDER BY` قرعة */
    public function periods(): HasMany
    {
        return $this->hasMany(AiBudgetPeriod::class, 'budget_id')
            ->orderByDesc('period_key')->orderBy('id');
    }
}
