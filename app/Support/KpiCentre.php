<?php

namespace App\Support;

use App\Models\KpiDef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مركزُ المؤشّرات (WP-8.5 · spec §6.9 · §47) — **قراءةٌ لا محرّكٌ ثانٍ**.
 *
 * القيمةُ تبقى من `hub_kpi_value` وحدَه (المحرّك القائم الذي يحترم `hub_scope`
 * و`hub_can`)، وهذا الصنفُ يضيف فوقها ما تطلبه المواصفة ولا يملكه أحد:
 * مالكاً ودورةً وانحرافاً واتّجاهاً وحالةً صحّية — وقائمةَ «خارج الهدف».
 *
 * ولماذا صنفٌ ثالث بدل توسيع `hub_kpis`؟ لأنّ `helpers.php` مِلكُ الطور الأول
 * (§٠.٤) ولأنّ الشاشاتِ القائمة تستهلك شكلَ `hub_kpis` كما هو — فالتوسيعُ هنا
 * **يستهلكه ولا يكرّره**: القيمةُ والنبرةُ والشرحُ تُقرأ منه حرفياً.
 *
 * ═══ العيبُ الذي يُغلقه هذا الصنف ═══
 * مؤشّرٌ يُرشِّح حالةً **لا وجودَ لها في السجل** يقرأ صفراً أبداً — وصفرٌ مقابل
 * هدفٍ صفرٍ باتّجاه «الأقلّ أفضل» كان يُعرض **«على الهدف»**. فالنظامُ يهنّئ
 * نفسَه على قياسٍ لم يقع. هنا: الفلترُ الميّت يُرصد ويُسمّى، والحالةُ الصحّية
 * تصير `dead` — لا «على الهدف» ولا «خارج الهدف»، بل **«لا يُقاس»**.
 */
class KpiCentre
{
    /** مفتاحُ السلسلة الزمنية للمؤشّرات في `metric_points` (§6.9) */
    public const MODULE = 'kpis';
    public const METRIC = 'value';

    /**
     * حزامُ «تحذير» حول الهدف — **نفسُ عتبة `hub_kpis`** حرفياً (٨٠٪ صعوداً،
     * ١٢٠٪ نزولاً)، فلا عتبتان لشيءٍ واحد. النبرةُ تُقرأ من هناك لا تُحسب هنا.
     */
    public const NEAR_BAND = 0.20;

    /** مدى قراءة الاتّجاه بالأيام — مطابقٌ لمدى `DataQuality::history` */
    public const TREND_DAYS = 60;

    /** ترجمةُ الحالة الصحّية إلى العربية — مفرداتٌ واحدةٌ للشاشة والاختبار */
    public const HEALTH = [
        'on'       => 'على الهدف',
        'warn'     => 'تحذير',
        'off'      => 'خارج الهدف',
        'dead'     => 'لا يُقاس — فلترٌ لا يطابق السجل',
        'nodata'   => 'لا يُحسب',
        'notarget' => 'بلا هدف',
    ];

    /**
     * الفلاترُ الميّتة في معادلة: حالةٌ مطلوبةٌ لا يعرفها سجلُّ وحدتها.
     *
     * صنفان يُرصدان معاً لأنّ أثرَهما واحد (رقمٌ كاذب):
     *  • حالةٌ **خارج خيارات** الوحدة ⇒ العدُّ صفرٌ أبداً.
     *  • حالةٌ على وحدةٍ **بلا حقل حالة** أصلاً ⇒ الفلترُ يسقط صامتاً فتُعدّ
     *    كلُّ السجلات تحت اسمٍ يعد بالتصفية (وحدةُ الموردين مثالٌ حيّ).
     *
     * @return array<int, array{side:string, module:string, label:string, status:string, why:string}>
     */
    public static function deadFilters(array $formula): array
    {
        $out = [];
        foreach (['a', 'b'] as $side) {
            $m = $formula[$side] ?? null;
            if (! is_array($m)) continue;

            $st = trim(hub_str($m['st'] ?? ''));
            if ($st === '') continue;

            $mk = hub_str($m['module'] ?? '');
            $def = hub_mod($mk);
            if (! $def) continue;                       // وحدةٌ مجهولة: `hub_kpi_value` يردّ null أصلاً

            $skey = (string) ($def['status'] ?? '');
            $opts = $skey
                ? (array) (collect($def['fields'])->firstWhere('key', $skey)['options'] ?? [])
                : [];

            if ($skey === '') {
                $out[] = ['side' => $side, 'module' => $mk, 'label' => (string) $def['label'],
                          'status' => $st,
                          'why' => 'الوحدة بلا حقل حالة — الفلتر يسقط صامتاً فتُعدّ كل السجلات'];
                continue;
            }
            if ($opts && ! in_array($st, $opts, true)) {
                $out[] = ['side' => $side, 'module' => $mk, 'label' => (string) $def['label'],
                          'status' => $st,
                          'why' => 'حالةٌ خارج خيارات الوحدة — العدّ صفرٌ أبداً'];
            }
        }

        return $out;
    }

