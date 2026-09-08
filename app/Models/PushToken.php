<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * **رمزُ دفعِ جوالٍ** — Mobile Readiness · الطور E (جدول `push_tokens`).
 *
 * رمزُ توجيهِ إشعارٍ إلى جهازٍ (FCM/APNs)، مربوطٌ بتنصيبٍ وصاحبِه. الإبطالُ
 * ناعمٌ (`revoked_at`) فيتوقّف التسليمُ دون فقدِ الأثر — نظيرُ `ApiToken`/جلساتِ
 * الجوال. القيمُ المسموحة (`PLATFORMS`/`PROVIDERS`) allowlist في التطبيق لا DB
 * enum (C10). لا يُدقَّق ولا يُسجَّل نصُّ الرمزِ أبداً (spec §Security).
 */
class PushToken extends Model
{
    use HasUuid;

    protected $table = 'push_tokens';
    protected $guarded = ['id'];
    protected $casts = [
        'last_confirmed_at' => 'datetime',
        'revoked_at'        => 'datetime',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    /** المنصّاتُ المسموحة — allowlist في التطبيق (C10) */
    public const PLATFORMS = ['ios', 'android'];

    /** مزوّدو الدفعِ المسموحون — allowlist في التطبيق (C10) */
    public const PROVIDERS = ['fcm', 'apns'];

    /** الرموزُ الحيّة (غيرُ المُبطَلة) — وحدَها تتلقّى تسليماً */
    public function scopeActive($q)
    {
        return $q->whereNull('revoked_at');
    }
}
