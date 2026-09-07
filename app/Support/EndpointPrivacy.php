<?php

namespace App\Support;

/**
 * **مُصادِقُ خصوصيّة النقاط الطرفية** — Work OS · الطور J · WP-J.2 · §43.
 *
 * الخصوصيّةُ **تُفرَض خادمياً لا وعداً**: خادمُ الأسطول يدير أجهزةً لا يتجسّس
 * على مستخدميها — فأيُّ حمولةٍ (heartbeat/حدث/نتيجة أمر) تحمل حقولَ مراقبةٍ
 * تُرَدّ **422 مسجَّلةً** قبل أن يُخزَّن منها حرف، ولو أرسلها وكيلٌ مارقٌ أو
 * معطوب: keystrokes/keylog، لقطاتُ الشاشة، الحافظة، التصفّح والتاريخ، محتوى
 * الملفات وحصادُها، الكاميرا والميكروفون، اعتراضُ المكالمات والرسائل.
 *
 * المطابقةُ **بالمفتاح وبالمفتاح المتشعّب** (recursively) بعد تطبيعٍ بسيط
 * (حروفٌ صغيرة، الفواصلُ كلُّها `_`) — وبالاحتواء لا بالتساوي، **fail-closed
 * عمداً**: `usb_keylogger_found` يُرفض مثل `keylog` سواء، ومفتاحٌ بريءٌ صادفَ
 * لفظاً محظوراً (`history`) يُعاد تسميتُه عند مُرسِله لا يُستثنى هنا — كلفةُ
 * الرفض الزائد إعادةُ تسمية، وكلفةُ القبول الزائد تجسّسٌ مخزَّن.
 *
 * الصنفُ يُستهلَك من ثلاثة مواضع لا غير: مُصادِقُ الابتلاع في
 * `EndpointProtocolController` (الرفضُ 422 المسجَّل)، وحاجزا `saving` الأخيران
 * في `EndpointEvent`/`EndpointCommand` (دفاعٌ في العمق).
 */
class EndpointPrivacy
{
    /**
     * ألفاظُ المراقبة المحظورة — تُطابَق احتواءً في أسماء المفاتيح المطبَّعة.
     * القائمةُ قائمةُ **رفض** (على عكس allowlist الأوامر): التجسّسُ يُسمّى
     * بأسمائه المعروفة، والبريءُ الملتبس يُعاد تسميتُه عند مصدره.
     */
    public const FORBIDDEN = [
        // لوحةُ المفاتيح
        'keystroke', 'keylog', 'keyboard_capture',
        // الشاشة
        'screenshot', 'screen_capture', 'screencap', 'screen_record', 'screen_grab',
        // الحافظة
        'clipboard',
        // التصفّح والتاريخ
        'browsing', 'browser_history', 'history', 'visited_url', 'web_activity',
        // محتوى الملفات وحصادُها
        'file_content', 'file_dump', 'file_harvest', 'document_content',
        // الكاميرا والميكروفون
        'camera', 'webcam', 'microphone', 'mic_capture', 'audio_capture', 'video_capture',
        // المكالمات والرسائل
        'sms', 'call_log', 'call_record', 'call_intercept', 'message_log',
        'message_content', 'chat_log', 'im_log', 'wiretap',
        // التقاطُ الشبكة (محتوىً لا وضعية — network_self وضعيّةُ الجهاز نفسِه فمسموحة)
        'packet_capture', 'pcap', 'traffic_dump',
    ];

    /**
     * مفاتيحُ المراقبة في حمولةٍ — **بالعمق**: تُعاد مساراتُ المخالفة
     * (`meta.nested.screenshot`) أو `[]` إن كانت نظيفة. المفاتيحُ وحدَها
     * تُفحَص لا القيَم — القيمةُ البريئة تحت مفتاحِ تجسّسٍ تجسّسٌ مقصود.
     */
    public static function violations(array $payload, string $prefix = ''): array
    {
        $bad = [];
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_string($key)) {
                $norm = strtolower(str_replace(['-', ' ', '.'], '_', $key));
                foreach (self::FORBIDDEN as $token) {
                    if (str_contains($norm, $token)) {
                        $bad[] = $path;
                        break;
                    }
                }
            }
            if (is_array($value)) {
                $bad = array_merge($bad, self::violations($value, $path));
            }
        }

        return $bad;
    }

    /**
     * تنقيحُ نصٍّ للتخزين: محارفُ التحكّم تُنزَع (تكسر السجلَّ والشاشة) والطولُ
     * يُقصّ **بمحارفَ** لا بايتات (mb_substr — القصُّ بالبايتات يقطع الحرفَ
     * العربيّ نصفين) — كاتبُ `summary`/`reason` الواحد.
     */
    public static function sanitizeText(string $text, int $max): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

        return mb_substr($clean, 0, $max);
    }
}
