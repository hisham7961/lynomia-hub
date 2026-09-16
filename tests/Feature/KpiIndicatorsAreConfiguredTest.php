<?php

namespace Tests\Feature;

use App\Models\KpiDef;
use App\Support\KpiCentre;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * **لا مؤشّرَ بلا إعداد** (قرارُ المالك · بعد محاكاةِ الشهر).
 *
 * مكتبةُ الانطلاق كانت تبذر ستّةً وعشرين مؤشّراً **بلا دورةٍ ولا وحدةٍ لكثيرٍ
 * منها**، وعمودُ `period` مضافٌ منذ الطور الثامن ولم يُملأ منه واحد. فتقرأ
 * الشاشةُ «١٢٥٠٠٠ · الدورة —»: رقمٌ لا يُعرف أدينارٌ هو أم تذكرة، ولا أرصيدٌ
 * قائمٌ هو («كم المفتوحُ الآن») أم تدفّقٌ مجموعٌ على شهر. ولوحةٌ كهذه تُقرأ
 * ولا تُقرَّر بها.
 *
 * **والهدفُ أخطرُ من الوحدة:** رقمٌ صامتٌ لا يقول أمقيسٌ من بياناتك هو
 * (خطُّ أساس) أم التزامٌ أُقرّ في مجلس أم رقمٌ كتبه أحدٌ من رأسه — والثلاثةُ
 * تُقرأ سواءً، فيُحاسَب فريقٌ على ما لم يلتزم به أحد. فصار لكلِّ هدفٍ **نسب**.
 *
 * وهذا الحارسُ يمرّ على **كلِّ** ما تبذره المكتبة — لا على عيّنةٍ منه.
 */
class KpiIndicatorsAreConfiguredTest extends TestCase
{
    /** يبذر المكتبةَ كاملةً ويُعيد ما وُلد — وحارسُ ألّا يقيس المسحُ فراغاً معه */
    private function seedLibrary(): \Illuminate\Support\Collection
    {
        Artisan::call('hub:kpis-starter', ['--all' => true]);
        $all = KpiDef::orderBy('sort')->orderBy('id')->get();

        $this->assertGreaterThan(20, $all->count(),
            'لم تُبذَر مكتبةٌ يُعتدّ بها — الحارسُ يمرّ على فراغ');

        return $all;
    }

    public function test_every_seeded_indicator_carries_its_unit_and_period_and_direction(): void
    {
        $this->seedCore();

        foreach ($this->seedLibrary() as $k) {
            $kind = KpiCentre::kind((array) $k->formula, $k->unit);

            $this->assertContains($k->good, ['up', 'down'], "«{$k->name}» بلا اتّجاه");
            $this->assertNotSame('', trim((string) $k->period),
                "«{$k->name}» بلا دورة — لا يُعرف أرصيدٌ قائمٌ هو أم تدفّقٌ على مدّة");

            if ($kind !== KpiCentre::KIND_COUNT) {
                $this->assertNotSame('', trim((string) $k->unit),
                    "«{$k->name}» ({$kind}) بلا وحدةِ قياس — الرقمُ يُقرأ عارياً");
            }
        }
    }

    /** ونوعُ كلِّ مؤشّرٍ يُستخرج من معادلته — فالنسبةُ «٪» والمبلغُ بعملةِ الأساس */
    public function test_the_type_of_each_indicator_matches_its_formula(): void
    {
        $this->seedCore();
        $seen = [];

        foreach ($this->seedLibrary() as $k) {
            $kind = KpiCentre::kind((array) $k->formula, $k->unit);
            $seen[$kind] = true;

            if ($kind === KpiCentre::KIND_PCT) {
                $this->assertSame('٪', $k->unit, "«{$k->name}» نسبةٌ ووحدتُها ليست ٪");
            }
            if ($kind === KpiCentre::KIND_MONEY) {
                $this->assertSame(\App\Support\Currency::base(), $k->unit,
                    "«{$k->name}» مبلغٌ ووحدتُه ليست عملةَ الأساس");
            }
        }

        $this->assertArrayHasKey(KpiCentre::KIND_PCT, $seen, 'لا نسبةَ في المكتبة — الحارسُ لم يمسّ فرعَ النسب');
        $this->assertArrayHasKey(KpiCentre::KIND_MONEY, $seen, 'ولا مبلغَ — ولا فرعَ المبالغ');
        $this->assertArrayHasKey(KpiCentre::KIND_COUNT, $seen);
    }

