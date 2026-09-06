<?php

namespace Tests\Feature;

use App\Models\KpiDef;
use App\Support\KpiCentre;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مركزُ المؤشّرات (WP-8.5 · §6.9 · §47).
 *
 * البطاقةُ التي تقول «٠٫٠» ولا تقول **لماذا** أسوأُ من غياب البطاقة: سبعةٌ من
 * مؤشّرات البذر كانت تُرشِّح حالاتٍ **لا وجودَ لها في السجل** («متأخرة» في
 * المهامّ، «مفتوحة» في التذاكر، «نشط» في العملاء…) فتقرأ صفراً أبداً — وصفرٌ
 * مقابلَ هدفٍ صفر باتّجاه «الأقلّ أفضل» يُعرَض **«على الهدف»**. فالنظامُ يهنّئ
 * نفسَه على فلترٍ ميّت.
 *
 * وهذه الحزمةُ تُثبت الثلاثة: الحالةُ من السجل، والفلترُ الميّت لا يُعَدّ
 * إنجازاً، والانحرافُ والاتّجاهُ والمالكُ والدورةُ محسوبةٌ لا مُدَّعاة.
 */
class KpiCenterTest extends TestCase
{
    /** خيارات حالة الوحدة كما يقرؤها محرّك المؤشّرات نفسُه (بالمفتاح لا بالعمود) */
    private function registryStates(string $module): array
    {
        $def = hub_mod($module);
        if (! $def || ! ($sk = $def['status'] ?? null)) return [];

        return (array) (collect($def['fields'])->firstWhere('key', $sk)['options'] ?? []);
    }

    /** مؤشّرٌ جاهزٌ بمعادلةِ عدٍّ بسيطة */
    private function kpi(array $over = []): KpiDef
    {
        return KpiDef::create(array_merge([
            'name' => 'مشاريع جارية', 'unit' => 'مشروع', 'target' => 10, 'good' => 'up',
            'formula' => ['a' => ['agg' => 'count', 'module' => 'projects', 'col' => null,
                                  'st' => 'قيد التنفيذ'], 'combine' => 'none'],
            'sort' => 0,
        ], $over));
    }

    /* ────────── العيبُ الحيّ: حالاتٌ لا وجودَ لها في السجل ────────── */

    public function test_every_seeded_kpi_filters_a_status_that_exists_in_the_registry(): void
    {
        $this->seedCore();
        $this->artisan('hub:kpis-starter --all')->assertSuccessful();
        $this->assertGreaterThan(0, KpiDef::count());

        $dead = [];
        foreach (KpiDef::orderBy('sort')->orderBy('id')->get() as $k) {
            $f = (array) $k->formula;
            foreach (['a', 'b'] as $side) {
                $m = (array) ($f[$side] ?? []);
                $st = trim((string) ($m['st'] ?? ''));
                if ($st === '' || ! ($mod = (string) ($m['module'] ?? ''))) continue;
                $opts = $this->registryStates($mod);
                if ($opts && ! in_array($st, $opts, true)) {
                    $dead[] = "«{$k->name}»: {$mod} ← «{$st}»";
                }
            }
        }

        $this->assertSame([], $dead,
            'مؤشّراتٌ مبذورةٌ تُرشِّح حالاتٍ لا وجودَ لها في السجل — تقرأ صفراً أبداً: ' . implode(' · ', $dead));
    }

