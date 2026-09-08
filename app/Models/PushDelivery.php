<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * **محاولةُ تسليمِ دفعٍ** — Mobile Readiness · الطور E (جدول `push_deliveries`).
 *
 * صفٌّ لكلِّ محاولةِ توجيهِ إشعارٍ عبر مزوّد. لا أسرارَ ولا نصَّ الإشعار (spec
 * §Push) — فقط الهويّاتُ والحالةُ وصنفُ الخطأ. الحالاتُ (`STATUSES`) allowlist
 * في التطبيق لا DB enum (C10)، و`not_configured` تقولُ الحقيقةَ حين لا مزوّد
 * (لا نجاحٌ مُزيَّف).
 *
 * `$timestamps=false`: لا `created_at`/`updated_at` — طابعا الحجزِ والمحاولةِ
 * (`queued_at`/`attempted_at`) وحدَهما.
 */
class PushDelivery extends Model
{
    use HasUuid;

    protected $table = 'push_deliveries';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $casts = [
        'queued_at'    => 'datetime',
        'attempted_at' => 'datetime',
        'attempts'     => 'integer',
    ];

    /** الحالاتُ المسموحة — allowlist في التطبيق (C10) */
    public const STATUSES = ['queued', 'attempted', 'delivered', 'failed', 'not_configured', 'skipped'];

    // ── أصنافُ الخطأ (تصنيفٌ تقنيٌّ لا رسالةَ مزوّدٍ خام · لا يُسرَّب نصُّ المزوّد) ──
    public const ERR_UNREGISTERED   = 'unregistered';    // الرمزُ لم يعد صالحاً لدى المزوّد
    public const ERR_INVALID_TOKEN  = 'invalid_token';   // رمزٌ مرفوضٌ شكلاً
    public const ERR_PROVIDER_ERROR = 'provider_error';  // خطأُ مزوّدٍ عامّ (٥xx/شبكة)
    public const ERR_RATE_LIMITED   = 'rate_limited';    // خنقُ المزوّد
    public const ERR_EXCEPTION      = 'exception';       // استثناءٌ مُلتقَطٌ — الإشعارُ الداخليُّ نجا
}
