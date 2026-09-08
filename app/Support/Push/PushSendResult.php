<?php

namespace App\Support\Push;

/**
 * **نتيجةُ محاولةِ تسليمٍ واحدة** — قيمةٌ محايدةٌ يعيدها `PushProvider::send`
 * فيترجمها `PushService` إلى صفِّ `push_deliveries`. لا تحمل نصَّ المزوّد الخام
 * (تسريبٌ محتمل) — بل حالةً من `PushDelivery::STATUSES` وصنفَ خطأٍ تقنيّاً.
 *
 * Mobile Readiness · الطور E · SF-1.
 */
final class PushSendResult
{
    private function __construct(
        public readonly string $status,          // delivered|failed|not_configured|skipped
        public readonly ?string $errorCategory = null,
    ) {
    }

    /** سُلِّم فعلاً لدى المزوّد */
    public static function delivered(): self
    {
        return new self('delivered');
    }

    /** فشل التسليمُ لدى مزوّدٍ مُهيّأ — بصنفٍ تقنيّ (لا رسالةَ مزوّدٍ خام) */
    public static function failed(string $errorCategory): self
    {
        return new self('failed', $errorCategory);
    }

    /** لا مزوّدَ مُهيّأ — **الحقيقة**، لا نجاحٌ مُزيَّف (spec §Push NOT_CONFIGURED) */
    public static function notConfigured(): self
    {
        return new self('not_configured');
    }

    /** تُخُطِّيَ عمداً (رمزٌ مُبطَل/تنصيبٌ بلا قدرةِ دفعٍ) — لا محاولةَ شبكة */
    public static function skipped(?string $errorCategory = null): self
    {
        return new self('skipped', $errorCategory);
    }
}
