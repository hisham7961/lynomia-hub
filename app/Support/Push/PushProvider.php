<?php

namespace App\Support\Push;

/**
 * **واجهةُ مزوّدِ الدفع** — تجريدٌ يفصل `PushService` عن أيِّ مزوّدٍ بعينه
 * (Mobile Readiness · الطور E · spec §Push «Provider abstraction»).
 *
 * تنفيذان: `NullPushProvider` (الافتراضيّ — بلا اعتمادات، يقول `not_configured`
 * ولا يُزيّف نجاحاً) و`FcmPushProvider` (نداءُ FCM الحقيقيّ، محجوبٌ خلف وجودِ
 * الاعتمادات). المُنادي يمرّر أوّليّاتٍ (رمز/منصّة/حمولة) لا موديلَ Eloquent —
 * فيبقى المزوّدُ محايداً قابلاً للاستبدال بمزوّدٍ مزيّفٍ في الاختبار.
 *
 * **الحمولةُ آمنةٌ بالبناء (spec §Push privacy):** يبنيها `PushService` من نوعِ
 * الإشعارِ ووحدتِه ومعرّفِه — عنوانٌ عامٌّ + تصنيفٌ + رابطٌ عميق + عددُ غير المقروء،
 * **لا نصَّ حسّاسٌ**. فلا يرى المزوّدُ سرّاً ولا رقماً ماليّاً ولا جسمَ رسالة.
 */
interface PushProvider
{
    /** اسمُ المزوّد للسجلّ (`null|fcm|apns`) — لا يحمل سرّاً */
    public function name(): string;

    /** هل الاعتماداتُ حاضرة؟ (المزوّدُ الصفريُّ دائماً false — الصدقُ لا التزييف) */
    public function isConfigured(): bool;

    /**
     * محاولةُ تسليمٍ واحدة — لا تُطلق استثناءً للفشل المتوقَّع (تعيد `failed`)؛
     * أمّا الأعطالُ غيرُ المتوقَّعة فيلتقطها `PushService` كي ينجوَ الإشعارُ الداخليّ.
     *
     * @param  string $token    رمزُ جهازِ المزوّد
     * @param  string $platform ios|android
     * @param  array  $payload  حمولةٌ آمنةٌ بناها PushService (بلا نصٍّ حسّاس)
     */
    public function send(string $token, string $platform, array $payload): PushSendResult;
}
