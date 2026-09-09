<?php

namespace App\Support;

/**
 * **حالةُ القدرة — النموذجُ المطبَّع** (سجلّ القدرات · §4).
 *
 * تسعُ حالاتٍ صادقة تُشتقُّ من **الكود + الإعدادات + فاحصاتِ الاتصال** لا من تفاؤلٍ يدويّ.
 * لا حالةَ متفائلة: `NOT_CONFIGURED`/`EXTERNAL` لا تُعرَض `ENABLED` أبداً بلا دليل. وكلُّ
 * حالةٍ غيرِ `ENABLED` تحمل **سبباً** يُعرَض. والثوابتُ الأمنيّة `SYSTEM_INVARIANT` لا تصير
 * مفتاحاً — لا انتقالَ منها إلى `DISABLED` بأيِّ باب.
 */
class FeatureStatus
{
    public const ENABLED          = 'ENABLED';           // منفَّذةٌ ومُختبَرةٌ ومتاحةٌ الآن
    public const DISABLED         = 'DISABLED';          // منفَّذةٌ مُطفأةٌ عمداً (اختياريّةٌ حصراً)
    public const READY            = 'READY';             // مكتملةٌ تنفيذاً، خطوةٌ تاليةٌ غيرُ نشطةٍ عمداً
    public const DEVELOPMENT      = 'DEVELOPMENT';       // ناقصةٌ/غيرُ جاهزةٍ للإنتاج
    public const DEFERRED         = 'DEFERRED';          // مؤجَّلةٌ عمداً
    public const NOT_CONFIGURED   = 'NOT_CONFIGURED';    // منفَّذةٌ لكنّ البنية/المزوّد/الاعتماد غائب
    public const EXTERNAL         = 'EXTERNAL';          // تعتمد أساساً على مزوّدٍ خارجيّ
    public const DEGRADED         = 'DEGRADED';          // متاحةٌ بوضعٍ مُخفَّض/احتياطيّ
    public const SYSTEM_INVARIANT = 'SYSTEM_INVARIANT';  // سلوكٌ أمنيٌّ إلزاميٌّ لا يصير مفتاحاً

    /** كلُّ الحالاتِ المشروعة — لا حالةَ خارجَها في السجلّ */
    public const ALL = [
        self::ENABLED, self::DISABLED, self::READY, self::DEVELOPMENT, self::DEFERRED,
        self::NOT_CONFIGURED, self::EXTERNAL, self::DEGRADED, self::SYSTEM_INVARIANT,
    ];

    /** التسميةُ العربية لكلِّ حالة */
    public const LABELS_AR = [
        self::ENABLED => 'مُفعَّلة', self::DISABLED => 'مُطفأة', self::READY => 'جاهزة (لم تُفعَّل)',
        self::DEVELOPMENT => 'قيدَ التطوير', self::DEFERRED => 'مؤجَّلة', self::NOT_CONFIGURED => 'غير مُهيَّأة',
        self::EXTERNAL => 'اعتمادٌ خارجيّ', self::DEGRADED => 'مُخفَّضة', self::SYSTEM_INVARIANT => 'ثابتٌ نظاميّ',
    ];

    /** التسميةُ الإنجليزية لكلِّ حالة */
    public const LABELS_EN = [
        self::ENABLED => 'Enabled', self::DISABLED => 'Disabled', self::READY => 'Ready',
        self::DEVELOPMENT => 'In development', self::DEFERRED => 'Deferred', self::NOT_CONFIGURED => 'Not configured',
        self::EXTERNAL => 'External', self::DEGRADED => 'Degraded', self::SYSTEM_INVARIANT => 'System invariant',
    ];

    /** نبرةُ الشارة (على شاراتِ الورقة المعرَّفة: ok/wn/bad/g/i) — عرضٌ لا حساب */
    public const TONE = [
        self::ENABLED => 'ok', self::DISABLED => 'g', self::READY => 'i',
        self::DEVELOPMENT => 'wn', self::DEFERRED => 'g', self::NOT_CONFIGURED => 'wn',
        self::EXTERNAL => 'i', self::DEGRADED => 'bad', self::SYSTEM_INVARIANT => 'i',
    ];

    /** أيقونةٌ مختصرةٌ لكلِّ حالة */
    public const ICON = [
        self::ENABLED => '✅', self::DISABLED => '⬛', self::READY => '🟣',
        self::DEVELOPMENT => '🛠️', self::DEFERRED => '🕒', self::NOT_CONFIGURED => '⚙️',
        self::EXTERNAL => '🌐', self::DEGRADED => '⚠️', self::SYSTEM_INVARIANT => '🔒',
    ];

    /** حالةٌ مشروعةٌ في السجلّ؟ */
    public static function valid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /** هل القدرةُ متاحةٌ للاستعمالِ الآن (تشغيليّاً)؟ — التوافرُ لا الصلاحية */
    public static function isAvailable(string $status): bool
    {
        // المُفعَّلُ والثابتُ النظاميُّ والمُخفَّضُ متاحةٌ تشغيليّاً؛ وما عداها ليس بعد.
        return in_array($status, [self::ENABLED, self::SYSTEM_INVARIANT, self::DEGRADED], true);
    }

    /** حالةٌ أمنيّةٌ لا تُطفأ أبداً؟ */
    public static function isInvariant(string $status): bool
    {
        return $status === self::SYSTEM_INVARIANT;
    }

    public static function labelAr(string $status): string
    {
        return self::LABELS_AR[$status] ?? $status;
    }

    public static function labelEn(string $status): string
    {
        return self::LABELS_EN[$status] ?? $status;
    }

    public static function tone(string $status): string
    {
        return self::TONE[$status] ?? 'g';
    }

    public static function icon(string $status): string
    {
        return self::ICON[$status] ?? '•';
    }

    /**
     * هل يُسمح بانتقالٍ يدويٍّ من حالةٍ إلى أخرى؟ — الحرّاسُ الصلبة (§13):
     *   · لا خروجَ من `SYSTEM_INVARIANT` أبداً (ولا دخولَ إليه يدويّاً).
     *   · `NOT_CONFIGURED → ENABLED` ممنوعٌ (الاعتماديّاتُ غائبةٌ فعلاً — يُحسم بالفحص لا باليد).
     *   · `DEFERRED → ENABLED` ممنوعٌ (التنفيذُ غائبٌ موضوعيّاً).
     * التبديلُ المشروعُ الوحيدُ للقدرةِ الاختياريّة: `ENABLED ↔ DISABLED` (وكلاهما «متاح تنفيذاً»).
     */
    public static function transitionAllowed(string $from, string $to): bool
    {
        if (! self::valid($from) || ! self::valid($to)) return false;
        if ($from === self::SYSTEM_INVARIANT || $to === self::SYSTEM_INVARIANT) return false;
        if ($to === self::ENABLED && in_array($from, [self::NOT_CONFIGURED, self::DEFERRED, self::DEVELOPMENT, self::EXTERNAL], true)) {
            return false;
        }

        return in_array([$from, $to], [
            [self::ENABLED, self::DISABLED],
            [self::DISABLED, self::ENABLED],
        ], true);
    }
}
