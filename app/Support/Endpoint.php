<?php

namespace App\Support;

/**
 * **المصدرُ الواحدُ لأنظمةِ تشغيلِ النقاط الطرفية** (التصحيح · §1) — قرارُ العمل:
 * المدعومُ رسميّاً **Windows وmacOS فقط**. Linux/iOS/Android/ChromeOS غيرُ مدعومة.
 *
 * `SUPPORTED` هي المرجعُ الواحد الذي تقرؤه: **تحقّقُ التسجيل** (السلطةُ الحاسمة)،
 * ونشرُ الإصدارات (`EndpointRelease::OSES`)، وخياراتُ واجهةِ الإدارة، والاختبارات،
 * والوثائق — قائمةٌ واحدةٌ لا تتكرّر ولا تتباعد.
 *
 * `STORED` تشمل `LEGACY_UNSUPPORTED` (linux) كي **تبقى صفوفُ التسجيلِ التاريخيّة**
 * (إن وُجدت من قبلِ هذا التصحيح) مقروءةً غيرَ محذوفة (§17: لا ترقيةٌ كاذبة، لا حذف):
 * تُصنَّف «قديمة/غيرُ مدعومة» في الواجهة، ويُمنَع تسجيلُ جديدٍ بها خادميّاً.
 *
 * **السلطةُ خادميّة:** حتى لو أعلن وكيلٌ على Linux نظامَه صادقاً، يرفض الخادمُ
 * تسجيلَه (٤٢٢)، ولا يُنشَر له أرتيفاكت (لا Linux في `EndpointRelease::OSES`) —
 * فلا يُسجَّل ولا يُحدَّث. فلا حاجةَ لأن يكذب الوكيلُ على نفسه لإرضاء عقدٍ.
 */
final class Endpoint
{
    /** المدعومُ رسميّاً — المصدرُ الواحد للتسجيل/الإصدارات/الواجهة/الاختبار/الوثائق */
    public const SUPPORTED = ['windows', 'macos'];

    /** مُسجَّلٌ تاريخيّاً لكن لم يعد مدعوماً — يُصان لا يُحذَف، ويُمنَع تسجيلُ جديدٍ به */
    public const LEGACY_UNSUPPORTED = ['linux'];

    /** كلُّ ما قد يحمله عمودُ `os` شرعيّاً (مدعوم + قديم) — لسعةِ العمود وقوائمِ التخزين */
    public const STORED = ['windows', 'macos', 'linux'];

    /** التسميةُ المعروضة لكلِّ نظام (القديمُ يحمل وسمَ «قديم») */
    public const LABELS = [
        'windows' => 'Windows',
        'macos'   => 'macOS',
        'linux'   => 'Linux (قديم — غيرُ مدعوم)',
    ];

    /** @return array<int,string> */
    public static function supported(): array
    {
        return self::SUPPORTED;
    }

    /** نظامٌ مدعومٌ رسميّاً (يُقبَل تسجيلاً ويُنشَر له) */
    public static function isSupported(?string $os): bool
    {
        return $os !== null && in_array(strtolower(trim($os)), self::SUPPORTED, true);
    }

    /** نظامٌ مُسجَّلٌ تاريخيّاً لم يعد مدعوماً (يُصان، يُصنَّف، يُمنَع تسجيلُ الجديد به) */
    public static function isLegacy(?string $os): bool
    {
        return $os !== null && in_array(strtolower(trim($os)), self::LEGACY_UNSUPPORTED, true);
    }

    /** التسميةُ الودّيّة للعرض (Windows/macOS/Linux قديم) */
    public static function label(?string $os): string
    {
        $os = strtolower(trim((string) $os));

        return self::LABELS[$os] ?? ($os !== '' ? $os : '—');
    }
}
