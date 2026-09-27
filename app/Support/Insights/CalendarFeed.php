<?php

namespace App\Support\Insights;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * **التقويمُ الموحّد — القارئُ الواحد للسطحَين** (خطّة التطبيق 4.5). مُستخرَجٌ من
 * `CalendarController` كي يستدعيه الويبُ (شبكةٌ شهريّة) والجوالُ (`GET calendar?from=&to=`)
 * بالقاعدة نفسِها: كلُّ حقول التاريخ الجديرة بالتقويم عبر الوحدات، كلٌّ بـ`hub_can`
 * + `hub_scope` + **`hub_field_mode`** (حقلُ تاريخٍ محجوبٌ عن الدور لا يُمسَح، واسمُ
 * سجلٍّ محجوبٌ عمودُه لا يُعرَض)، والمخبأُ بالمستخدم وحدَه (v2.556 — ما نُطِّق
 * بالمستخدمِ يُخبَّأ بالمستخدم) مختوماً بختم الجداول المقروءة.
 */
final class CalendarFeed
{
    /** سقفُ صفوفِ كلِّ حقلٍ في النافذة، وسقفُ عناصرِ اليوم الواحد — والمحجوبُ يُعَدّ في `overflow` */
    public const PER_FIELD = 80;

    public const PER_DAY = 8;

    /** حقول التواريخ الجديرة بالتقويم: كل حقول date/dt عدا التأريخية البحتة (إنشاء/آخر لمس) */
    public static function fields(): array
    {
        $out = [];
        foreach (hub_modules() as $mk => $md) {
            foreach ($md['fields'] as $f) {
                if (! in_array($f['type'] ?? '', ['date', 'dt'], true)) continue;
                if (preg_match('/آخر|التسجيل|الإنشاء|الاكتشاف|التأسيس|الشراء|الفعلي/u', $f['label'])) continue;
                $out[] = [$mk, $f];
            }
        }

        return $out;
    }

    /**
     * عناصرُ التقويم في `[from, to]` (شاملاً) للمستخدم — `[$days, $overflow]` حيث
     * `$days[Y-m-d] = [{module, mlabel, label, field, name, id}]`.
     *
     * @param string $variant مميّزُ المخبأ لتضييقٍ إضافيٍّ يطبّقه `$narrow` (سياقُ الجوال مثلاً)
     * @param (\Closure(\Illuminate\Database\Query\Builder, string): mixed)|null $narrow تضييقُ عرضٍ فوق hub_scope
     * @return array{0: array<string, list<array<string,string>>>, 1: int}
     */
    public static function range(User $u, CarbonInterface $from, CarbonInterface $to, bool $fresh = false,
                                 string $variant = '', ?\Closure $narrow = null): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        $tables = array_values(array_unique(array_filter(array_map(
            fn ($x) => (string) (hub_mod($x[0])['table'] ?? ''), self::fields()))));
        $tables[] = 'roles';
        $ckey = 'hub:calendar:' . $from->toDateString() . ':' . $to->toDateString() . ':'
              . 'u:' . $u->getKey() . ':c:' . $variant . hub_data_stamp($tables);
        if ($fresh) Cache::forget($ckey);

        return Cache::remember($ckey, 300, function () use ($u, $from, $to, $narrow) {
            $days = [];
            $overflow = 0;
            foreach (self::fields() as [$mk, $f]) {
                if (! hub_can($u, $mk, 'v')) continue;
                // حقلُ التاريخ المحجوبُ عن الدور لا يُمسَح أصلاً — لا يُعرف منه حتى وجودُ السجلّ
                if (hub_field_mode($u, $mk, (string) $f['key']) === 'hide') continue;
                $md = hub_mod($mk);
                $disp = hub_display_col($mk);
                $dispKey = (string) (collect($md['fields'])->firstWhere('col', $disp)['key'] ?? '');
                $nameHidden = $dispKey !== '' && hub_field_mode($u, $mk, $dispKey) === 'hide';
                try {
                    $base = hub_scope(
                        // نطاقٌ على العمود الخام لا DATE(col): الدالة على العمود تُعمي الفهرس
                        DB::table($md['table'])->whereNull('deleted_at')->whereNotNull($f['col'])
                            ->where($f['col'], '>=', $from->toDateString())
                            ->where($f['col'], '<', $to->copy()->addDay()->toDateString()),
                        $mk, $u
                    );
                    if ($narrow) $narrow($base, $mk);
                    // نعدّ الكل ثم نجلب الأقرب زمنياً بترتيب صريح — والمحجوبُ يُصرَّح به في العدّاد
                    $total = (clone $base)->count();
                    $rows = (clone $base)->orderBy(DB::raw("DATE(`{$f['col']}`)"))->orderBy('id')
                        ->limit(self::PER_FIELD)->get(['id', $disp . ' as _n', DB::raw("DATE(`{$f['col']}`) as _d")]);
                    $overflow += max(0, $total - $rows->count());
                } catch (\Throwable $e) {
                    continue;
                }

                foreach ($rows as $row) {
                    $d = (string) $row->_d;
                    if (count($days[$d] ?? []) >= self::PER_DAY) { $overflow++; continue; }
                    $days[$d][] = [
                        'module' => $mk,
                        'mlabel' => $md['label'] ?? $mk,
                        'label'  => $f['label'],
                        'field'  => (string) $f['key'],
                        'name'   => $nameHidden ? '' : (string) ($row->_n ?? ''),
                        'id'     => (string) $row->id,
                    ];
                }
            }
            ksort($days);

            return [$days, $overflow];
        });
    }
}
