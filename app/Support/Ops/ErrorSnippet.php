<?php

namespace App\Support\Ops;

use App\Models\ErrorEvent;

/**
 * **مقتطفُ الشيفرة حول سطر الخطأ — من داخل جذر المشروع حصراً** (v2.318).
 *
 * `error_events.file` صفٌّ يزرعه أيُّ مستخدمٍ مسجَّل بإحداث خطأ، وقراءتُه كما هو تعني قراءةَ أيّ
 * ملفٍ على القرص (`.env`، مفاتيح، `/etc/passwd`). فالحارسُ هنا واحدٌ لشاشة مركز الأخطاء ولمساعد
 * التطوير معاً — لا نسختان تتباعدان.
 */
final class ErrorSnippet
{
    /** ما لا يُقرأ ولو كان داخل الجذر: أسرارٌ مخبّأة (`bootstrap/cache/config.php` يحمل APP_KEY وكلماتِ المرور)
     *  وجلساتٌ وسجلّاتٌ وملفّاتٌ مرفوعة (`storage/`)، وكلُّ ملفٍّ أو مجلّدٍ نقطيّ (`.env*` · `.git/config`) */
    public const DENY = ['bootstrap/cache/', 'storage/'];

    /** وما يجوز أن **يغادر إلى مزوّد نموذج**: شيفرةُ المشروع المصدريّة وحدَها */
    public const MODEL_ALLOW = ['app/', 'routes/', 'resources/views/', 'database/', 'config/'];

    /**
     * المسارُ الحقيقيّ إن كان ملفّاً مقروءاً داخل الجذر، خارجَ `DENY` وبلا جزءٍ نقطيّ — وإلّا `null`.
     * و`$forModel` يضيّقه إلى `MODEL_ALLOW`، **ويرفض خطأَ `js`** كلَّه: ملفُّه يكتبه أيُّ مستخدمٍ مسجَّل (`jslog`).
     */
    public static function realPath(ErrorEvent $e, bool $forModel = false): ?string
    {
        if ($forModel && $e->kind === 'js') return null;
        $path = (string) $e->file;
        $real = $path !== '' ? @realpath($path) : false;
        if ($real === false) return null;
        $root = rtrim(@realpath(base_path()) ?: base_path(), '/');
        if (! str_starts_with($real, $root . '/')) return null;
        $rel = substr($real, strlen($root) + 1);
        if (preg_match('#(^|/)\.#', $rel)) return null;
        foreach (self::DENY as $d) if (str_starts_with($rel, $d)) return null;
        if ($forModel) {
            $ok = false;
            foreach (self::MODEL_ALLOW as $a) if (str_starts_with($rel, $a)) $ok = true;
            if (! $ok) return null;
        }

        return is_file($real) && is_readable($real) ? $real : null;
    }

    /** @return list<array{n: int, code: string, hot: bool}> */
    public static function around(string $real, int $line, int $before = 5, int $after = 5): array
    {
        $out = [];
        try {
            $lines = @file($real, FILE_IGNORE_NEW_LINES);
            if ($lines === false || $line < 1) return [];
            $from = max(0, $line - 1 - $before);
            $to = min(count($lines) - 1, $line - 1 + $after);
            for ($i = $from; $i <= $to; $i++) {
                $out[] = ['n' => $i + 1, 'code' => $lines[$i], 'hot' => ($i + 1) === $line];
            }
        } catch (\Throwable) {
        }

        return $out;
    }
}
