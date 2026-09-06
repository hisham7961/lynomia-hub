<?php

namespace App\Support;

/**
 * **سلّمُ الشدّة الواحد** — طبقةُ خرائطَ فوق مفردات المستودع، لا مفرداتٌ سادسة.
 *
 * المستودع يتكلّم الشدّةَ بلهجاتٍ عدّة: `ErrorTaxonomy` (INFO|WARNING|ERROR|HIGH|CRITICAL
 * وتسمياتُها العربية)، و`SecurityEvents` (info|notice|warning|high)، وخياراتُ الحوادث
 * المذكّرة والمشاكلِ المؤنّثة في `config/hub.php`، ومفرداتُ `ActionCenter` (حرج|مهم|اطّلاع).
 * `normalize` يطويها كلَّها إلى خمس درجاتٍ قياسية للعرض والتصفية والعدّ.
 *
 * قاعدتان: **لا تحويلَ مدمِّرٍ لقيمةٍ مخزَّنة** (الخرائطُ قراءةٌ لا كتابة)، و**النبرةُ
 * ليست شدّةً** — `ok|wn|bad` صفةُ لونٍ لا درجةُ خطر، فلا تدخل `normalize` (شدّةُ
 * نتائج الأمن تُعيَّن بالرمز صراحةً لا تُستنتَج من اللون)؛ المجهولُ يهبط إلى `info`.
 */
final class Severity
{
    /** الدرجاتُ الخمس تصاعدياً */
    public const LEVELS = ['info', 'low', 'medium', 'high', 'critical'];

    /** التسمياتُ العربية للشاشات */
    public const LABELS = ['info' => 'معلوماتي', 'low' => 'منخفض', 'medium' => 'متوسط', 'high' => 'مرتفع', 'critical' => 'حرج'];

    /** نبرةُ الشارة (.bdg) لكل درجة */
    public const TONE = ['info' => 'g', 'low' => 'g', 'medium' => 'wn', 'high' => 'bad', 'critical' => 'bad'];

    /** ترتيبُ الفرز والمقارنة — الأشدُّ أعلى */
    public const RANK = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    /**
     * خريطةُ المفردات (بعد خفض الحالة) — كلُّ قيمةٍ حرفية تكتبها ثوابتُ المستودع.
     * ERROR ⇒ high لا medium: خطأٌ مصنَّفٌ في `ErrorTaxonomy` فوق WARNING ودون CRITICAL،
     * ورتابةُ RANK هناك (0..4) تبقى محفوظةً هنا (info ≤ medium ≤ high ≤ high ≤ critical).
     */
    protected const MAP = [
        // الدرجاتُ القياسية نفسُها — ثابتةٌ على قيمها
        'info' => 'info', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'critical' => 'critical',
        // ErrorTaxonomy::SEVERITIES (تشمل قيمَ hub_schedule_failed: ERROR|HIGH) — بعد خفض الحالة
        'warning' => 'medium', 'error' => 'high',
        // ErrorTaxonomy::LABELS للشدّة
        'معلومة' => 'info', 'تحذير' => 'medium', 'خطأ' => 'high', 'عالٍ' => 'high',
        // SecurityEvents::SEVERITY_TONE — notice فوق info ودون warning
        'notice' => 'low',
        // config/hub.php: خياراتُ الحوادث (مذكّر)
        'حرج' => 'critical', 'عالي' => 'high', 'متوسط' => 'medium', 'منخفض' => 'low',
        // config/hub.php: خياراتُ المشاكل (مؤنّث)
        'حرجة' => 'critical', 'عالية' => 'high', 'متوسطة' => 'medium', 'منخفضة' => 'low',
        // ActionCenter::RANK
        'مهم' => 'high', 'اطّلاع' => 'info', 'اطلاع' => 'info',
        // تسمياتُ هذا السلّم نفسِه — فالعرضُ قد يعود مدخلاً في فلتر
        'معلوماتي' => 'info', 'مرتفع' => 'high',
    ];

    /** تطبيعُ أيّ مفردةِ شدّةٍ في المستودع إلى إحدى الدرجات الخمس — المجهولُ info */
    public static function normalize(?string $any): string
    {
        return self::MAP[mb_strtolower(trim((string) $any))] ?? 'info';
    }

    /** التسميةُ العربية — يقبل درجةً قياسية أو أيَّ مفردةٍ فيطبّعها أولاً */
    public static function label(?string $level): string
    {
        return self::LABELS[self::normalize($level)];
    }

    /** نبرةُ الشارة (g|wn|bad) للدرجة */
    public static function tone(?string $level): string
    {
        return self::TONE[self::normalize($level)];
    }

    /** رتبةُ الفرز (0..4) */
    public static function rank(?string $level): int
    {
        return self::RANK[self::normalize($level)];
    }

    /** هل a بشدّة b أو أشدّ؟ (للعتبات: «أشعِرني من high فصاعداً») */
    public static function atLeast(?string $a, ?string $b): bool
    {
        return self::rank($a) >= self::rank($b);
    }
}
