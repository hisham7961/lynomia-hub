<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * **مزوّدٌ متّصل** — نسخةٌ مُهيَّأةٌ من مدخلِ كتالوج (المرحلة ٢ · W2).
 *
 * **ولا سرَّ في هذا الصفّ.** `credential_name` **مرجعٌ** إلى خزنةِ LiteLLM، و
 * `config` يحمل الحقولَ غيرَ السرّيّةِ وحدَها. واختبارٌ يمسح أسماءَ الأعمدةِ
 * ويسقط إن ظهر عمودُ سرّ.
 *
 * **ولا `Auditable` ولا `HasVersions`** (قرارُ المالك · B): الجدولُ يخلط
 * حوكمةً نادرةً بتَلَمُّسٍ متكرّر (`health` · `last_probe_at`)، والسمةُ لا
 * تفرّق — فكلُّ فحصٍ كان سيُنتج أثراً. وأفعالُ الحوكمةِ تُسجَّل صراحةً بـ
 * `hub_audit()` عند الكاتب، حيث يُعرَف **قصدُ** الفعل.
 */
class AiProvider extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'ai_providers';
    public const MODULE = 'ai_providers';
    public const DISPLAY = 'label';

    protected $guarded = ['id'];

    protected $casts = [
        'config'          => 'array',
        'enabled'         => 'boolean',
        'last_probe_at'   => 'datetime',
        'last_latency_ms' => 'integer',
    ];

    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'provider_id');
    }
}
