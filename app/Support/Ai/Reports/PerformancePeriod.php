<?php

namespace App\Support\Ai\Reports;

use App\Support\Platform\BusinessDate;
use Illuminate\Support\Carbon;

/**
 * **فترةُ تقرير الأداء** — شهرٌ (`2026-09`) أو أسبوعٌ ISO (`2026-W39`)، بتوقيت المنشأة.
 *
 * مفتاحُ الفترة نصٌّ ثابتٌ يُخزَّن ويُعرَض ويُمرَّر في الرابط؛ وكلُّ مفتاحٍ لا يُفهَم يُرفض (`null`)
 * لا يُخمَّن — فمعاملُ رابطٍ مصنوعٌ باليد لا يُسقط صفحةً ولا يولّد فترةً مختلَقة.
 */
final class PerformancePeriod
{
    public const MONTH = 'month';

    public const WEEK = 'week';

    /** نوعُ الفترة من الإعداد — وأيُّ قيمةٍ سوى `week` تعني الشهر */
    public static function kind(): string
    {
        return (string) setting('hr.performance_period', 'month') === self::WEEK ? self::WEEK : self::MONTH;
    }

    /**
     * الفترةُ التي تحوي تاريخاً ('Y-m-d') — بالنوع المعطى.
     *
     * @return array{kind:string, key:string, from:string, to:string}
     */
    public static function containing(string $date, ?string $kind = null): array
    {
        $kind ??= self::kind();
        $d = Carbon::parse($date, BusinessDate::tz());
        if ($kind === self::WEEK) {
            $from = $d->copy()->startOfWeek(Carbon::MONDAY);

            return ['kind' => self::WEEK, 'key' => $from->format('o-\WW'), 'from' => $from->toDateString(),
                'to' => $from->copy()->addDays(6)->toDateString()];
        }
        $from = $d->copy()->startOfMonth();

        return ['kind' => self::MONTH, 'key' => $from->format('Y-m'), 'from' => $from->toDateString(),
            'to' => $from->copy()->endOfMonth()->toDateString()];
    }

    /** الفترةُ الجارية (تحوي اليوم) */
    public static function current(?string $kind = null): array
    {
        return self::containing(BusinessDate::today(), $kind);
    }

    /** آخرُ فترةٍ **مكتملة** — التي تسبق الجارية مباشرةً */
    public static function previous(?string $kind = null): array
    {
        $cur = self::current($kind);

        return self::containing(Carbon::parse($cur['from'], BusinessDate::tz())->subDay()->toDateString(), $cur['kind']);
    }

    /**
     * مفتاحٌ ⇒ فترة، أو `null` لما لا يُفهَم (أو لفترةٍ مستقبليّة).
     *
     * @return array{kind:string, key:string, from:string, to:string}|null
     */
    public static function parse(?string $key): ?array
    {
        $key = trim((string) $key);
        try {
            if (preg_match('/^(\d{4})-(\d{2})$/', $key, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
                $p = self::containing(sprintf('%04d-%02d-01', (int) $m[1], (int) $m[2]), self::MONTH);
            } elseif (preg_match('/^(\d{4})-W(\d{2})$/', $key, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 53) {
                $d = Carbon::now(BusinessDate::tz())->setISODate((int) $m[1], (int) $m[2], 1);
                $p = self::containing($d->toDateString(), self::WEEK);
                if ($p['key'] !== $key) return null;   // الأسبوعُ ٥٣ في سنةٍ لا تملكه
            } else {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $p['from'] > BusinessDate::today() ? null : $p;
    }

    /** أمكتملةٌ الفترة؟ (انتهى آخرُ أيّامها قبل اليوم) */
    public static function complete(array $p): bool
    {
        return $p['to'] < BusinessDate::today();
    }

    /**
     * آخرُ يومٍ تشمله الحقائق: آخرُ الفترة إن اكتملت، وإلّا **أمس** — فاليومُ الجاري لم ينتهِ ولا يُحكم عليه.
     * و`null` إن لم يمضِ من الفترة يومٌ كامل بعد.
     */
    public static function asOf(array $p): ?string
    {
        if (self::complete($p)) return $p['to'];
        $y = BusinessDate::yesterday();

        return $y >= $p['from'] ? $y : null;
    }

    /** التسميةُ العربيّة */
    public static function label(array $p): string
    {
        return $p['kind'] === self::WEEK
            ? 'أسبوع ' . $p['key'] . ' (' . $p['from'] . ' ← ' . $p['to'] . ')'
            : 'شهر ' . $p['key'];
    }
}
