<?php

namespace App\Support;

/**
 * **بريدُ النظام من حقول الشاشة لا من ملف الخادم.**
 *
 * «ليش بالخادم؟ ليش ما أضيفها بالمشروع كخانات؟» — كان `.env` الطريقَ
 * الوحيد لأن أحداً لم يبنِ الحقول. الآن تُكتب إعدادات SMTP من مركز
 * المراسلة وتُخزَّن في جدول الإعدادات (كلمةُ المرور مشفَّرة)، وتُطبَّق
 * عند إقلاع كل طلبٍ فتغلب ما في `.env` — والملفُّ يبقى احتياطاً لمن
 * تركَ الحقولَ فارغة: إضافةٌ لا كسر.
 */
class MailSettings
{
    /** هل ضُبط البريد من حقول النظام؟ (host هو مفتاح التفعيل) */
    public static function fromScreen(): bool
    {
        try {
            return filled(setting('mail.host'));
        } catch (\Throwable $e) {
            return false;   // قاعدةٌ غير مهيأة بعد (تنصيب أول) — يبقى .env
        }
    }

    /** تطبيق حقول الشاشة على إعدادات البريد الحية — يُستدعى عند الإقلاع */
    public static function apply(): void
    {
        if (! self::fromScreen()) return;

        $enc = strtolower(trim((string) setting('mail.encryption', 'tls')));
        config([
            'mail.default'                 => 'smtp',
            'mail.mailers.smtp.host'       => (string) setting('mail.host'),
            'mail.mailers.smtp.port'       => (int) (setting('mail.port') ?: 587),
            'mail.mailers.smtp.username'   => (string) setting('mail.username'),
            'mail.mailers.smtp.password'   => (string) setting('mail.password'),
            'mail.mailers.smtp.encryption' => in_array($enc, ['tls', 'ssl'], true) ? $enc : null,
        ]);
        if (filled(setting('mail.from_address'))) {
            config(['mail.from.address' => (string) setting('mail.from_address')]);
        }
        if (filled(setting('mail.from_name'))) {
            config(['mail.from.name' => (string) setting('mail.from_name')]);
        }
    }
}
