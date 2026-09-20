<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * **نموذجٌ مُسجَّل** (المرحلة ٢ · W2).
 *
 * **وأعمدةُ المعرفةِ الأربعةُ نسخةٌ لا مصدرُ حقيقة** — تُقرأ من البوّابةِ
 * وتُوسَم بمصدرِها، وتُحذَف وتُبنى بلا فقد. **ولا تُملأ يدويّاً في الشيفرة.**
 *
 * **ولا `Auditable` ولا `HasVersions`** (قرارُ المالك · B): تحديثُ هذه
 * الأعمدةِ **مزامنةٌ لا قرارٌ بشريّ**. وتغيُّرُ كسرٍ عشريٍّ في سعرٍ يجعل عمودَ
 * JSON كلَّه متّسخاً، فتُكتب حمولةٌ كاملةٌ في كلِّ تحديث — ثلاثون نموذجاً
 * بتحديثٍ أسبوعيٍّ تُنتج ألفاً وخمسَمئةِ صفٍّ سنويّاً لا يقرؤها أحد.
 */
class AiModel extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'ai_models';
    public const MODULE = 'ai_models';
    public const DISPLAY = 'display_name';

    protected $guarded = ['id'];

    protected $casts = [
        'capabilities'        => 'array',
        'limits'              => 'array',
        'params'              => 'array',
        'pricing'             => 'array',
        'tags'                => 'array',
        'enabled'             => 'boolean',
        'priority'            => 'integer',
        'last_latency_ms'     => 'integer',
        'last_probe_at'       => 'datetime',
        'pricing_updated_at'  => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function profileLinks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AiProfileModel::class, 'model_id');
    }
}