    /**
     * **تصحيحُ الجدول يحمي التنصيبات الجديدة وحدَها.** التنصيبُ القائم فيه
     * صفوفٌ محفوظةٌ بالاسم القديم والفلترِ الميّت، والبذرُ يتخطّاها بالاسم فلا
     * يمسّها — فتبقى تقرأ صفراً أبداً بعد الترقية. `--repair` هو الطريقُ إليها،
     * وهذا حارسُه: يُصلح **في مكانه** (لا نسخةٌ ثانيةٌ بالاسم الجديد)، ويكتب
     * أثراً، **ولا يمسّ صفّاً عدّله صاحبُ النظام بيده إلى حالةٍ صحيحة**.
     */
    public function test_repair_fixes_a_legacy_dead_seed_in_place_and_spares_a_hand_edited_one(): void
    {
        $this->seedCore();
        $this->artisan('hub:kpis-starter --all')->assertSuccessful();
        $total = KpiDef::count();

        // (أ) صفّان يعودان إلى صورتهما المكسورة كما هي في تنصيبٍ قائم
        KpiDef::where('name', '⛔ المهام المتوقفة')->orderBy('id')->first()
            ->update(['name' => '🔥 المهام المتأخرة', 'formula' => [
                'a' => ['agg' => 'count', 'module' => 'tasks', 'col' => null, 'st' => 'متأخرة'],
                'combine' => 'none']]);
        KpiDef::where('name', '🎫 التذاكر الجديدة')->orderBy('id')->first()
            ->update(['name' => '🎫 التذاكر المفتوحة', 'formula' => [
                'a' => ['agg' => 'count', 'module' => 'tickets', 'col' => null, 'st' => 'مفتوحة'],
                'combine' => 'none']]);

        // (ب) وصفٌّ عدّله صاحبُ النظام بيده إلى حالةٍ **موجودةٍ** في السجل
        $hand = KpiDef::where('name', '🤝 العملاء الحاليون')->orderBy('id')->first();
        $handState = $this->registryStates('clients')[0] ?? 'عميل حالي';
        $hand->update(['formula' => ['a' => ['agg' => 'count', 'module' => 'clients',
            'col' => null, 'st' => $handState], 'combine' => 'none']]);

        $this->assertSame(2, KpiDef::all()
            ->filter(fn ($k) => KpiCentre::deadFilters((array) $k->formula))->count(),
            'خطُّ الأساس: صفّان ميّتان بالضبط قبل الإصلاح');

        $this->artisan('hub:kpis-starter --all --repair')->assertSuccessful();

        // يُصلَح في مكانه: لا صفَّ جديدٌ بالاسم الجديد بجانب المكسور
        $this->assertSame($total, KpiDef::count(), 'الإصلاح بذر نسخةً ثانيةً بدل أن يُصلح في مكانها');
        $this->assertSame(0, KpiDef::all()
            ->filter(fn ($k) => KpiCentre::deadFilters((array) $k->formula))->count(),
            'بقي فلترٌ ميّتٌ بعد --repair — والصفُّ يقرأ صفراً أبداً');
        $this->assertSame(0, KpiDef::whereIn('name', ['🔥 المهام المتأخرة', '🎫 التذاكر المفتوحة'])->count(),
            'الاسمُ المكسورُ باقٍ — الإصلاح لم يمسّ الصفَّ نفسَه');
        $fixed = KpiDef::where('name', '⛔ المهام المتوقفة')->orderBy('id')->first();
        $this->assertNotNull($fixed);
        $this->assertSame('متوقفة', ((array) $fixed->formula)['a']['st'] ?? null,
            'المعادلةُ لم تُصحَّح إلى حالةٍ يعرفها السجل');

        // ولا يُمَسّ ما صحّحه صاحبُ النظام بيده
        $this->assertSame($handState,
            KpiDef::where('id', $hand->id)->first()->formula['a']['st'] ?? null,
            'الإصلاح داس تعديلاً يدويّاً صحيحاً');

        // وأثرٌ لكل إصلاح (§31) — لا تغييرَ صامتٌ في معادلةٍ تُقرأ منها الإدارة
        $this->assertSame(2, \App\Models\AuditEntry::where('action', 'إصلاح مؤشر KPI')->count());
    }

    public function test_a_dead_status_filter_is_never_reported_on_target(): void
    {
        $this->seedCore();
        // صفرٌ مقابلَ هدفٍ صفر باتّجاه «الأقلّ أفضل» = «على الهدف» في القراءة القديمة
        $k = $this->kpi(['name' => '🔥 مهامٌ بحالةٍ ميّتة', 'target' => 0, 'good' => 'down',
            'formula' => ['a' => ['agg' => 'count', 'module' => 'tasks', 'col' => null,
                                  'st' => 'متأخرة'], 'combine' => 'none']]);

        $rows = collect(KpiCentre::rows($this->owner))->keyBy('id');
        $row = $rows[$k->id] ?? null;
        $this->assertNotNull($row, 'المؤشّر غائبٌ عن قراءة المركز');

        $this->assertNotEmpty($row['dead'], 'الفلترُ الميّت لم يُرصد');
        $this->assertSame('dead', $row['health'],
            'فلترٌ لا يطابق السجل عُرض حالةً صحّية — النظام يهنّئ نفسه على قياسٍ لم يقع');
        $this->assertNotContains($row['id'], array_column(KpiCentre::onTarget($rows->values()->all()), 'id'));
    }

    public function test_the_screen_names_the_dead_filter_in_arabic(): void
    {
        $this->seedCore();
        $this->kpi(['name' => 'مؤشّرٌ بفلترٍ ميّت',
            'formula' => ['a' => ['agg' => 'count', 'module' => 'tasks', 'col' => null,
                                  'st' => 'متأخرة'], 'combine' => 'none']]);

        $this->actingAs($this->owner)->get('/kpis')->assertOk()
            ->assertSee('فلترٌ لا يطابق السجل')
            ->assertSee('متأخرة');
    }

    /* ────────── مالكٌ ودورة ────────── */

