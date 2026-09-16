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
     * **أنواعُ المؤشّرات** — لأنّ «إعداداً كاملاً» ليس واحداً لكلِّ مؤشّر.
     *
     * نسبةٌ مئويّةٌ هدفُها بين صفرٍ ومئة وتُعرض بمنزلةٍ واحدةٍ ولاحقةِ «٪»؛
     * ومبلغٌ يُعرض بفواصلِ الآلافِ وعملةِ الأساس؛ وعدٌّ رقمٌ صحيحٌ باسمِ معدوده؛
     * ومتوسّطٌ بمنزلتين. وخلطُها في قالبٍ واحد هو ما جعل الشاشةَ تقرأ
     * «١٢٥٠٠٠ · الهدف ١٢٠٠٠٠» بلا عملةٍ ولا فاصلة.
     */
    public const KIND_PCT = 'pct';
    public const KIND_MONEY = 'money';
    public const KIND_AVG = 'avg';
    public const KIND_COUNT = 'count';

    public const KIND_LABEL = [
        self::KIND_PCT   => 'نسبة مئويّة',
        self::KIND_MONEY => 'مبلغ',
        self::KIND_AVG   => 'متوسّط',
        self::KIND_COUNT => 'عدّ',
    ];

    /**
     * نوعُ المؤشّرِ من معادلته — **مشتقٌّ لا مخزَّن**: عمودٌ ثالثٌ يحمل النوعَ
     * ينحرف عن المعادلةِ يومَ تُعدَّل المعادلةُ وحدَها، فيُعرض مبلغٌ بلاحقةِ «٪».
     */
    public static function kind(array $formula, ?string $unit = null): string
    {
        if (($formula['combine'] ?? 'none') === 'ratio_pct') return self::KIND_PCT;
        if (trim((string) $unit) === '٪') return self::KIND_PCT;

        $agg = hub_str($formula['a']['agg'] ?? 'count', 'count');
        if ($agg === 'avg') return self::KIND_AVG;
        if ($agg === 'count') return self::KIND_COUNT;

        // `sum` على عمودٍ ماليّ ⇒ مبلغ. والماليّةُ تُعرف من وحدةِ المقياس لا من اسمِ عموده:
        // سجلُّ الوحدات يُعلن `money` على الحقولِ المبلغيّة، وما عداها مجموعٌ عدديّ.
        return self::isMoney($formula['a'] ?? []) ? self::KIND_MONEY : self::KIND_COUNT;
    }

    /** أهذا المقياسُ مجموعُ عمودٍ ماليّ؟ — من سجلّ الوحدة لا من تخمينِ الاسم */
    protected static function isMoney($metric): bool
    {
        if (! is_array($metric)) return false;
        $def = hub_mod(hub_str($metric['module'] ?? ''));
        if (! $def) return false;

        $ck = hub_str($metric['col'] ?? '');
        if ($ck === '') return false;

        $col = collect($def['fields'])->firstWhere('key', $ck)
            ?: collect($def['fields'])->firstWhere('col', $ck);

        return (bool) ($col['money'] ?? false);
    }

    /**
     * **إعدادُ النوع** — الوحدةُ والدورةُ ومنازلُ العرض ومدى الهدف المقبول.
     *
     * دورةُ العدّ «لحظي» لا «شهري»: عدُّ المفتوحِ الآن **رصيدٌ قائم** يُقرأ
     * لحظةَ النظر، لا تدفّقٌ يُجمَع على شهر — وتسميتُه «شهريّاً» تَعِد بجمعٍ
     * لا يقع. والمجاميعُ والنسبُ تدفّقاتٌ فدورتُها شهريّة.
     *
     * @return array{unit: ?string, period: string, decimals: int, min: ?float, max: ?float}
     */
    public static function kindDefaults(string $kind): array
    {
        return match ($kind) {
            self::KIND_PCT => ['unit' => '٪', 'period' => 'شهري', 'decimals' => 1, 'min' => 0.0, 'max' => 100.0],
            self::KIND_MONEY => ['unit' => \App\Support\Currency::base(), 'period' => 'شهري',
                                 'decimals' => 0, 'min' => null, 'max' => null],
            self::KIND_AVG => ['unit' => null, 'period' => 'شهري', 'decimals' => 2, 'min' => null, 'max' => null],
            default => ['unit' => null, 'period' => 'لحظي', 'decimals' => 0, 'min' => 0.0, 'max' => null],
        };
    }

    /**
     * عرضُ قيمةٍ بنوعها — رقمٌ واحدٌ بمنازلَ واحدةٍ ووحدةٍ واحدة في كلِّ شاشة.
     * كان كلُّ سطحٍ يقصّ أصفارَه بطريقته فاختلف الرقمُ نفسُه بين بطاقةٍ وجدول.
     */
    public static function format($value, string $kind, ?string $unit = null): string
    {
        if ($value === null) return '—';

        $d = self::kindDefaults($kind)['decimals'];
        $txt = number_format((float) $value, $d);
        if ($d > 0) $txt = rtrim(rtrim($txt, '0'), '.');      // ٨٠٫٠ ⇒ ٨٠

        $u = trim((string) ($unit ?? self::kindDefaults($kind)['unit']));

        return $u === '' ? $txt : ($u === '٪' ? $txt . '٪' : $txt . ' ' . $u);
    }

    /**
     * **ما ينقص هذا المؤشّرَ ليكون مُعَدّاً** — قائمةُ نواقصَ تُقال لا حكمٌ صامت.
     *
     * «لا مؤشّرَ بلا إعداد» قاعدةٌ لا تُفرَض بالنيّة: شاشةٌ تعرض «—» في خانةِ
     * الهدفِ والدورةِ والمالك تبدو عاملةً وهي لا تُحاسِب أحداً على شيء.
     *
     * @return array<int, string>
     */
    public static function missingConfig(array $row): array
    {
        $gaps = [];

        if ($row['target'] === null) {
            // **والنقصُ يُقال بسببه**: «بلا هدف» على وحدةٍ لم يُسجَّل فيها شيءٌ بعدُ
            // ليس إهمالاً في الإعداد — لا شيءَ يُقاس منه خطُّ أساس. وخلطُهما
            // يجعل القائمةَ تطلب ما لا يُمكن فعلُه، فتُهمَل كلُّها.
            $v = $row['value'] ?? null;
            $gaps[] = ($v === null || abs((float) $v) < 0.000001)
                ? 'بلا هدف — ولا بياناتٍ بعدُ لقياس خطِّ أساس'
                : 'بلا هدف — يُضبط بخطِّ أساسٍ مقيس';
        }
        if (trim((string) ($row['unit'] ?? '')) === '' && ($row['kind'] ?? '') !== self::KIND_COUNT) {
            $gaps[] = 'بلا وحدة قياس';
        }
        if (trim((string) ($row['period'] ?? '')) === '') $gaps[] = 'بلا دورة';
        if ($row['target'] !== null && trim((string) ($row['target_basis'] ?? '')) === '') {
            $gaps[] = 'هدفٌ بلا نسب — لا يُعرف أمقيسٌ هو أم التزام';
        }

        return $gaps;
    }

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
        $hasBasis  = hub_has_col('kpi_defs', 'target_basis');

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
                // نوعُ المؤشّرِ وإعدادُه ونسبُ هدفه — بها تُعرض القيمةُ بوحدتها
                // وتُعرف مصادرُ الهدف، وبها تُحسب «ما ينقصه ليكون مُعَدّاً»
                'kind' => $kind = self::kind($formula, $k->unit),
                'kind_label' => self::KIND_LABEL[$kind] ?? '',
                'shown' => self::format($value, $kind, $k->unit),
                'target_shown' => self::format($target, $kind, $k->unit),
                'target_basis' => $hasBasis ? $k->target_basis : null,
                'target_note' => $hasBasis ? $k->target_note : null,
                'baseline_at' => $hasBasis ? $k->baseline_at : null,
                'delta' => $delta,
                'variance' => $variance,
                'variance_pct' => $variancePct,
                'dead' => $dead,
                'health' => self::health($value, $target, $dead, (string) ($c['tone'] ?? '')),
                'trend' => self::trend($series[$k->id][self::METRIC] ?? []),
            ];
            // «لا مؤشّرَ بلا إعداد»: النواقصُ تُحسب بعد اكتمال الصفّ فتقرأ حقولَه كلَّها
            $out[count($out) - 1]['missing'] = self::missingConfig($out[count($out) - 1]);
            // **المالكُ قرارُ إنسانٍ لا حقلٌ يُملأ آليّاً** — فلا يُخلَط بنواقصِ
            // الإعدادِ التي يسدّها أمرٌ واحد، ويُعَدّ على حدة كي لا يُغرِق القائمة
            $out[count($out) - 1]['needs_owner'] = ($k->owner_id ?? null) === null;
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
