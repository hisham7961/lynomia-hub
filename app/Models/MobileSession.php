<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * جلسةُ مستخدمِ جوالٍ أصيل — Mobile Readiness · الطور B.
 *
 * زوجُ رمزَين (وصولٌ قصيرُ الأجل + تحديثٌ متجدّدٌ لمرّة) يُخزَّنان **تجزئةَ sha256
 * حصراً** (نمطُ `ApiToken` — INVENTORY §3a): لا نصَّ صريحاً في القاعدة أبداً.
 * الإبطالُ ناعمٌ (`revoked_at` + سبب) لا حذف — الصفُّ يبقى شاهداً ولكشفِ إعادةِ
 * رمزِ التحديث. آلةُ التدوير والإبطال في `App\Support\MobileSessionService`.
 *
 * مفهومٌ **مستقلٌّ** عن `api_tokens` (مفتاحُ التكامل الطويل، غيرُ المربوطِ بجهاز).
 */
class MobileSession extends Model
{
    use HasUuid;

    protected $table = 'mobile_sessions';
    protected $guarded = ['id'];

    protected $casts = [
        'access_expires_at'  => 'datetime',
        'refresh_expires_at' => 'datetime',
        'last_used_at'       => 'datetime',
        'revoked_at'         => 'datetime',
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function installation()
    {
        return $this->belongsTo(MobileInstallation::class, 'installation_id');
    }

    /** جلسةٌ سارية: غيرُ مُبطَلة ورمزُ وصولها لم ينتهِ بعد */
    public function isActive(): bool
    {
        return ! $this->revoked_at
            && $this->access_expires_at
            && now()->lte($this->access_expires_at);
    }

    /** هل رمزُ التحديث ساري المفعول؟ (غيرُ مُبطَلٍ ولم ينتهِ) */
    public function refreshUsable(): bool
    {
        return ! $this->revoked_at
            && $this->refresh_expires_at
            && now()->lte($this->refresh_expires_at);
    }
}