    /**
     * **وكلُّ مؤشّرٍ في المكتبةِ يُحسَب فعلاً** — لا صفَّ يُعرَض «لا تُحسب» أبداً.
     *
     * `hub_kpi_metric` يُطابق عمودَ المجموع/المتوسط بـ`key` وحدَه، وصفُّ
     * «الكلفة الشهرية للسيرفرات» كان يمرّر `cost_month` (اسمَ العمود) فيعود null
     * منذ بذره. وحارسُ البذرِ كان يقبل الصورتين فأجاز صفّاً لا يُحسب أبداً.
     */
    public function test_every_seeded_indicator_actually_computes(): void
    {
        $this->seedCore();
        $mute = []; $checked = 0;

        foreach ($this->seedLibrary() as $k) {
            foreach (['a', 'b'] as $side) {
                $m = ((array) $k->formula)[$side] ?? null;
                if (! is_array($m) || ! in_array(hub_str($m['agg'] ?? ''), ['sum', 'avg'], true)) continue;

                $checked++;
                $def = hub_mod(hub_str($m['module'] ?? ''));
                // **بالمفتاح وحدَه** — كما يُطابق `hub_kpi_metric` حرفيّاً
                $col = $def ? collect($def['fields'])->firstWhere('key', hub_str($m['col'] ?? '')) : null;

                if (! $col || ! in_array($col['type'] ?? '', ['num', 'big'], true)) {
                    $mute[] = $k->name . ' (' . $side . ':' . hub_str($m['col'] ?? '—') . ')';
                }
            }
        }

        $this->assertGreaterThan(5, $checked, 'لا مقياسَ مجموعٍ في المكتبة — الحارسُ لم يمسّ شيئاً');
        $this->assertSame([], $mute,
            'مقاييسُ مجموعٍ بعمودٍ لا يعرفه سجلُّ وحدته — تُقرأ «لا تُحسب» أبداً: ' . implode('، ', $mute));

        /*
         * **والوجهُ الثاني للعيبِ نفسِه: السقوطُ الصامت.** حارسُ البذر (`usable`)
         * يتخطّى صفّاً عمودُه لا يُطابق مفتاحاً — فتصحيحُ الحارسِ وحدَه يحوّل
         * «مؤشّرٌ لا يُحسب» إلى **مؤشّرٌ لا يُوجد**، وكلاهما فقدٌ صامت. فيُطلَب
         * صراحةً أن يكون كلُّ صفٍّ مُعلَنٍ وحدتُه مثبَّتةٌ **موجوداً بعد البذر**.
         */
        $declared = [];
        foreach (['CORE', 'MORE'] as $lib) {
            $rows = (new \ReflectionClass(\App\Console\Commands\HubKpisStarter::class))->getConstant($lib);
            foreach ($rows as [$name, $mod, , , , $combine, $bMod]) {
                $t = ($d = hub_mod($mod)) ? $d['table'] : null;
                if (! $t || ! \Illuminate\Support\Facades\Schema::hasTable($t)) continue;
                if ($combine !== 'none') {
                    $bt = ($bd = hub_mod((string) $bMod)) ? $bd['table'] : null;
                    if (! $bt || ! \Illuminate\Support\Facades\Schema::hasTable($bt)) continue;
                }
                $declared[] = $name;
            }
        }

        $this->assertGreaterThan(40, count($declared), 'المكتبةُ المعلَنةُ خاويةٌ — الحارسُ يقيس فراغاً');
        $born = KpiDef::pluck('name')->all();
        $lost = array_values(array_diff($declared, $born));
        $this->assertSame([], $lost,
            'صفوفٌ مُعلَنةٌ لم تُبذَر — سقطت صامتةً من حارسِ البذر: ' . implode('، ', $lost));
    }

    /** وهدفُ المكتبةِ يُنسَب `policy` — لا يُقرأ رقماً مقيساً من بياناتك وهو ليس كذلك */
    public function test_a_library_target_declares_that_it_is_not_measured(): void
    {
        $this->seedCore();
        $withTarget = $this->seedLibrary()->filter(fn ($k) => $k->target !== null);

        $this->assertGreaterThan(5, $withTarget->count(), 'لا أهدافَ في المكتبة — لا شيءَ يُقاس هنا');
        foreach ($withTarget as $k) {
            $this->assertSame('policy', $k->target_basis, "هدفُ «{$k->name}» بلا نسب");
            $this->assertStringContainsString('مكتبةِ الانطلاق', (string) $k->target_note);
        }
    }

