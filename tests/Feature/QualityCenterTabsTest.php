<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Issue;
use App\Models\KpiDef;
use App\Models\Objective;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\CeoBoard;
use App\Support\DataQuality;
use App\Support\ExecutionStats;
use App\Support\KpiCentre;
use App\Support\TimeRange;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * **مركزُ الجودة والإنجاز بتبويبات (WP-8.1 · spec §6 · §6.1 · §12 · §47).**
 *
 * ما يحرسه هذا الملف — ثلاثةٌ لا رابعَ لها:
 *
 *  ١) **كلُّ تبويبٍ يُعرَض بحارسِه.** «البيانات» يبقى للمالك وحده: مسحُ الجودة
 *     غيرُ منطَّق، يُخبَّأ تحت مفتاحٍ عامّ (`dq:scan`)، ويُظهر **أسماءَ سجلاتٍ
 *     من كلّ الوحدات**. وبقيةُ التبويبات لحاملِ راية المتابعة **غيرِ المعزول**
 *     (`hub_monitor` + `hub_org_analytics_guard`) — فأرقامُ المنشأة تجمع عبر
 *     الشركات كلِّها، وحسابٌ محصورٌ بشركةٍ أو بعميلٍ لا يُطعَم مجموعَ غيرِه.
 *
 *  ٢) **قارئُ المتابعة لا يرى عيّنةَ جودةٍ واحدة.** لا في النظرة التنفيذية،
 *     ولا في الاتّجاهات، ولا في المعالجة — الاسمُ المسرَّبُ لا يُستردّ.
 *
 *  ٣) **كلُّ رقمٍ في النظرة التنفيذية يساوي محرّكَه حرفياً** (spec §6.1): لا
 *     حسابَ ثانٍ في المتحكّم، ولا درجةَ مركّبةٌ بلا رياضيات. الاختبارُ يقارن
 *     مخرجَ الشاشة بنداءِ المحرّك نفسِه — فأيُّ إعادةِ حسابٍ تُسقطه.
 */
class QualityCenterTabsTest extends TestCase
{
    /** كلُّ التبويبات كما يُعلنها المتحكّم — لا قائمةٌ ثانيةٌ تُصان يدوياً */
    private function tabs(): array
    {
        return array_keys(\App\Http\Controllers\Web\QualityController::TABS);
    }

