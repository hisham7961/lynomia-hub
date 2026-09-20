<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * **ملفُّ سياسة** (المرحلة ٢ · W2) — `general` · `fast` · `reasoning` …
 *
 * **و`Auditable` هنا** (قرارُ المالك · B): حوكمةٌ خالصةٌ بلا عمودِ تَلَمُّسٍ
 * واحد، وكلُّ كتابةٍ فيه قرارُ مديرٍ بتعريفِ سياسة.
 */
class AiProfile extends Model
{
    use HasUuid, Auditable;

    protected $table = 'ai_profiles';
    public const MODULE = 'ai_profiles';
    public const DISPLAY = 'label';

    protected $guarded = ['id'];

    protected $casts = ['enabled' => 'boolean'];

    /** **الترتيبُ مطلوبٌ صراحةً** — فالسلسلةُ بلا `ORDER BY` قرعة */
    public function links(): HasMany
    {
        return $this->hasMany(AiProfileModel::class, 'profile_id')->orderBy('rank');
    }
}
