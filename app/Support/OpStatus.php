<?php

namespace App\Support;

/**
 * **حالةُ التشغيل للعرض** — طبقةٌ فوق `Health` لا بديلٌ عنه.
 *
 * ثوابتُ `Health` الخمسة (HEALTHY|DEGRADED|UNAVAILABLE|MAINTENANCE|UNKNOWN) عقدٌ
 * محميٌّ (`/healthz` يبثّها حرفياً وعقدُه مُختبَر) — **لا تُعاد تسميتُها**. ما تحتاجه
 * الشاشاتُ هو قبولُ مرادفات الـspec (`warning`≈DEGRADED و`critical`≈UNAVAILABLE)
 * وإرجاعُ التسمية والنبرة من `Health::LABELS/TONE` نفسِها — قراءةٌ للعرض فقط.
 */
final class OpStatus
{
    /** مرادفاتُ العرض (بعد خفض الحالة) ⇒ ثابتُ Health القانونيّ */
    protected const SYNONYMS = [
        'healthy' => Health::HEALTHY, 'operational' => Health::HEALTHY, 'ok' => Health::HEALTHY,
        'degraded' => Health::DEGRADED, 'warning' => Health::DEGRADED,
        'unavailable' => Health::UNAVAILABLE, 'critical' => Health::UNAVAILABLE, 'fail' => Health::UNAVAILABLE, 'down' => Health::UNAVAILABLE,
        'maintenance' => Health::MAINTENANCE,
        'unknown' => Health::UNKNOWN,
    ];

    /** حالةُ Health القانونية لأيّ مدخل — ثوابتُ Health تعود نفسَها، والمجهولُ UNKNOWN */
    public static function fromHealth(string $h): string
    {
        return self::SYNONYMS[strtolower(trim($h))] ?? Health::UNKNOWN;
    }

    /** التسميةُ العربية — من Health::LABELS لا من نسخةٍ ثانية */
    public static function label(string $h): string
    {
        return Health::LABELS[self::fromHealth($h)];
    }

    /** نبرةُ الشارة — من Health::TONE لا من نسخةٍ ثانية */
    public static function tone(string $h): string
    {
        return Health::TONE[self::fromHealth($h)];
    }
}
