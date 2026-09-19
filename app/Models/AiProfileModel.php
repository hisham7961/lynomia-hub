<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * **حلقةٌ في سلسلةِ التوجيه** (المرحلة ٢ · W2).
 *
 * `rank = 0` أساسيّ · ١ فما فوقُ احتياط.
 *
 * **و`Auditable` هنا** (قرارُ المالك · B): **هذا أهمُّ جدولٍ يستحقّ أثراً** —
 * تغييرُ الأساسيِّ أو ترتيبِ الاحتياطِ يغيّر **من يجيب عن طلبِ المستخدمِ وبأيِّ
 * كلفة**. والسمةُ تحفظ السلسلةَ قبلاً وبعداً.
 */
class AiProfileModel extends Model
{
    use HasUuid, Auditable;

    protected $table = 'ai_profile_models';
    public const MODULE = 'ai_profile_models';

    protected $guarded = ['id'];

    protected $casts = ['rank' => 'integer', 'enabled' => 'boolean'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AiProfile::class, 'profile_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}