    /** قارئُ متابعةٍ غيرُ معزول: راية monitor ونطاقٌ كامل، وليس مالكاً */
    private function monitor(array $extra = []): User
    {
        $modules = array_keys(hub_modules());
        $role = Role::create(['name' => 'مراقبٌ عامّ', 'scope' => 'all', 'flags' => ['monitor' => 1],
            'matrix' => collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all()]);

        return User::create($extra + ['name' => 'مراقب', 'email' => 'mon' . count($extra) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /**
     * عالمٌ صغيرٌ يُغذّي المحرّكاتِ الثمانية: مهامٌّ بأختامِ إنجاز، ومؤشّرُ إنجازٍ
     * مبذورُ الشكل، وهدفٌ بنتيجة، ومشكلةٌ حرجة، وعميلٌ ناقصٌ يصنع نتيجةَ جودة.
     */
    private function seedWorld(): void
    {
        // مهامّ: واحدةٌ أُنجزت في الموعد، وواحدةٌ متأخّرةٌ مفتوحة
        Task::create(['title' => 'مهمّة أُنجزت', 'status' => 'منجزة',
            'due' => now()->toDateString(), 'completed_at' => now()->subHour()]);
        Task::create(['title' => 'مهمّة فات موعدها', 'status' => 'قيد التنفيذ',
            'due' => now()->subDays(3)->toDateString()]);

        // مؤشّرُ «نسبة إنجاز المهام» بشكل البذرة نفسِه (`HubKpisStarter::CORE`)
        KpiDef::create(['name' => '✅ نسبة إنجاز المهام', 'unit' => '٪', 'target' => 80,
            'good' => 'up', 'sort' => 1, 'formula' => [
                'a' => ['agg' => 'count', 'module' => 'tasks', 'col' => null, 'st' => 'منجزة'],
                'b' => ['agg' => 'count', 'module' => 'tasks', 'col' => null, 'st' => ''],
                'combine' => 'ratio_pct']]);

        Objective::create(['title' => 'هدفٌ للقياس', 'status' => 'قيد التنفيذ',
            'due' => now()->addDays(20)->toDateString()]);

        Issue::create(['title' => 'مشكلةٌ حرجة', 'severity' => 'حرجة', 'status' => 'مفتوحة']);

        // عميلٌ باسمٍ فريد: اسمُه يظهر في **عيّنة** فحصِ الجودة وحدَها
        Client::create(['name' => self::SAMPLE_NAME, 'email' => 'ليس-بريداً']);
    }

    /** اسمٌ لا يظهر إلا في عيّنات المسح — فظهورُه لغير المالك تسريبٌ مُثبَت */
    private const SAMPLE_NAME = 'سِرُّ العيّنةِ الفريد';

    /* ────────── ١) كلُّ تبويبٍ بحارسه ────────── */

    public function test_the_owner_opens_every_tab(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->seedWorld();

        foreach ($this->tabs() as $tab) {
            $this->get(route('quality.index', ['tab' => $tab]))
                ->assertOk()
                ->assertViewHas('tab', $tab);
        }
    }

    public function test_a_monitor_opens_every_tab_except_the_owner_only_data_tab(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->seedWorld();

        $mon = $this->monitor();
        foreach ($this->tabs() as $tab) {
            $res = $this->actingAs($mon)->get(route('quality.index', ['tab' => $tab]));
            if ($tab === 'data') {
                $res->assertForbidden();
                continue;
            }
            $res->assertOk();
        }
    }

    public function test_a_reader_without_the_monitor_flag_is_refused(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)
            ->get(route('quality.index'))->assertForbidden();
    }

    /**
     * الحسابُ المعزول (شركاتٍ أو عملاء) يُصَدّ عن تبويبات المنشأة: أرقامُها
     * غيرُ منطَّقة ومخبّأةٌ بمفاتيحَ عامة — لا تُقصّ لعزلِ قارئها.
     */
    public function test_an_isolated_reader_is_blocked_from_the_org_wide_tabs(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->seedWorld();

        $co = \App\Models\Company::create(['name_ar' => 'شركة']);
        $walled = $this->monitor(['companies' => [$co->id]]);

        foreach (['overview', 'execution', 'kpi', 'okr', 'trends', 'actions'] as $tab) {
            $this->actingAs($walled)->get(route('quality.index', ['tab' => $tab]))
                ->assertForbidden();
        }
    }

    /** معاملُ تبويبٍ مجهول يرتدّ للنظرة التنفيذية بلا سقوط */
    public function test_an_unknown_tab_falls_back_to_the_overview(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $this->get(route('quality.index', ['tab' => '../data']))
            ->assertOk()->assertViewHas('tab', 'overview');
    }

    /* ────────── ٢) لا عيّنةَ جودةٍ لقارئ المتابعة ────────── */

    public function test_a_monitor_reader_never_sees_a_quality_sample(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->seedWorld();

        // خطُّ الأساس: العيّنةُ تظهر فعلاً للمالك في تبويب البيانات — وإلا فالحارسُ
        // يحرس فراغاً (بريدٌ فاسدٌ فحصٌ مشتقٌّ من السجل، واسمُ العميل عيّنتُه)
        $this->get(route('quality.index', ['tab' => 'data']))
            ->assertOk()->assertSee(self::SAMPLE_NAME, false);

        $mon = $this->monitor();
        foreach (['overview', 'execution', 'kpi', 'okr', 'trends', 'actions'] as $tab) {
            $this->actingAs($mon)->get(route('quality.index', ['tab' => $tab]))
                ->assertOk()->assertDontSee(self::SAMPLE_NAME, false);
        }
    }

    /* ────────── ٣) كلُّ رقمٍ يساوي محرّكَه ────────── */

    public function test_every_overview_number_equals_its_own_engine(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 10:00:00'));
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->seedWorld();

        $ov = $this->get(route('quality.index', ['tab' => 'overview']))
            ->assertOk()->viewData('ov');

        $range = TimeRange::fromRequest(new \Illuminate\Http\Request(), \App\Http\Controllers\Web\QualityController::RANGE);

        // ١) جودةُ البيانات٪ — من مسح `DataQuality` لا من حسابٍ في المتحكّم
        $scan = DataQuality::scan();
        $this->assertSame($scan['totals']['score'], $ov['quality']['score']);
        $this->assertSame($scan['totals']['defects'], $ov['quality']['defects']);

        // ٢) إنجازُ العمل٪ — قيمةُ المؤشّر من `hub_kpis` حرفياً (لا عدٌّ ثانٍ للمهامّ)
        $kpis = collect(hub_kpis($this->owner, true));
        $seeded = $kpis->firstWhere('name', '✅ نسبة إنجاز المهام');
        $this->assertNotNull($seeded, 'مؤشّرُ إنجاز المهامّ مبذورٌ في العالم المزروع');
        $this->assertSame($seeded['id'], $ov['completion']['kpi']['id']);
        $this->assertSame($seeded['value'], $ov['completion']['value']);

        // وحالاتُ الإغلاق التي يفوتُها فلترُ المؤشّر تُسمّى — بمفردات `hub_closed_scope`
        $this->assertContains('مكتملة', $ov['completion']['gap'],
            'المؤشّر يعدّ «منجزة» وحدَها، و«مكتملة» حالةُ إغلاقٍ في سجلّ المهامّ — يُصارَح بها لا تُبتلع');

        // ٣ و٤) الالتزام٪ والمتأخّر — من `ExecutionStats` وحدَه
        $x = ExecutionStats::executionSummary($range);
        $this->assertSame($x['on_time']['pct'], $ov['ontime']['pct']);
        $this->assertSame($x['overdue'], $ov['overdue']);

        // ٥) مؤشّراتٌ على الهدف — من `KpiCentre` (وهو بدوره يقرأ `hub_kpis`)
        $sum = KpiCentre::summary(KpiCentre::rows($this->owner));
        $this->assertSame($sum['on'], $ov['kpis']['on']);
        $this->assertSame($sum['total'], $ov['kpis']['total']);

        // ٦) تقدّمُ OKR — من `hub_okr_board` (المصدرُ الواحد للنسبة)
        $board = hub_okr_board($this->owner);
        $this->assertSame($board['avg'], $ov['okr']['avg']);
        $this->assertSame($board['measured'], $ov['okr']['measured']);

        // ٧) مشكلاتٌ حرجة — من `CeoBoard::risks` (يقرأ بـ`hub_read` فيجمع النطاق والصلاحية)
        $risks = CeoBoard::risks();
        $this->assertSame(count($risks), $ov['risks']['listed']);
        $this->assertSame(count(array_filter($risks, fn ($x) => ($x['tone'] ?? '') === 'bad')),
            $ov['risks']['critical']);

        // ٨) اتّجاهُ التحسّن — من `DataQuality::history` (بلا لقطتين: `null` لا صفر)
        $hist = DataQuality::history();
        $this->assertSame($hist['delta'], $ov['improve']['delta']);
        $this->assertSame(count($hist['points']), $ov['improve']['points']);

        Carbon::setTestNow();
    }

    /* ────────── ٤) الكلفة: لا تنمو بالصفوف، ولا تُحسب مرّتين ────────── */

    /**
     * **ميزانيةُ النظرة التنفيذية** (§41 خطوة ٥): ثمانيةُ محرّكاتٍ في شاشةٍ
     * واحدة تُغري بقارئٍ لكل صفّ. الحكمُ هنا ليس رقماً سحرياً بل أن **ثلاثةَ
     * مشاريعَ وثلاثين تكلّفان العدد نفسه**؛ ثم أنّ الفتحةَ الثانية تقرأ
     * الخبيئة (`hub_screen`) بدل أن تُعيد الحسابَ من أوّله.
     */
    public function test_the_overview_cost_does_not_grow_with_the_rows_and_is_cached(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->seedWorld();

        $url = route('quality.index', ['tab' => 'overview']);
        $cold = fn () => $this->countQueries(function () use ($url) {
            \Illuminate\Support\Facades\Cache::flush();
            $this->actingAs($this->owner)->get($url)->assertOk();
        });

        $this->seedProjects(3, 0);
        $few = $cold();

        $this->seedProjects(27, 3);
        $many = $cold();

        $this->assertLessThanOrEqual($few + 4, $many,
            "٣ مشاريعَ كلّفت {$few} استعلاماً و٣٠ كلّفت {$many} — الكلفة تنمو مع الصفوف");

        // والفتحةُ الثانية من الخبيئة: أقلُّ من عُشر الباردة
        $warm = $this->countQueries(fn () => $this->actingAs($this->owner)->get($url)->assertOk());
        $this->assertLessThan((int) ($many / 10), $warm,
            "الفتحةُ الثانية كلّفت {$warm} استعلاماً — الخبيئةُ لا تعمل، والشاشةُ تُحسب من أوّلها كلَّ مرّة");
    }

    /** عدّادُ استعلاماتٍ لمقطعٍ من الشيفرة — بنمط `ScreenPerformanceTest` */
    private function countQueries(\Closure $fn): int
    {
        $n = 0;
        $on = false;
        \Illuminate\Support\Facades\DB::listen(function () use (&$n, &$on) { if ($on) $n++; });
        $on = true;
        $fn();
        $on = false;

        return $n;
    }

    private function seedProjects(int $n, int $from): void
    {
        for ($i = $from; $i < $from + $n; $i++) {
            $p = \App\Models\Project::create(['name' => "مشروع {$i}", 'status' => 'قيد التنفيذ']);
            Task::create(['title' => "مهمّة {$i}", 'status' => 'جديدة', 'project_id' => $p->id,
                'due' => now()->subDay()->toDateString()]);
        }
    }

    /** بلا مؤشّرِ إنجازٍ مبذور: حالةٌ فارغةٌ صادقة — لا صفرٌ ولا رقمٌ مخترع */
    public function test_the_completion_card_is_empty_not_zero_when_no_kpi_exists(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $ov = $this->get(route('quality.index', ['tab' => 'overview']))
            ->assertOk()->viewData('ov');

        $this->assertNull($ov['completion']['kpi']);
        $this->assertNull($ov['completion']['value']);
    }

    /**
     * (§49 · §25 — لا طريقَ مسدود) **لا تبويبَ ولا رابطَ يَعِد ببابٍ يردّه ٤٠٣.**
     *
     * شريطُ التبويبات كان يرسم السبعةَ لكل قارئ — و«جودة البيانات» للمالك وحدَه
     * (`OWNER_TABS`)، فحاملُ راية المتابعة يجد في كل تبويبٍ يفتحه تبويباً ثامناً
     * يردّه. والروابطُ داخل التبويبات (المشكلات · لوحة الأهداف · لوحة الدعم)
     * تتبع مصفوفةَ القارئ لا رايةَ المتابعة — فمن لا يملك الوحدةَ لا يُوعَد بها.
     */
    public function test_no_tab_and_no_link_promises_a_door_the_reader_is_refused(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->seedWorld();
        auth()->logout();

        // ① التبويبُ المملوكُ للمالك لا يُرسَم لغيره — والمالكُ يبقى يراه
        $dataUrl = route('quality.index', ['tab' => 'data']);
        $mon = $this->monitor();
        foreach (array_diff($this->tabs(), ['data']) as $tab) {
            $html = $this->actingAs($mon)->get(route('quality.index', ['tab' => $tab]))
                ->assertOk()->getContent();
            $this->assertStringNotContainsString('tab=data', $html,
                "تبويب «{$tab}» يعرض للمراقب تبويبَ البيانات وهو يُصَدّ عنه");
            auth()->logout();
        }
        $this->assertStringContainsString('tab=data',
            $this->actingAs($this->owner)->get(route('quality.index', ['tab' => 'overview']))
                ->assertOk()->getContent(), 'تبويبُ البيانات اختفى عن المالك');
        auth()->logout();

        // ② روابطُ الوحدات تتبع مصفوفةَ القارئ: مراقبٌ بلا مصفوفةٍ لا يُوعَد بها
        $bare = Role::create(['name' => 'مراقب بلا مصفوفة', 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => []]);
        $bareMon = User::create(['name' => 'مراقب بلا مصفوفة', 'email' => 'qc-bare@test.local',
            'password' => 'Secret!2026x', 'role_id' => $bare->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        foreach (['overview' => route('m.index', 'issues'), 'okr' => route('okrs.board'),
                  'actions' => route('support')] as $tab => $url) {
            $html = $this->actingAs($bareMon)->get(route('quality.index', ['tab' => $tab]))
                ->assertOk()->getContent();
            $this->assertStringNotContainsString('href="' . $url . '"', $html,
                "تبويب «{$tab}» يربط قارئاً بلا صلاحيةٍ إلى {$url}");
            auth()->logout();
        }

        // والمراقبُ صاحبُ المصفوفة الكاملة يبقى يجد روابطَه — الحجبُ بالصلاحية لا بالراية
        $full = $this->actingAs($mon)->get(route('quality.index', ['tab' => 'okr']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('href="' . route('okrs.board') . '"', $full,
            'رابطُ لوحة الأهداف سقط عمّن يملك عرضَها');
    }
}
