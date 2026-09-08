<?php

namespace App\Support\Push;

/**
 * **المزوّدُ الصفريّ** — الافتراضيُّ حين لا اعتماداتِ دفعٍ (Mobile Readiness ·
 * الطور E · spec §Push «If no creds → NOT_CONFIGURED, never fake success»).
 *
 * لا يتّصل بشبكةٍ ولا يُزيّف تسليماً: كلُّ محاولةٍ تعيد `not_configured` — فيقول
 * سجلُّ `push_deliveries` الحقيقةَ عن كل إشعار. هو مزوّدُ الإنتاجِ ما لم تُضبَط
 * اعتماداتُ FCM، ومزوّدُ الاختبار الصادقُ لمسار «غير المهيّأ».
 */
class NullPushProvider implements PushProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;   // الصدقُ لا التزييف
    }

    public function send(string $token, string $platform, array $payload): PushSendResult
    {
        return PushSendResult::notConfigured();   // لا نجاحٌ مُزيَّف أبداً
    }
}