    /**
     * صفوفُ المركز — مرتّبةً ترتيباً حتميّاً (`sort` ثم `id`؛ `created_at`
     * بدقّة الثانية قرعةٌ بين المحرّكين، انظر CLAUDE.md).
     *
     * القيمةُ والنبرةُ والشرح من `hub_kpis` حرفياً؛ والمالكُ والدورةُ والانحرافُ
     * والاتّجاهُ والحالةُ الصحّية تُضاف هنا. واستعلاماتُ الاتّجاه **واحدةٌ
     * للجميع** (`hub_metric_bulk_series`) لا واحدٌ لكل صفّ.
     */
    public static function rows($user = null, bool $withHidden = true): array
    {
        if (! Schema::hasTable('kpi_defs')) return [];
        $user = $user ?? auth()->user();

        $hasActive = hub_has_col('kpi_defs', 'active');
        $hasOwner  = hub_has_col('kpi_defs', 'owner_id');
        $hasPeriod = hub_has_col('kpi_defs', 'period');

        $defs = KpiDef::when($hasActive && ! $withHidden, fn ($q) => $q->where('active', true))
            ->orderBy('sort')->orderBy('id')->get();
        if ($defs->isEmpty()) return [];

        // القيمةُ من المحرّك الواحد — نداءٌ واحدٌ لكل المؤشّرات ثم مطابقةٌ بالمعرّف
        $computed = collect(hub_kpis($user, true))->keyBy('id');

        $ownerNames = [];
        if ($hasOwner) {
            $ids = $defs->pluck('owner_id')->filter()->unique()->values()->all();
            if ($ids) {
                $ownerNames = DB::table('users')->whereIn('id', $ids)
                    ->orderBy('id')->pluck('name', 'id')->all();
            }
        }

        $series = hub_metric_bulk_series(self::MODULE, $defs->pluck('id')->all(),
            [self::METRIC], self::TREND_DAYS);

        $out = [];
        foreach ($defs as $k) {
            $formula = (array) $k->formula;
            $c = (array) ($computed[$k->id] ?? []);
            $value = $c['value'] ?? null;
            $target = $k->target === null ? null : (float) $k->target;
            $good = ($k->good ?? 'up') === 'down' ? 'down' : 'up';

            $dead = self::deadFilters($formula);

            // الفرقُ الخام ثم **الانحرافُ بإشارة الاتجاه**: تحت الهدف سيّئٌ
            // صعوداً وحسنٌ نزولاً — والطرحُ وحده يقول العكسَ في نصف الحالات
            $delta = ($value !== null && $target !== null) ? round((float) $value - $target, 4) : null;
            $variance = $delta === null ? null : ($good === 'up' ? $delta : -$delta);
            $variancePct = ($variance !== null && $target !== null && abs($target) > 0.000001)
                ? round($variance * 100 / abs($target), 1) : null;

            $out[] = [
                'id' => $k->id,
                'name' => $k->name,
                'unit' => $k->unit,
                'value' => $value,
                'target' => $target,
                'good' => $good,
                'active' => $hasActive ? (bool) $k->active : true,
                'tone' => (string) ($c['tone'] ?? ''),
                'explain' => (string) ($c['explain'] ?? hub_kpi_explain($formula)),
                'owner_id' => $hasOwner ? $k->owner_id : null,
                'owner' => $hasOwner && $k->owner_id ? ($ownerNames[$k->owner_id] ?? null) : null,
                'period' => $hasPeriod ? $k->period : null,
                'delta' => $delta,
                'variance' => $variance,
                'variance_pct' => $variancePct,
                'dead' => $dead,
                'health' => self::health($value, $target, $dead, (string) ($c['tone'] ?? '')),
                'trend' => self::trend($series[$k->id][self::METRIC] ?? []),
            ];
        }

        return $out;
    }

