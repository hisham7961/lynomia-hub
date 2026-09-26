<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkUpdate;
use App\Support\Workforce\DailyWorkCompliance as DWC;
use App\Support\Workforce\Workday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مفاتيحُ الالتزامِ اليوميّ: أتُغيّر الحكمَ فعلاً؟** (المراجعةُ الشاملة ·
 * الطبقة ٣ · L3-04).
 *
 * الطبقةُ الثالثةُ أثبتت أنّ الدعاوى **التصريحيّة** تصمد: كلُّ مفتاحٍ مُعلَنٌ،
 * وكلُّ اقتباسٍ يشير إلى شيفرةٍ قائمة (v2.552.0). ويبقى سؤالُها الأصعب:
 * **أيفعل المفتاحُ ما يقوله أثرُه؟** وقد أُجيب لثمانيةِ مفاتيحَ أمنيّة
 * (v2.549.0)، وهذه ثمانيةٌ أخرى — **أخطرُ ما في الباقي على الإنسان**.
 *
 * فهذه المفاتيحُ تقرّر **أيُسجَّل الموظّفُ ملتزماً أم لا**، وأثرُها يمتدّ إلى
 * ملفِّه ومسيّرِه. ومفتاحٌ يُزيّن الشاشةَ ولا يمسّ الحكم هنا **يُدين بريئاً أو
 * يُبرّئ مقصّراً** — وكلاهما صامتٌ لا يشتكي منه أحد.
 *
 * وكلُّ مفتاحٍ يُقلَب **بوجهَيه** على المشهدِ نفسِه، ويُقاس الحكمُ الناتج:
 * فلا يكفي أن يتغيّر شيءٌ، بل أن يتغيّر **إلى ما يقوله الكتالوج**.
 */
class DailyComplianceSwitchesActuallyDecideTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /** موظّفٌ موصولٌ بمستخدم — الجسرُ `Employee.user_id ↔ User.id` */
    private function linked(): array
    {
        $role = Role::firstOrCreate(['name' => 'منفّذٌ يوميّ'],
            ['scope' => 'all', 'flags' => [],
             'matrix' => ['updates' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0], 'tasks' => ['v' => 1, 'e' => 1]]]);
        $u = User::create(['name' => 'منفّذ', 'email' => Str::random(10) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => $u->name, 'status' => 'نشط', 'user_id' => $u->id]);

        return [$u, $e];
    }

    /** يومٌ كامل: حضر ٠٨:٠٠ وانصرف ١٧:٠٠ بلا تقرير — و«الآن» يُضبَط صراحةً */
    private function workedDay(Employee $e, string $now = '20:00'): string
    {
        $date = '2026-09-14';                     // يومُ عملٍ ثابتٌ لا يقترعه التقويم
        Attendance::create(['emp_id' => $e->id, 'date' => $date, 'time_in' => '08:00',
            'time_out' => '17:00', 'status' => Workday::PRESENT]);
        Carbon::setTestNow(Carbon::parse($date . ' ' . $now));

        return $date;
    }

    /** الحالةُ القانونيّة — **الحقيقةُ** كما وقعت (§11) */
    private function verdict(Employee $e, string $date): string
    {
        return (string) (DWC::resolve($e, $date)['state'] ?? '');
    }

    /** والأثرُ الفعّالُ للموارد البشرية — **حُكمُ السياسةِ** على تلك الحقيقة (§6.C) */
    private function effective(Employee $e, string $date): string
    {
        return (string) (DWC::resolve($e, $date)['effective'] ?? '');
    }

    // ── ① اشتراطُ التقرير أصلاً ──────────────────────────────────────────

    public function test_report_required_decides_whether_a_missing_report_counts(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '23:59');
        [, $e] = $this->linked();
        $date = $this->workedDay($e);

        $this->hubSetting('work.report_required', '1');
        $on = $this->verdict($e, $date);
        $this->hubSetting('work.report_required', '0');
        $off = $this->verdict($e, $date);

        $this->assertNotSame(DWC::NOT_REQUIRED, $on,
            'الاشتراطُ مُفعَّلٌ وتقريرُ اليومِ غائبٌ بعدَ المهلة — ومع ذلك لا التزامَ عليه');
        $this->assertSame(DWC::NOT_REQUIRED, $off,
            'الاشتراطُ مُطفأٌ والنظامُ ما زال يُطالب بالتقرير — مفتاحٌ يُزيّن ولا يُطفئ');
    }

    // ── ② المهلة: سماحيةُ الانصراف ──────────────────────────────────────

    public function test_report_grace_minutes_moves_the_deadline(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '23:59');
        $this->hubSetting('work.report_required', '1');
        $this->hubSetting('work.report_cutoff_time', '');
        [, $e] = $this->linked();
        $date = $this->workedDay($e, '18:00');          // بعد الانصرافِ بساعة

        $this->hubSetting('work.report_grace_minutes', '30');      // المهلة ١٧:٣٠ ⇒ مضت
        $tight = $this->verdict($e, $date);
        $this->hubSetting('work.report_grace_minutes', '240');     // المهلة ٢١:٠٠ ⇒ لم تمضِ
        $loose = $this->verdict($e, $date);

        $this->assertNotSame(DWC::REPORT_PENDING, $tight, 'مهلةٌ قصيرةٌ مضت والحكمُ ما زال «بانتظار»');
        $this->assertSame(DWC::REPORT_PENDING, $loose,
            'مهلةٌ واسعةٌ لم تمضِ بعد — ومع ذلك أُدين الموظّفُ قبل وقتِه');
    }

    // ── ③ والحدُّ اليوميُّ النهائيُّ يُبعِد المهلةَ لا يُقرّبها ────────────

    public function test_the_daily_cutoff_extends_the_deadline_when_it_is_later(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '23:59');
        $this->hubSetting('work.report_required', '1');
        $this->hubSetting('work.report_grace_minutes', '30');       // المهلةُ من الانصراف ١٧:٣٠
        [, $e] = $this->linked();
        $date = $this->workedDay($e, '18:00');

        $this->hubSetting('work.report_cutoff_time', '');
        $none = $this->verdict($e, $date);
        $this->hubSetting('work.report_cutoff_time', '23:00');      // الأبعد ⇒ لم تمضِ
        $late = $this->verdict($e, $date);

        $this->assertNotSame(DWC::REPORT_PENDING, $none, 'بلا حدٍّ نهائيٍّ مضت المهلةُ ولم يتغيّر الحكم');
        $this->assertSame(DWC::REPORT_PENDING, $late,
            'الحدُّ النهائيُّ ٢٣:٠٠ لم يُؤخّر المهلة — «الأبعدُ من النقطتين» غيرُ مُطبَّق');
    }

    // ── ④ وسياسةُ الناقصِ تختار العقوبة ─────────────────────────────────

    public function test_the_missing_report_policy_chooses_the_verdict(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '23:59');
        $this->hubSetting('work.report_required', '1');
        $this->hubSetting('work.report_cutoff_time', '');
        $this->hubSetting('work.report_grace_minutes', '30');
        [, $e] = $this->linked();
        $date = $this->workedDay($e, '20:00');          // بعدَ المهلةِ يقيناً

        /*
         * **ثلاثُ سياساتٍ ⇒ ثلاثةُ آثارٍ مختلفة**، والحقيقةُ واحدةٌ لا تتبدّل.
         * القياسُ الفعليّ على المشهدِ نفسِه:
         *
         *   absence_equivalent → effective = absent_due_to_missing_report
         *   non_compliant      → effective = non_compliant
         *   warning_only       → effective = present
         *   وفي الثلاثةِ جميعاً: state = present_without_report · compliance = missing
         */
        $seen = [];
        foreach ([DWC::POLICY_ABSENCE, DWC::POLICY_NON_COMPLIANT, DWC::POLICY_WARNING] as $policy) {
            $this->hubSetting('work.missing_report_policy', $policy);
            $cell = DWC::resolve($e, $date);
            $seen[$policy] = (string) $cell['effective'];

            /*
             * **والحقيقةُ لا تتحرّك بالسياسة** (§6: الحقائقُ الثلاث منفصلة). ما وقع
             * أنّه **حضر ولم يُقدّم تقريراً**، وهذا لا يتبدّل بقرارِ الإدارة في
             * عقوبته. فالسياسةُ تُحرّك الأثرَ الفعّالَ وحدَه، والحالةُ القانونيّةُ
             * تبقى الشهادةَ على ما جرى.
             */
            $this->assertSame(DWC::PRESENT_WITHOUT_REPORT, (string) $cell['state'],
                "سياسةُ «{$policy}» غيّرت الحقيقةَ القانونيّةَ نفسَها — والحضورُ الفيزيائيُّ لا تمحوه عقوبة");
            $this->assertSame('missing', (string) $cell['compliance'],
                "سياسةُ «{$policy}» غيّرت وصفَ الامتثال — والتقريرُ ناقصٌ في الثلاثةِ سواء");
        }

        $this->assertSame(DWC::ABSENT_DUE_TO_MISSING_REPORT, $seen[DWC::POLICY_ABSENCE],
            'سياسةُ «معادِلُ الغياب» لم تُنتج غياباً في الأثرِ الفعّال — والمسيّرُ يُبنى عليه');
        $this->assertSame(DWC::POLICY_NON_COMPLIANT, $seen[DWC::POLICY_NON_COMPLIANT],
            'سياسةُ «مخالفةُ امتثال» لم تُنتج مخالفةً — وهي الوسطى بين التنبيهِ والغياب');
        $this->assertSame('present', $seen[DWC::POLICY_WARNING],
            'سياسةُ «تنبيهٌ فقط» ما زالت تُنقص من يومِه — المفتاحُ لا يُخفّف');
        $this->assertCount(3, array_unique($seen),
            'سياستان أو أكثرُ تُنتجان الأثرَ نفسَه — فبعضُ الخياراتِ زينةٌ في القائمة');
    }

    // ── ⑤ وسماحيةُ التأخّرِ الصباحيّ تُحرّك حدَّ «متأخر» ──────────────────

    public function test_late_grace_moves_the_lateness_line(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '08:00');

        $this->hubSetting('work.late_grace', '5');
        $strict = DWC::lateArrival(Workday::PRESENT, '08:20');
        $this->hubSetting('work.late_grace', '60');
        $lenient = DWC::lateArrival(Workday::PRESENT, '08:20');

        $this->assertTrue($strict, 'سماحيةُ ٥ دقائق ووصولٌ ٠٨:٢٠ — ولم يُعدّ متأخّراً');
        $this->assertFalse($lenient, 'سماحيةُ ٦٠ دقيقة ووصولٌ ٠٨:٢٠ — وأُعلن متأخّراً رغمها');
    }

    // ── ⑥ واشتراطُ مراجعةِ المدير يظهر في الخليّةِ نفسِها ────────────────

    public function test_review_required_reaches_the_resolved_cell(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '23:59');
        [, $e] = $this->linked();
        $date = $this->workedDay($e);

        $this->hubSetting('work.review_required', '1');
        $on = DWC::resolve($e, $date);
        $this->hubSetting('work.review_required', '0');
        $off = DWC::resolve($e, $date);

        $this->assertTrue((bool) $on['review_required'], 'الاشتراطُ مُفعَّلٌ ولا يصل خليّةَ اليوم');
        $this->assertFalse((bool) $off['review_required'], 'الاشتراطُ مُطفأٌ والخليّةُ ما زالت تطلب المراجعة');
    }

    // ── ⑦ والنسبةُ المقترحة: تُطبَّق آليّاً أم تنتظر اعتماداً ─────────────

    public function test_progress_auto_decides_whether_a_suggestion_is_applied(): void
    {
        $this->seedCore();
        [$u] = $this->linked();
        $p = Project::create(['name' => 'مشروعُ النسبة', 'status' => 'قيد التنفيذ']);

        $this->hubSetting('work.progress_auto', '1');
        $t1 = Task::create(['title' => 'مهمّةٌ آليّة', 'project_id' => $p->id, 'progress' => 0]);
        $this->actingAs($u);
        WorkUpdate::create(['created_by' => $u->id, 'task_id' => $t1->id, 'project_id' => $p->id,
            'work_date' => '2026-09-14', 'hours' => 1, 'progress' => 50, 'done' => 'عملٌ منجَز']);

        $this->hubSetting('work.progress_auto', '0');
        $t2 = Task::create(['title' => 'مهمّةٌ باقتراح', 'project_id' => $p->id, 'progress' => 0]);
        WorkUpdate::create(['created_by' => $u->id, 'task_id' => $t2->id, 'project_id' => $p->id,
            'work_date' => '2026-09-14', 'hours' => 1, 'progress' => 50, 'done' => 'عملٌ منجَز']);

        $this->assertSame(50.0, (float) $t1->fresh()->progress,
            'الإعدادُ يسمح بالتطبيق الآليّ ولم تتحرّك نسبةُ المهمّة');
        $this->assertSame(0.0, (float) $t2->fresh()->progress,
            'الإعدادُ يمنع التطبيقَ الآليَّ والنسبةُ كُتبت قسراً — تقدّمٌ من طرفٍ واحد');
        $this->assertSame(50.0,
            (float) (($t2->fresh()->meta['suggested_progress']['pct']) ?? 0),
            'المنعُ ابتلع الاقتراحَ أيضاً — فلا آليّةَ ولا اقتراحَ يعتمده المدير');
    }

    // ── ⑧ والتقاطُ الموقعِ عند الحضور: يُلتقط أو يُطرَح ──────────────────

    public function test_geo_capture_is_actually_switched(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '23:59');
        [$u1, $e1] = $this->linked();

        $this->hubSetting('work.geo', '1');
        $this->actingAs($u1)->post('/workday/check-in', ['mode' => 'مكتب', 'geo' => '29.37,47.97']);
        $withGeo = Attendance::where('emp_id', $e1->id)->orderByDesc('id')->first();

        [$u2, $e2] = $this->linked();
        $this->hubSetting('work.geo', '0');
        $this->actingAs($u2)->post('/workday/check-in', ['mode' => 'مكتب', 'geo' => '29.37,47.97']);
        $noGeo = Attendance::where('emp_id', $e2->id)->orderByDesc('id')->first();

        $this->assertSame('29.37,47.97', $withGeo->meta['checkin']['geo'] ?? null,
            'الالتقاطُ مُفعَّلٌ والموقعُ لم يُخزَّن');
        $this->assertArrayNotHasKey('geo', (array) ($noGeo->meta['checkin'] ?? []),
            'الالتقاطُ مُطفأٌ والموقعُ خُزِّن رغم ذلك — مفتاحُ خصوصيّةٍ لا يُطفئ شيئاً');
    }
}
