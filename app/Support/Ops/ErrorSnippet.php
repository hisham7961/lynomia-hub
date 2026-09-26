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
    /** المسارُ الحقيقيّ إن كان ملفّاً مقروءاً داخل الجذر وليس `.env*` — وإلّا `null` */
    public static function realPath(ErrorEvent $e): ?string
    {
        $path = (string) $e->file;
        $real = $path !== '' ? @realpath($path) : false;
        if ($real === false) return null;
        $root = rtrim(@realpath(base_path()) ?: base_path(), '/');
        if (! str_starts_with($real, $root . '/') || str_starts_with($real, $root . '/.env')) return null;

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
