<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * **مُحلِّلُ تاريخِ العمل — يومُ المنشأة الواحد، بلا تناثر.**
 *
 * كان «اليوم» يُشتقّ في مواضعَ متفرّقةٍ بـ`now()->toDateString()` و`created_at`
 * وتاريخِ الخادم — كلٌّ صحيحٌ صدفةً ما دام التوقيتُ واحداً. هنا مصدرٌ واحد (§48):
 * التاريخُ التجاريُّ يُحسَب دائماً بتوقيتِ المنشأة (`app.timezone` = آسيا/الكويت،
 * بلا توقيتٍ صيفيّ)، فتقريرُ الساعةِ 23:58 يقع في يومِ حضورِه لا في غدٍ مخزَّنٍ
 * بـUTC (§49/§50).
 *
 * لا محرّكَ وقتٍ ثانٍ: `work_date` في `work_updates` و`date` في `attendance` كلاهما
 * يُملأ من هذا الأساس المحلّيّ نفسِه — فالمطابقةُ حتميّةٌ لا قرعةَ منطقةٍ زمنيّة.
 */
class BusinessDate
{
    /** منطقةُ عملِ المنشأة — مصدرٌ واحد */
    public static function tz(): string
    {
        return (string) config('app.timezone', 'Asia/Kuwait');
    }

    /** اللحظةُ الآن بتوقيتِ المنشأة */
    public static function now(): Carbon
    {
        return Carbon::now(self::tz());
    }

    /** تاريخُ اليومِ التجاريّ (Y-m-d) */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** تاريخُ الأمسِ التجاريّ (Y-m-d) — لكنسِ نهايةِ اليوم والمصالحة */
    public static function yesterday(): string
    {
        return self::now()->subDay()->toDateString();
    }

    /**
     * التاريخُ التجاريُّ لأيّ لحظةٍ (Y-m-d) — تُحوَّل إلى توقيتِ المنشأة أولاً.
     * فقيمةٌ مخزَّنةٌ بـUTC قربَ منتصفِ الليل تُنسَب ليومِها التجاريِّ الصحيح.
     */
    public static function of($dt): ?string
    {
        if ($dt === null || $dt === '') return null;
        try {
            return Carbon::parse($dt)->timezone(self::tz())->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * لحظةٌ تُبنى من تاريخِ عملٍ ووقتٍ نصّيّ (H:i) بتوقيتِ المنشأة — لمهلةِ التقرير.
     * وقتٌ فارغٌ ⇒ null.
     */
    public static function at(string $date, ?string $time): ?Carbon
    {
        $time = trim((string) $time);
        if ($time === '') return null;
        try {
            [$h, $m] = array_map('intval', array_pad(explode(':', $time), 2, 0));
            return Carbon::parse($date, self::tz())->setTime(max(0, min(23, $h)), max(0, min(59, $m)));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
