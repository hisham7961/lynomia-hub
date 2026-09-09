<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * **مؤشّرُ الكتابةِ العابر** (§typing · IMPLEMENT_NOW) — «فلانٌ يكتب…».
 *
 * **عابرٌ لا يُخزَّن كتلمترٍ دائم:** يُحفظ في الذاكرةِ المؤقّتة (Cache) بمدّةٍ قصيرةٍ
 * تنتهي ذاتيّاً، **لا يُدقَّق**، ولا يصير سجلَّ نشاطٍ دائماً. مُنطَّقٌ بأعضاءِ المحادثةِ
 * المصرَّح لهم (الحارسُ عند نقطةِ النبض والقراءة). محايدُ النقل: يُسلَّم عبر عقدِ
 * الاستطلاع/الأحداث نفسِه، فيستبدله websocket لاحقاً بلا تغيّرِ عقدِ المجال/الواجهة.
 *
 * **الإشارةُ الفائتةُ تُنتهى لا تُزيَّف:** إن تقادمت قبل التسليم تُسقَط بدل عرضِ حالةٍ
 * كاذبة (النافذةُ `WINDOW` ثوانٍ قليلة).
 */
class Typing
{
    /** نافذةُ صلاحيّةِ إشارةِ الكتابة (ثوانٍ) — تنتهي ذاتيّاً */
    public const WINDOW = 8;

    private static function key(string $scopeId): string
    {
        return 'collab:typing:' . $scopeId;
    }

    /** يسجّل «أكتب الآن» في نطاقِ محادثةٍ — يُقلِّم المنتهي، ويُخزَّن بمدّةٍ قصيرة */
    public static function ping(string $scopeId, string $userId): void
    {
        $now = now()->getTimestamp();
        $map = self::prune((array) Cache::get(self::key($scopeId), []), $now);
        $map[$userId] = $now;
        Cache::put(self::key($scopeId), $map, self::WINDOW + 4);
    }

    /** المستخدمون الكاتبون الآنَ في النطاق (غيرَ المنتهين، عدا القارئ) */
    public static function current(string $scopeId, string $excludeUserId): array
    {
        $map = self::prune((array) Cache::get(self::key($scopeId), []), now()->getTimestamp());

        return array_values(array_filter(array_keys($map), fn ($uid) => (string) $uid !== $excludeUserId));
    }

    /** يمسح إشارةَ مستخدمٍ (مثلاً بعد الإرسال) */
    public static function clear(string $scopeId, string $userId): void
    {
        $map = self::prune((array) Cache::get(self::key($scopeId), []), now()->getTimestamp());
        unset($map[$userId]);
        Cache::put(self::key($scopeId), $map, self::WINDOW + 4);
    }

    /** يُسقِط الإشاراتِ المتقادمةَ (فائتةٌ ⇒ تُنتهى لا تُزيَّف) */
    private static function prune(array $map, int $now): array
    {
        $cut = $now - self::WINDOW;

        return array_filter($map, fn ($ts) => (int) $ts >= $cut);
    }
}
