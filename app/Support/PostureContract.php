<?php

namespace App\Support;

/**
 * **عقدُ حالةِ الوضعيّة الأمنيّة** — مسارُ التصحيح §11 (توسيعٌ صادقٌ للعقد القائم).
 *
 * كان العقدُ ثلاثيّاً {active|inactive|not-configured} فيهبط كلُّ ما سواه إلى
 * not-configured — يخلط «معطَّلة» بـ«مُنع القارئُ» بـ«غيرُ مدعوم». هذا التوسيعُ
 * **الإضافيُّ** يفرّق بينها بصدق، والقاعدةُ الحاكمة تبقى (C15): **الامتثالُ لـ
 * 'active' وحدَها** — و«مُنع القارئ» (`permission-denied`) **ليس امتثالاً** أبداً
 * (§10/§11)، فلا يُحسَب فعّالاً ولا يُسكَت عنه.
 *
 * الحالاتُ الستّ:
 *  • `active`            — قُرئت فعّالةً حرفياً (الوحيدةُ الممتثِلة).
 *  • `inactive`          — قُرئت معطَّلةً (ثغرةٌ حقيقيّة).
 *  • `permission-denied` — منع النظامُ القراءةَ — **لا نعرف، فلا امتثال**.
 *  • `unavailable`       — الفحصُ قائمٌ لكن تعذّرت قراءتُه الآن (عابر).
 *  • `unsupported`       — الفحصُ لا ينطبق على هذا النظام/المنصّة.
 *  • `not-configured`    — لا قارئَ مُهيّأٌ لهذا الفحص (الافتراضُ الصادق).
 */
final class PostureContract
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
    public const PERMISSION_DENIED = 'permission-denied';
    public const UNAVAILABLE = 'unavailable';
    public const UNSUPPORTED = 'unsupported';
    public const NOT_CONFIGURED = 'not-configured';

    /** الحالاتُ الستّ — قائمةُ سماحٍ مغلقة (C10/C15)؛ خارجُها يهبط not-configured */
    public const STATES = [
        self::ACTIVE, self::INACTIVE, self::PERMISSION_DENIED,
        self::UNAVAILABLE, self::UNSUPPORTED, self::NOT_CONFIGURED,
    ];

    /** الممتثِلُ وحدَه — 'active' لا سواها (permission-denied **ليس** امتثالاً) */
    public const COMPLIANT = [self::ACTIVE];

    /** حالاتُ الانتباه — ثغرةٌ فعليّة أو «لا نعرف» (كلاهما ليس امتثالاً) */
    public const ATTENTION = [self::INACTIVE, self::PERMISSION_DENIED];

    /** وسومُ العرض العربية + نبرتها (g أخضر · bad أحمر · wn تحذير) */
    public const LABELS = [
        self::ACTIVE => ['فعّالة', 'g'],
        self::INACTIVE => ['معطَّلة', 'bad'],
        self::PERMISSION_DENIED => ['مُنعت القراءة (ليست امتثالاً)', 'bad'],
        self::UNAVAILABLE => ['تعذّرت القراءة (عابر)', 'wn'],
        self::UNSUPPORTED => ['غيرُ مدعومٍ على هذا النظام', 'wn'],
        self::NOT_CONFIGURED => ['غيرُ مُهيّأ', 'wn'],
    ];

    /** تطبيعُ قراءةٍ إلى العقد — خارجُ القائمة المغلقة ⇒ not-configured (لا ادّعاء) */
    public static function normalize(?string $reading): string
    {
        $r = strtolower(trim((string) $reading));

        return in_array($r, self::STATES, true) ? $r : self::NOT_CONFIGURED;
    }

    /** الامتثالُ الصارم: 'active' وحدَها — لا permission-denied ولا سواه */
    public static function isCompliant(?string $reading): bool
    {
        return self::normalize($reading) === self::ACTIVE;
    }

    /** وسمُ العرض [نصّ، نبرة] لحالةٍ */
    public static function label(?string $reading): array
    {
        return self::LABELS[self::normalize($reading)] ?? ['غيرُ مُهيّأ', 'wn'];
    }

    /**
     * **تقييمُ وضعيّةِ Wi-Fi الشركة (§10)** — من اتّصال الجهاز نفسِه فقط (لا مسحَ
     * شبكةٍ): يقارن SSID الذي بلّغه الوكيلُ بقائمة SSID المعتمدة في السياسة.
     *
     *  • `$readStatus` كما بلّغه الوكيلُ: readable | permission-denied | unsupported | unavailable.
     *  • مُنع القارئُ ⇒ `permission-denied` (**ليس امتثالاً** — §10 صراحةً).
     *  • غيرُ مدعوم/تعذّر ⇒ الحالةُ نفسُها (لا نزعم اتصالاً معتمداً).
     *  • قُرئ SSID:
     *     - بلا قائمةٍ معتمدة ⇒ `not-configured` (لا معيارَ للحكم).
     *     - ضمنَ المعتمدة ⇒ `active` (على شبكةٍ معتمدة).
     *     - خارجَها ⇒ `inactive` (على شبكةٍ غيرِ معتمدة — انتباه).
     */
    public static function evaluateWifi(?string $observedSsid, ?string $readStatus, array $approvedSsids): string
    {
        $status = strtolower(trim((string) $readStatus));

        if ($status === 'permission-denied' || $status === 'denied') {
            return self::PERMISSION_DENIED;   // §10: مُنع القراءة ≠ امتثال
        }
        if ($status === 'unsupported') {
            return self::UNSUPPORTED;
        }

        $ssid = trim((string) $observedSsid);
        if ($status !== 'readable' || $ssid === '') {
            return self::UNAVAILABLE;         // لم يُقرأ SSID — لا نزعم اتصالاً معتمداً
        }

        // تطبيعُ القائمة المعتمدة (قصٌّ + إسقاطُ الفارغ) — المقارنةُ بالنصّ الحرفيّ
        $approved = array_values(array_filter(array_map(fn ($s) => trim((string) $s), $approvedSsids), fn ($s) => $s !== ''));
        if ($approved === []) {
            return self::NOT_CONFIGURED;      // لا قائمةَ معتمدة ⇒ لا حكم
        }

        return in_array($ssid, $approved, true) ? self::ACTIVE : self::INACTIVE;
    }
}