    public function test_owner_and_period_are_stored_and_shown(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/kpis', [
            'name' => 'نسبة الإنجاز', 'unit' => '٪', 'target' => '80', 'good' => 'up',
            'owner_id' => $this->employee->id, 'period' => 'ربع سنوي',
            'a_agg' => 'count', 'a_module' => 'projects', 'a_st' => 'قيد التنفيذ',
            'combine' => 'none',
        ])->assertRedirect();

        $k = KpiDef::orderBy('id')->first();
        $this->assertSame($this->employee->id, $k->owner_id, 'مالكُ المؤشّر لم يُحفظ');
        $this->assertSame('ربع سنوي', $k->period, 'دورةُ المؤشّر لم تُحفظ');

        $this->actingAs($this->owner)->get('/kpis')->assertOk()
            ->assertSee('موظفة')->assertSee('ربع سنوي');
    }

    public function test_an_owner_outside_the_users_table_is_rejected(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/kpis', [
            'name' => 'مؤشّر', 'good' => 'up', 'owner_id' => (string) \Illuminate\Support\Str::uuid(),
            'a_agg' => 'count', 'a_module' => 'projects', 'combine' => 'none',
        ])->assertSessionHasErrors('owner_id');
    }

    public function test_the_builder_refuses_a_status_outside_the_registry(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/kpis', [
            'name' => 'مؤشّرٌ بحالةٍ مخترَعة', 'good' => 'up',
            'a_agg' => 'count', 'a_module' => 'tasks', 'a_st' => 'متأخرة', 'combine' => 'none',
        ])->assertStatus(422);

        $this->assertSame(0, KpiDef::count(), 'كُتب مؤشّرٌ بفلترٍ ميّتٍ من الباني');
    }

    /* ────────── الانحراف ────────── */

    public function test_variance_is_signed_by_the_direction_not_by_subtraction(): void
    {
        $this->seedCore();
        \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);

        $up = $this->kpi(['name' => 'الأعلى أفضل', 'target' => 4, 'good' => 'up', 'sort' => 1]);
        $down = $this->kpi(['name' => 'الأقلّ أفضل', 'target' => 4, 'good' => 'down', 'sort' => 2]);

        $rows = collect(KpiCentre::rows($this->owner))->keyBy('id');

        // القيمةُ ١ مقابلَ هدفٍ ٤: فرقٌ خام ‎−٣ في الحالتين
        $this->assertSame(-3.0, $rows[$up->id]['delta']);
        $this->assertSame(-3.0, $rows[$down->id]['delta']);
        // والانحرافُ **بإشارة الاتجاه**: تحتَ الهدف سيّئٌ صعوداً وحسنٌ نزولاً
        $this->assertSame(-3.0, $rows[$up->id]['variance'], 'الانحرافُ صعوداً يجب أن يكون سالباً');
        $this->assertSame(3.0, $rows[$down->id]['variance'], 'الانحرافُ نزولاً يجب أن يكون موجباً');

        $this->assertSame('off', $rows[$up->id]['health']);
        $this->assertSame('on', $rows[$down->id]['health']);
    }

    public function test_a_kpi_without_a_target_is_not_judged(): void
    {
        $this->seedCore();
        $k = $this->kpi(['target' => null]);
        $rows = collect(KpiCentre::rows($this->owner))->keyBy('id');

        $this->assertNull($rows[$k->id]['variance']);
        $this->assertSame('notarget', $rows[$k->id]['health'], 'مؤشّرٌ بلا هدفٍ حُكم عليه بهدفٍ مخترَع');
    }

    /* ────────── الاتّجاه من اللقطة اليومية ────────── */

    public function test_the_daily_snapshot_writes_one_point_per_kpi(): void
    {
        $this->seedCore();
        $k = $this->kpi();

        $this->artisan('hub:quality-snapshot')->assertSuccessful();
        $this->assertDatabaseHas('metric_points', [
            'module' => 'kpis', 'record_id' => $k->id, 'metric' => 'value',
        ]);

        // إعادةُ التشغيل في اليوم نفسِه تُحدِّث ولا تُكرّر
        $this->artisan('hub:quality-snapshot')->assertSuccessful();
        $this->assertSame(1, DB::table('metric_points')->where('module', 'kpis')
            ->where('record_id', $k->id)->where('metric', 'value')->count());
    }

    public function test_the_trend_reads_the_series_and_stays_honest_when_empty(): void
    {
        $this->seedCore();
        $k = $this->kpi();

        $rows = collect(KpiCentre::rows($this->owner))->keyBy('id');
        $this->assertSame(0, $rows[$k->id]['trend']['points']);
        $this->assertNull($rows[$k->id]['trend']['delta'], 'نقطةٌ واحدةٌ أو لا نقطة لا تصنع اتّجاهاً');

        hub_metric_put('kpis', $k->id, 'value', 4.0, now()->subDays(3), 'auto');
        hub_metric_put('kpis', $k->id, 'value', 9.0, now(), 'auto');

        $rows = collect(KpiCentre::rows($this->owner))->keyBy('id');
        $this->assertSame(2, $rows[$k->id]['trend']['points']);
        $this->assertSame(5.0, $rows[$k->id]['trend']['delta']);
        $this->assertSame('up', $rows[$k->id]['trend']['dir']);
    }

    /* ────────── قائمةُ «خارج الهدف» (§47) ────────── */

    public function test_the_screen_lists_the_off_target_kpis_by_name(): void
    {
        $this->seedCore();
        $this->kpi(['name' => 'مؤشّرٌ بعيدٌ عن هدفه', 'target' => 500, 'good' => 'up']);

        $this->actingAs($this->owner)->get('/kpis')->assertOk()
            ->assertSee('خارج الهدف')
            ->assertSee('مؤشّرٌ بعيدٌ عن هدفه');
    }

    public function test_the_centre_keeps_its_guard(): void
    {
        $this->seedCore();
        $this->actingAs($this->viewer)->get('/kpis')->assertForbidden();
    }
}
