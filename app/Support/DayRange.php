<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * **ترشيحٌ بيومٍ يُبقي الفهرسَ حيّاً** — البندان #27 (PERF-11/12/13) و#33.
 *
 * ── **القياسُ أوّلاً، وقد قلب الدعوى مرّتين** ──
 *
 * `whereDate($col, $day)` تُنتج `DATE(col) = '…'`، ودالّةٌ على عمودٍ تمنع
 * استعمالَ فهرسِه. **لكنّ ذلك ليس صحيحاً في كلِّ موضع** — وهذا ما قِيس على
 * MariaDB 10.11 بـ`EXPLAIN`:
 *
 * | الموضع | `DATE(col)` | مدىً نصفُ مفتوح |
 * |---|---|---|
 * | عمودٌ **يسبقه تساوٍ** على صدرِ الفهرس (`attendance` بـ`emp_id`) | `type: ref` · `key: …uniq` | `type: ref` · `key: …uniq` |
 * | عمودٌ **يتصدّر** الترشيح (`contracts.date_end`) | `type: ALL` · `key: NULL` | `type: range` · `key: …index` |
 *
 * **فالمكسبُ في الصفِّ الثاني وحدَه.** حين يسبق العمودَ تساوٍ على صدرِ
 * الفهرس، يفتح المحرّكُ الفهرسَ بذلك التساوي ويُطبّق الدالّةَ شرطَ فهرسةٍ
 * على الصفوفِ الناجيةِ وحدَها — **فلا شيءَ يُكسَب، والتغييرُ مخاطرةٌ بلا ثمن**.
 *
 * وهذا يُصحّح بندَي السجلِّ معاً: #27 يشير إلى `ExecutionStats` حيث الدوالُّ
 * في `SELECT`/`GROUP BY` **لا في `WHERE` أصلاً**، و#33 يستشهد بسطرٍ
 * (`AlertEngine:211`) هو **سطرُ تعليق**.
 *
 * ── **ولماذا لا تُستبدَل بـ`where()` مساواةً؟** ──
 *
 * عمودٌ مُكاستٌ `'date'` في Eloquent **يُكتَب `Y-m-d H:i:s`** مهما كان
 * الكاست (`fromDateTime` يستعمل `getDateFormat()`). فعلى MySQL يقصّ نوعُ
 * `DATE` الوقتَ عند التخزين، وعلى SQLite — ولا إلزامَ نوعيَّ فيها — يبقى
 * `'2026-09-22 00:00:00'` نصّاً كما كُتب. **فمساواةٌ نصّيّةٌ بـ`'2026-09-22'`
 * تُخطئ الصفَّ على SQLite صامتةً**، وحزمةُ الاختباراتِ تعمل عليها.
 *
 * والمدى نصفُ المفتوح `>= اليوم AND < غد` يلتقط الصيغتَين معاً — **والحدود
 * تُبنى نصّاً** (`toDateString()`): تمريرُ `Carbon` يربطه المُنشئُ بصيغةِ
 * `Y-m-d H:i:s` فتعود الكسرةُ نفسُها من بابٍ آخر.
 */
final class DayRange
{
    /**
     * **يومٌ بعينِه** — `DATE(col) = $day` بلا دالّةٍ على العمود.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $q
     */
    public static function on($q, string $col, Carbon|string $day)
    {
        $from = self::day($day);

        return $q->where($col, '>=', $from)
                 ->where($col, '<', Carbon::parse($from)->addDay()->toDateString());
    }

    /**
     * **حتّى يومٍ شامِلاً إيّاه** — نظيرُ `whereDate($col, '<=', $day)`.
     *
     * والحدُّ الأعلى **غدُ ذلك اليوم حصراً** لا اليومُ نفسُه: عمودٌ يحمل
     * `'2026-09-22 14:30:00'` يقع ضمن «حتّى ٢٢ سبتمبر» ولا يقع تحت
     * `<= '2026-09-22'` نصّاً. **وهذه الكسرةُ بالذات هي ما يجعل الاستبدالَ
     * الساذجَ خطراً.**
     */
    public static function upto($q, string $col, Carbon|string $day)
    {
        return $q->where($col, '<', Carbon::parse(self::day($day))->addDay()->toDateString());
    }

    /**
     * **قبل يومٍ حصراً** — نظيرُ `whereDate($col, '<', $day)`.
     *
     * وهنا وحدَها يتطابق الحدُّ مع اليومِ نفسِه: `DATE(col) < X` تعني أنّ
     * كلَّ لحظاتِ ذلك اليومِ خارجةٌ أصلاً، و`col < 'X'` نصّاً تقول ذلك بعينِه.
     */
    public static function before($q, string $col, Carbon|string $day)
    {
        return $q->where($col, '<', self::day($day));
    }

    /** **منذ يومٍ شامِلاً إيّاه** — نظيرُ `whereDate($col, '>=', $day)` */
    public static function since($q, string $col, Carbon|string $day)
    {
        return $q->where($col, '>=', self::day($day));
    }

    /**
     * **مدىً بين يومَين، شامِلاً طرفَيه** — نظيرُ `whereDate` مرّتين.
     */
    public static function between($q, string $col, Carbon|string $from, Carbon|string $to)
    {
        return $q->where($col, '>=', self::day($from))
                 ->where($col, '<', Carbon::parse(self::day($to))->addDay()->toDateString());
    }

    /** **اليومُ نصّاً `Y-m-d`** — لا كائنَ زمنٍ يربطه المُنشئُ بالساعةِ والدقيقة */
    private static function day(Carbon|string $day): string
    {
        return $day instanceof Carbon ? $day->toDateString() : substr(trim($day), 0, 10);
    }
}
