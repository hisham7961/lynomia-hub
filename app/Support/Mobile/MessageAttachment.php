<?php

namespace App\Support\Mobile;

use Illuminate\Support\Facades\Storage;

/**
 * **مرفقُ رسالةٍ/تعليقٍ على الجوال** (طلب الجوال #2 · إضافيّ).
 *
 * مرفقُ التعليق والـDM مسارٌ في عمود `att` (رِكازُ الويب: `store('hub','local')`) لا صفٌّ في
 * `attachments` — فلا معرّفَ مرفقٍ له. **المقبضُ القابلُ للتنزيل هو معرّفُ الرسالة/التعليق
 * نفسِه**، والبايتاتُ خلفَ نقطةٍ مُصادَقةٍ تعيد حارسَ الرؤية (`comments/{id}/attachment`،
 * `dm/messages/{id}/attachment`) — لا مسارَ قرصٍ يُكشَف ولا رابطٌ عامّ.
 */
final class MessageAttachment
{
    /**
     * بطاقةُ المرفق `{id, name, size, mime, download}` — أو null بلا مرفق/ملفٍّ مفقود.
     * `name` اسمُ التخزين (الاسمُ الأصليُّ لا يُحفَظ في هذا الرِّكاز) — صادقٌ لا مُختلَق.
     */
    public static function shape(?string $path, string $ownerId, string $routeName): ?array
    {
        $abs = self::absolute($path);
        if ($abs === null) return null;

        return [
            'id' => $ownerId,
            'name' => basename((string) $path),
            'size' => (int) (@filesize($abs) ?: 0),
            'mime' => (string) (@mime_content_type($abs) ?: 'application/octet-stream'),
            'download' => route($routeName, ['id' => $ownerId], false),
        ];
    }

    /** المسارُ المطلق داخل `hub/` وحدَه (لا صعودَ مسارات) — أو null */
    public static function absolute(?string $path): ?string
    {
        $path = (string) $path;
        if ($path === '' || ! str_starts_with($path, 'hub/') || str_contains($path, '..')) return null;

        $abs = Storage::disk('local')->path($path);

        return is_file($abs) ? $abs : null;
    }

    /**
     * ردُّ التنزيل بعد التخويل — `Content-Disposition: attachment` + `nosniff` (فملفُ
     * HTML/SVG مرفوعٌ لا يُنفَّذ). ٤٠٤ حين غاب الملفّ عن القرص.
     */
    public static function download(?string $path): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $abs = self::absolute($path);
        abort_if($abs === null, 404, 'المرفق غير موجود');

        return response()->download($abs, basename((string) $path), [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