    /**
     * الحالةُ الصحّية — بترتيبِ صدقٍ لا بترتيبِ تفاؤل:
     *  ١) فلترٌ ميّت ⇒ `dead` **قبل أي حكم** (وإلا هنّأ النظامُ نفسَه بصفرٍ كاذب).
     *  ٢) لا قيمة ⇒ `nodata`. ٣) لا هدف ⇒ `notarget` (لا هدفَ مخترَع).
     *  ٤) وإلا فنبرةُ `hub_kpis` كما هي: ok ⇒ على الهدف · wn ⇒ تحذير · bad ⇒ خارج الهدف.
     */
    public static function health($value, ?float $target, array $dead, string $tone): string
    {
        if ($dead) return 'dead';
        if ($value === null) return 'nodata';
        if ($target === null) return 'notarget';

        return match ($tone) {
            'ok' => 'on',
            'wn' => 'warn',
            'bad' => 'off',
            default => 'notarget',
        };
    }

    /**
     * الاتّجاه من السلسلة اليومية. **نقطةٌ واحدةٌ لا تصنع اتّجاهاً**: `delta`
     * تبقى null ولا يُرسم خطُّ صفرٍ كاذب (نفسُ قاعدة `DataQuality::moduleTrend`).
     */
    public static function trend(array $points): array
    {
        $out = ['points' => count($points), 'first' => null, 'last' => null,
                'delta' => null, 'dir' => null, 'series' => $points];
        if (count($points) < 1) return $out;

        $out['first'] = (float) $points[0]['value'];
        $out['last'] = (float) $points[count($points) - 1]['value'];
        if (count($points) < 2) return $out;

        $d = round($out['last'] - $out['first'], 4);
        $out['delta'] = $d;
        $out['dir'] = $d > 0 ? 'up' : ($d < 0 ? 'down' : 'flat');

        return $out;
    }

    /** المؤشّراتُ خارج الهدف (§47) — وما لا يُقاس معها، فالعمى ليس نجاحاً */
    public static function offTarget(array $rows): array
    {
        return array_values(array_filter($rows,
            fn ($r) => ($r['active'] ?? true) && in_array($r['health'], ['off', 'dead'], true)));
    }

    /** المؤشّراتُ على هدفها — للعدّ ولاختبار أنّ الميّتَ ليس منها */
    public static function onTarget(array $rows): array
    {
        return array_values(array_filter($rows,
            fn ($r) => ($r['active'] ?? true) && $r['health'] === 'on'));
    }

    /** ملخّصُ الصحّة عدداً — كلُّ مؤشّرٍ في خانةٍ واحدة، والمجموعُ هو العدد */
    public static function summary(array $rows): array
    {
        $live = array_filter($rows, fn ($r) => $r['active'] ?? true);
        $out = ['total' => count($live)] + array_fill_keys(array_keys(self::HEALTH), 0);
        foreach ($live as $r) $out[$r['health']] = ($out[$r['health']] ?? 0) + 1;

        return $out;
    }

    /**
     * لقطةٌ يومية لكل مؤشّر (§6.9): `('kpis', <id>, 'value')`.
     *
     * تُكتب في **سياق النظام** (بلا مستخدم) فالرقمُ رقمُ المنشأة لا رقمُ من فتح
     * الشاشة — كما تفعل `ExecutionStats::snapshot`. و`hub_metric_put` مفتاحُها
     * (وحدة، سجل، مقياس، لحظة) فإعادةُ التشغيل في اليوم نفسِه **تُحدِّث ولا
     * تكرّر**. والقيمةُ `null` لا تُكتب: «لا قياس» ليس صفراً.
     */
    public static function snapshot(?string $at = null): int
    {
        if (! Schema::hasTable('kpi_defs') || ! Schema::hasTable('metric_points')) return 0;

        $t = $at ? \Illuminate\Support\Carbon::parse($at)->startOfDay() : now()->startOfDay();
        $hasActive = hub_has_col('kpi_defs', 'active');
        $n = 0;

        // الترقيمُ بالمعرّف وحدَه: `chunkById` يرشّح بـ`id > الأخير`، فترتيبٌ
        // سابقٌ عليه (`sort`) يجعل الصفحةَ التالية تقفز فوق صفوفٍ لم تُكتب.
        // وترتيبُ الكتابة لا معنى له هنا — كلُّ نقطةٍ مفتاحُها سجلُّها.
        KpiDef::when($hasActive, fn ($q) => $q->where('active', true))
            ->chunkById(100, function ($chunk) use ($t, &$n) {
                foreach ($chunk as $k) {
                    $v = hub_kpi_value((array) $k->formula);      // سياقُ النظام: بلا مستخدم
                    if ($v === null) continue;
                    hub_metric_put(self::MODULE, $k->id, self::METRIC, (float) $v, $t, 'auto');
                    $n++;
                }
            }, 'id');

        return $n;
    }
}