    /**
     * **وخطُّ الأساسِ يُقاس من بياناتك ويُوسَم بذلك** — وهو أصلُ قرارِ المالك:
     * لا رقمَ مخترَع، بل ما يقوله الواقعُ اليومَ موسوماً «خطُّ أساسٍ مبدئيّ لا
     * هدفٌ تجاريّ».
     */
    public function test_the_baseline_command_measures_from_real_data_and_labels_it(): void
    {
        $this->seedCore();
        \App\Models\Project::create(['name' => 'مشروعٌ قائم', 'status' => 'قيد التنفيذ']);
        \App\Models\Project::create(['name' => 'مشروعٌ ثانٍ', 'status' => 'قيد التنفيذ']);

        $this->seedLibrary();
        $k = KpiDef::where('name', '🚀 المشاريع النشطة')->firstOrFail();
        $this->assertNull($k->target, 'هذا المؤشّرُ يبدأ بلا هدف — وإلّا فالاختبارُ يقيس مساراً آخر');

        Artisan::call('hub:kpis-baseline');
        $k->refresh();

        $this->assertSame(2.0, (float) $k->target, 'الهدفُ لم يُقَس من البياناتِ الحقيقيّة');
        $this->assertSame('baseline', $k->target_basis);
        $this->assertStringContainsString('خطُّ أساسٍ مبدئيّ لا هدفٌ تجاريّ', (string) $k->target_note);
        $this->assertNotNull($k->baseline_at, 'لا يُعرف متى قِيس — فلا يُعرف متى شاخ');
    }

    /** ولا يمسّ خطُّ الأساسِ هدفاً التزم به أحد — وإلّا محا الأمرُ قرارَ مجلس */
    public function test_the_baseline_never_overwrites_a_declared_target(): void
    {
        $this->seedCore();
        $this->seedLibrary();

        $k = KpiDef::where('name', '✅ نسبة إنجاز المهام')->firstOrFail();
        $this->assertSame(80.0, (float) $k->target);

        Artisan::call('hub:kpis-baseline', ['--all' => true]);
        $k->refresh();

        $this->assertSame(80.0, (float) $k->target, 'خطُّ الأساسِ داس هدفاً معلَناً');
        $this->assertSame('policy', $k->target_basis);
    }

    /**
     * **ولا يُثبَّت الصفرُ خطَّ أساس**: وحدةٌ لم يُسجَّل فيها شيءٌ بعدُ تقرأ صفراً،
     * وهدفٌ صفريٌّ باتّجاه «الأعلى أفضل» يجعل المؤشّرَ «على الهدف» أبداً.
     */
    public function test_an_empty_measurement_is_not_a_baseline(): void
    {
        $this->seedCore();
        $this->seedLibrary();

        $k = KpiDef::where('name', '🚀 المشاريع النشطة')->firstOrFail();
        $this->assertNull($k->target);
        $this->assertSame(0.0, hub_kpi_value((array) $k->formula), 'لا مشاريعَ هنا — وإلّا لم يُقَس الفراغ');

        Artisan::call('hub:kpis-baseline');

        $this->assertNull($k->fresh()->target,
            'صفرُ الفراغِ ثُبّت هدفاً — والمؤشّرُ سيُعرض «على الهدف» بلا مشروعٍ واحد');
    }

    /**
     * **ولا يُثبَّت صفرٌ كاذبٌ هدفاً**: مؤشّرٌ يُرشِّح حالةً لا يعرفها سجلُّ
     * وحدته يقرأ صفراً أبداً، وتثبيتُه هدفاً يخلّد الكذبَ في جدول.
     */
    public function test_the_baseline_refuses_an_indicator_whose_filter_is_dead(): void
    {
        $this->seedCore();

        $dead = KpiDef::create(['name' => 'مؤشّرٌ بفلترٍ ميّت', 'good' => 'down', 'sort' => 1,
            'formula' => ['a' => ['agg' => 'count', 'module' => 'tasks', 'col' => null,
                                  'st' => 'حالةٌ لا وجودَ لها'], 'combine' => 'none']]);

        $this->assertNotSame([], KpiCentre::deadFilters((array) $dead->formula),
            'الفلترُ ليس ميّتاً أصلاً — الاختبارُ يقيس مساراً لا يُسلَك');

        Artisan::call('hub:kpis-baseline');

        $this->assertNull($dead->fresh()->target,
            'صفرٌ كاذبٌ ثُبّت هدفاً — والمؤشّرُ سيُعرض «على الهدف» أبداً');
    }
}
