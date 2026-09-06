<?php

namespace App\Support;

/**
 * النسبُ المئوية (p50/p95/p99) من مدرَّجٍ لوغاريتميّ — القرار ق٥ في الخطة:
 * لا تخزينَ لمدّة كلِّ طلبٍ إلى الأبد. الدلوُ i يغطّي [GROWTH^i, GROWTH^(i+1))
 * وقيمتُه الممثِّلة وسطُه الهندسيّ، فالخطأُ النسبيّ محصورٌ بـ√GROWTH−1 — تقريبٌ
 * **مُعلَنٌ** تُوسَم به الشاشات (spec §37: «Do not fake percentiles»).
 * الصيغةُ المخزَّنة (عمود hist في http_metric_buckets — الطور ٢): خريطةُ
 * {رقم الدلو ⇒ العدّ}؛ ومفاتيحُ JSON قد تصل نصوصاً فتُطبَّع أعداداً هنا.
 */
class Series
{
    /** معامل نموّ الدلاء — دلوٌ كلَّ ~١٥٪ من القيمة (توازنُ دقّةٍ وحجمِ تخزين) */
    public const GROWTH = 1.15;

    /** حدُّ الخطأ النسبيّ المُعلَن: √1.15 − 1 ≈ 0.0724 — يُعرَض «±7.5٪» */
    public const MAX_REL_ERROR = 0.075;

    /** دلوُ قيمةٍ (مدّةٌ بالمللي ثانية مثلاً) — ما دون 1 يُقصّ للدلو 0 عمداً */
    public static function bucket(float $v): int
    {
        return $v < 1.0 ? 0 : (int) floor(log($v) / log(self::GROWTH));
    }

    /** القيمةُ الممثِّلة للدلو — الوسطُ الهندسيّ لمداه */
    public static function mid(int $i): float
    {
        return pow(self::GROWTH, $i + 0.5);
    }

    /** بناءُ مدرَّجٍ من قيمٍ خام — سكّةُ كتّاب الدلاء (الطور ٢) والاختبارات */
    public static function hist(array $values): array
    {
        $h = [];
        foreach ($values as $v) {
            $i = self::bucket((float) $v);
            $h[$i] = ($h[$i] ?? 0) + 1;
        }
        ksort($h);
        return $h;
    }

    /** دمجُ مدرَّجين (دلاءُ نوافذَ زمنيةٍ متجاورة) — جمعُ الأعداد دلواً بدلو */
    public static function mergeHist(array $a, array $b): array
    {
        $out = [];
        foreach ([$a, $b] as $h) {
            foreach ($h as $i => $n) {
                $n = (int) $n;
                if ($n <= 0) continue;
                $out[(int) $i] = ($out[(int) $i] ?? 0) + $n;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * النسبُ المئوية من مدرَّج بطريقة الرتبة الأقرب (nearest-rank): جوابُ p هو
     * ممثِّلُ الدلو الحاوي للعنصر ⌈n·p/100⌉ — والعنصرُ الدقيق في الدلو نفسِه،
     * فالخطأُ محصورٌ بـMAX_REL_ERROR. مدرَّجٌ فارغ ⇒ null لا صفرٌ مُختلَق.
     * يعيد ['p50' => float|null, 'p95' => ..., 'p99' => ...] بحسب $p.
     */
    public static function percentiles(array $hist, array $p = [50, 95, 99]): array
    {
        $h = self::mergeHist($hist, []);   // تطبيعُ المفاتيح والأعداد والترتيب
        $total = array_sum($h);
        $out = [];
        foreach ($p as $pct) {
            if ($total < 1) { $out['p' . $pct] = null; continue; }
            $rank = max(1, (int) ceil($total * $pct / 100));
            $cum = 0;
            $val = null;
            foreach ($h as $i => $n) {
                $cum += $n;
                if ($cum >= $rank) { $val = self::mid((int) $i); break; }
            }
            $out['p' . $pct] = $val;
        }
        return $out;
    }
}
