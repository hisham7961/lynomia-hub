<?php

namespace App\Support;

use App\Http\Controllers\Web\SearchController;

/**
 * **بحثُ «اسأل Hub» — مُهايئٌ لا محرّكٌ ثانٍ** (المرحلة ٣ · P3-W3).
 *
 * ── **لماذا مُهايئٌ رقيقٌ لا بحثٌ جديد؟** ──
 *
 * ‏`SearchController::results()` يفعل **بالضبط** ما نحتاجه: يمرّ على الوحداتِ
 * المسموحةِ لهذا المستخدم (`hub_can(v)` + وجودُ موديل + شدُّ `endpoints`
 * للرقابة)، ويستعلم داخلَ `hub_scope` + `hub_client_scope`، **بترتيبٍ حتميٍّ
 * ينتهي بـ`id`** فلا قرعةَ بين المحرّكَين، ويُعيد `{module, id, name, label}`.
 *
 * **وبحثٌ ثانٍ — مهما أُتقن — ينحرف عن الأوّلِ بعد شهر.** يُضاف شدٌّ أمنيٌّ في
 * أحدِهما ويُنسى في الآخر، **فيُسرّب المساعدُ ما لا تُسرّبه الشاشة**. وقيدُ
 * المالكِ الرابع يقول هذا حرفاً: «أعِد استعمال… ولا تبنِ نظامَ صلاحيّاتٍ
 * موازياً بلا ضرورة».
 *
 * **والثمنُ المقبول:** استدعاءُ متحكّمٍ من طبقةِ دعم. وهو أقلُّ ضرراً من
 * نسخِ منطقِ حراسةٍ يُراجَع مرّتين — **ونقطةُ الحقيقةِ تبقى واحدة**.
 */
final class AskSearch
{
    /** كم صفّاً من كلِّ وحدةٍ قبل السقفِ العامّ */
    public const PER_MODULE = 3;

    /**
     * **نتائجُ بحثٍ داخلَ نطاقِ المستخدمِ الحاليّ.**
     *
     * @return list<array{module: string, id: mixed, name: string, label: string}>
     */
    public static function across(string $q, mixed $user = null, int $cap = 12): array
    {
        $q = trim($q);
        if (mb_strlen($q) < AskTools::MIN_SEARCH_CHARS) return [];

        /*
         * **والمستخدمُ هو صاحبُ الجلسةِ بالضرورة.**
         *
         * `results()` يقرأ `auth()->user()` في حراسته. فلو سُمح هنا بتمريرِ
         * مستخدمٍ آخرَ لصار **الوسيطُ بابَ تجاوز**: يُمرَّر مستخدمٌ أوسعُ
         * نطاقاً فتُعاد صفوفُه. فالوسيطُ يُقبَل للتوثيقِ ويُرَدُّ إن خالف.
         */
        $u = $user ?? auth()->user();
        if ($u === null) return [];
        if (auth()->id() === null || (string) auth()->id() !== (string) $u->id) return [];

        return rescue(
            fn () => app(SearchController::class)->results($q, self::PER_MODULE, $cap),
            [],
            false
        );
    }
}
