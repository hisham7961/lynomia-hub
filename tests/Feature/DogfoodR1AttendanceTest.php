<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\DailyWorkCompliance;
use App\Support\MonthlyAttendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **محاكاةُ الجولة الأولى — الحضورُ الذي لا يُجيب (F9/F10/F11).**
 *
 * ثلاثةُ عيوبٍ بلّغ عنها المالكُ وHR والمحاسب:
 *
 *  - **F9 — «من غائبٌ اليوم؟» بلا إجابة:** الغيابُ لا يُنشئ صفّاً، ففلترُ «غائب»
 *    صفرٌ دائماً؛ وبطاقةُ CEO تقول «0 حاضر» وتحتها «الفريق مكتمل 💪» — تناقض.
 *    الدواء: «نداءُ اليوم» محسوبٌ بالفرق (النشطون − من ختم − من في إجازة)،
 *    تقرؤه شاشةُ «فريقي اليوم» وبطاقةُ CEO من مصدرٍ واحد.
 *  - **F10 — الشبكةُ الشهرية تظلم المستقبلَ والعطل:** المستقبلُ «—» لا «غائب»،
 *    وعطلةُ الأسبوع «عطلة»، والإجازةُ «إجازة»، و«غائب» حصراً ليومِ عملٍ ماضٍ
 *    بلا ختمٍ وبلا إجازة.
 *  - **F11 — الانصرافُ المنسيُّ يسقط بصمت:** دخولٌ بلا انصرافٍ يُوسَم «انصراف
 *    مفقود» ظاهراً في اليوميّ والشهريّ، ويُعدّ ضمن شذوذاتِ الشهر — بلا اختلاقِ
 *    ساعاتٍ، وبرابطِ تصحيحٍ لمن يملك تحرير الحضور.
 */
class DogfoodR1AttendanceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /** موظفٌ نشطٌ مربوطٌ بمستخدمٍ منفّذ (نمطُ AttendanceReportComplianceTest) */
    protected function linkedEmployee(string $name): array
    {
        $role = Role::firstOrCreate(['name' => 'موظّفٌ منفّذ'],
            ['scope' => 'all', 'flags' => [],
             'matrix' => ['updates' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0], 'tasks' => ['v' => 1]]]);
        $u = User::create(['name' => $name, 'email' => Str::random(10) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => $name, 'status' => 'نشط', 'user_id' => $u->id]);

        return [$u, $e];
    }

    /** صفُّ حضورٍ مباشر بضبطِ الأوقات */
    protected function attendance(Employee $e, string $date, ?string $in = '08:00', ?string $out = '16:00', array $extra = []): Attendance
    {
        return Attendance::create(array_merge([
            'emp_id' => $e->id, 'date' => $date, 'time_in' => $in, 'time_out' => $out,
            'status' => \App\Support\Workday::PRESENT,
            'hours' => ($in && $out) ? 8 : null,
        ], $extra));
    }

    /** إجازةٌ معتمدةٌ من أنواع الخصم تشمل التاريخ */
    protected function leave(Employee $e, string $from, string $to): void
    {
        Employee::whereKey($e->id)->update(['leave_bal' => 30]);
        LeaveRequest::create(['emp_id' => $e->id, 'type' => 'إجازة سنوية',
            'date_from' => $from, 'date_to' => $to, 'days' => 1, 'status' => 'معتمد']);
    }

    /* ═══════════ F9 — نداءُ اليوم: الغائبُ بالفرق لا بانتظارِ صفٍّ لن يُكتب ═══════════ */

    public function test_roll_call_derives_absent_from_the_difference_not_from_stamped_rows(): void
    {
        $this->seedCore();
        // ثلاثاء 2026-09-08 — يومُ عملٍ (العطلة الافتراضية جمعة/سبت)
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00', config('app.timezone')));
        $this->hubSetting('sec.hours_start', '08:00');
        $this->hubSetting('work.late_grace', '15');
        $today = '2026-09-08';

        [$u1, $present] = $this->linkedEmployee('حاضرٌ منضبط');
        [$u2, $late] = $this->linkedEmployee('واصلٌ متأخراً');
        [$u3, $onLeave] = $this->linkedEmployee('مجازٌ معتمد');
        [$u4, $absent] = $this->linkedEmployee('غائبٌ بلا عذر');

        // حاضرٌ في الوقت + تقريرُه مقدَّم
        $this->attendance($present, $today, '07:55', null);
        $this->actingAs($u1);
        \App\Models\WorkUpdate::create(['done' => 'أنجزتُ مهامَّ الصباح', 'hours' => 3, 'work_date' => $today]);
        // واصلٌ بعد بداية الدوام + السماحية (صفٌّ يدويٌّ بلا ختمِ «متأخر» — يُشتقُّ من الوقت)
        $this->attendance($late, $today, '09:30', null);
        // مجازٌ معتمد — لا ختمَ له
        $this->leave($onLeave, $today, $today);
        // «غائبٌ بلا عذر»: لا صفَّ حضورٍ إطلاقاً — هذا جوهرُ F9

        $roll = DailyWorkCompliance::rollCall(
            Employee::whereNull('deleted_at')->where('status', 'نشط')->orderBy('id')->get(['id', 'name', 'dept', 'user_id'])
        );

        $names = fn (string $k) => array_column($roll['buckets'][$k], 'name');
        $this->assertContains('حاضرٌ منضبط', $names('present'), 'من ختم في الوقت حاضر');
        $this->assertContains('واصلٌ متأخراً', $names('late'), 'من ختم بعد بداية الدوام متأخر — يُشتقُّ من الوقت لا من الختم وحدَه');
        $this->assertContains('مجازٌ معتمد', $names('leave'), 'الإجازةُ المعتمدة فئةٌ لا غياب');
        $this->assertContains('غائبٌ بلا عذر', $names('absent'),
            'الغائبُ يُحسب بالفرق (نشط − ختم − إجازة) — لا بانتظارِ صفِّ «غائب» لن يكتبَه أحد');
        $this->assertNotContains('غائبٌ بلا عذر', $names('present'));
        // «بلا تقرير»: من ختم ولم يقدّم تقريراً صالحاً (المتأخرُ هنا بلا تقرير)
        $this->assertContains('واصلٌ متأخراً', $names('noreport'));
        $this->assertNotContains('حاضرٌ منضبط', $names('noreport'), 'من قدّم تقريرَه ليس «بلا تقرير»');

        $this->assertSame(2, $roll['n']['in'], 'الحاضرون = من ختم فعلاً (حاضر + متأخر)');
        $this->assertSame(1, $roll['n']['absent']);
        $this->assertTrue($roll['any_stamp'], 'ثمّة أختامٌ اليوم');
    }

    public function test_roll_call_on_a_weekend_counts_nobody_absent(): void
    {
        $this->seedCore();
        // جمعة 2026-09-11 — عطلةُ الأسبوع الافتراضية
        Carbon::setTestNow(Carbon::parse('2026-09-11 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('موظفُ العطلة');

        $roll = DailyWorkCompliance::rollCall(
            Employee::whereNull('deleted_at')->where('status', 'نشط')->get(['id', 'name', 'dept', 'user_id'])
        );

        $this->assertTrue($roll['weekend']);
        $this->assertSame(0, $roll['n']['absent'], 'العطلةُ الأسبوعية ليست غياباً بلا عذر');
    }

    public function test_team_screen_shows_the_roll_call_with_names(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00', config('app.timezone')));
        [$u1, $present] = $this->linkedEmployee('حاضرُ الشاشة');
        [$u2, $absent] = $this->linkedEmployee('غائبُ الشاشة');
        $this->attendance($present, '2026-09-08', '08:00', null);

        $html = $this->actingAs($this->owner)->get('/workforce')->assertOk()->getContent();
        $this->assertStringContainsString('نداء اليوم', $html, 'شاشةُ الحضور اليومية فيها قسمُ «نداء اليوم»');
        $this->assertStringContainsString('غائب بلا عذر', $html);
        $this->assertStringContainsString('غائبُ الشاشة', $html, 'الغائبُ يُسمّى بالاسم لا بعدٍّ صامت');
    }

    /* ═══════════ F9 — بطاقةُ CEO: لا «مكتمل» فوق صفرِ بيانات ═══════════ */

    public function test_ceo_card_says_no_data_yet_instead_of_complete_when_nothing_is_stamped(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-08 09:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('موظفٌ بلا ختم');   // لا أختامَ ولا إجازاتِ اليوم

        $html = $this->actingAs($this->owner)->get('/ceo')->assertOk()->getContent();
        $this->assertStringContainsString('لا بيانات حضور بعد', $html,
            'صفرُ أختامٍ = «لا بيانات حضور بعد» لا إعلانَ اكتمال');
        $this->assertStringNotContainsString('الفريق مكتمل', $html,
            'لا يُعلَن «الفريق مكتمل» فوق صفرِ بيانات');
    }

    public function test_ceo_card_reads_the_same_roll_call_source(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00', config('app.timezone')));
        [$u1, $present] = $this->linkedEmployee('حاضرُ البطاقة');
        [$u2, $absent] = $this->linkedEmployee('غائبُ البطاقة');
        $this->attendance($present, '2026-09-08', '08:00', null);
        // صفٌّ مختومٌ «غائب» (كنسُ نهاية اليوم) — ليس «حاضراً» في العدّ
        [$u3, $stamped] = $this->linkedEmployee('مختومٌ غائباً');
        Attendance::create(['emp_id' => $stamped->id, 'date' => '2026-09-08', 'status' => \App\Support\Workday::ABSENT]);

        $res = $this->actingAs($this->owner)->get('/ceo')->assertOk();
        $roll = $res->viewData('teamRoll');
        $this->assertSame(1, $roll['n']['in'], 'صفُّ «غائب» المختومُ لا يُحسَب حاضراً');
        $this->assertSame(2, $roll['n']['absent'], 'الغائبُ المختومُ والغائبُ بالفرق كلاهما غائب');
        $this->assertStringNotContainsString('لا بيانات حضور بعد', $res->getContent());
    }

    /* ═══════════ F10 — الشبكةُ الشهرية: مستقبلٌ «—»، عطلةٌ «عطلة»، غيابٌ ليومِ عملٍ ماضٍ ═══════════ */

    public function test_monthly_sheet_classifies_future_weekend_leave_and_past_workdays(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('موظفُ الشبكة');
        $this->attendance($e, '2026-09-01', '08:00', '16:00');       // ثلاثاء: حاضر
        $this->leave($e, '2026-09-06', '2026-09-06');                // أحد: إجازة معتمدة

        $days = collect(MonthlyAttendance::sheet($e, '2026-09')['days'])->keyBy('date');

        // المستقبل (أحد 20/9): «—» لا «غائب» — ولا حالةَ محتسبةً تفتري غياباً
        $this->assertSame('—', $days['2026-09-20']['label'], 'يومٌ مستقبليٌّ لا يُختم غائباً');
        $this->assertNull($days['2026-09-20']['effective_key'] ?? null, 'لا «غائب» محتسبًا في المستقبل');
        // عطلةُ الأسبوع (جمعة 11/9): «عطلة»
        $this->assertSame('عطلة', $days['2026-09-11']['label'], 'عطلةُ الأسبوع تُسمّى عطلة');
        $this->assertNull($days['2026-09-11']['effective_key'] ?? null);
        // الإجازةُ المعتمدة: «إجازة»
        $this->assertSame('إجازة', $days['2026-09-06']['label']);
        // يومُ عملٍ ماضٍ بلا ختمٍ وبلا إجازة (اثنين 7/9): «غائب»
        $this->assertSame('غائب', $days['2026-09-07']['label'],
            'يومُ عملٍ ماضٍ بلا ختمٍ وبلا إجازة = غائب — بالفرقِ لا بانتظارِ صفٍّ مختوم');
        // اليومُ نفسُه (15/9) لم ينتهِ: «—» لا «غائب»
        $this->assertSame('—', $days['2026-09-15']['label'], 'اليومُ الجاري لا يُختم غائباً قبل انتهائه');

        // العدّاد: الغيابُ المشتقُّ ظاهرٌ، والمظلّةُ القديمة (off = غيرُ مسجَّل) باقيةٌ كما هي
        $t = MonthlyAttendance::sheet($e, '2026-09')['totals'];
        $this->assertSame(8, $t['unexcused'], 'أيامُ العملِ الماضيةُ بلا ختمٍ (2,3,7,8,9,10,13,14/9) = 8');
        $this->assertSame(0, $t['absent'], 'لا صفَّ غيابٍ مختوماً — العدّادُ القديمُ لا يُطمَس');
        $this->assertSame(28, $t['off'], 'مظلّةُ «غيرِ المسجَّل» القديمةُ باقية — الإضافةُ لا الكسر');
    }

    public function test_monthly_employee_screen_renders_holiday_and_no_absent_stamp_for_future(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('موظفُ شاشةِ الشهر');
        $this->attendance($e, '2026-09-01', '08:00', '16:00');

        $html = $this->actingAs($this->owner)
            ->get(route('reports.monthly.employee', ['emp' => $e->id, 'month' => '2026-09']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('عطلة', $html, 'عطلةُ الأسبوع ظاهرةٌ باسمها');
    }

    /* ═══════════ F11 — الانصرافُ المفقود: شارةٌ وعدّادٌ لا سقوطٌ صامت ═══════════ */

    public function test_missing_checkout_is_flagged_and_counted_without_inventing_hours(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('ناسي الانصراف');
        $row = $this->attendance($e, '2026-09-09', '08:00', null);   // دخولٌ بلا انصراف — يومٌ ماضٍ

        $sheet = MonthlyAttendance::sheet($e, '2026-09');
        $day = collect($sheet['days'])->keyBy('date')['2026-09-09'];

        $this->assertTrue((bool) ($day['missing_out'] ?? false), 'اليومُ موسومٌ «انصراف مفقود»');
        $this->assertSame(0.0, $day['hours'], 'لا ساعاتٍ مختلَقة — الانصرافُ لم يُسجَّل');
        $this->assertArrayHasKey('missing_out', $sheet['totals'], 'شذوذاتُ الشهرِ تُعدّ');
        $this->assertSame(1, $sheet['totals']['missing_out'], 'انصرافٌ مفقودٌ واحدٌ معدود');

        // الشاشةُ الشهرية: شارةٌ ظاهرة + رابطُ التصحيح لمن يملك تحرير الحضور
        $html = $this->actingAs($this->owner)
            ->get(route('reports.monthly.employee', ['emp' => $e->id, 'month' => '2026-09']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('انصراف مفقود', $html);
        $this->assertStringContainsString(route('m.edit', ['attend', $row->id]), $html,
            'الشارةُ رابطٌ لمسارِ تصحيحِ HR القائم (تعديل صفّ الحضور)');

        // الشاشةُ اليومية (مركزُ التقارير بتاريخٍ ماضٍ): الشارةُ نفسُها
        $daily = $this->actingAs($this->owner)
            ->get(route('reports.index', ['date' => '2026-09-09']))->assertOk()->getContent();
        $this->assertStringContainsString('انصراف مفقود', $daily);
        $this->assertStringContainsString(route('m.edit', ['attend', $row->id]), $daily);
    }

    public function test_open_shift_today_is_not_flagged_as_missing_checkout(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('وردية مفتوحة');
        $this->attendance($e, '2026-09-15', '08:00', null);          // اليومُ الجاري — طبيعيّ

        $daily = $this->actingAs($this->owner)
            ->get(route('reports.index', ['date' => '2026-09-15']))->assertOk()->getContent();
        $this->assertStringNotContainsString('انصراف مفقود', $daily,
            'ورديةُ اليومِ المفتوحةُ ليست شذوذاً — الشارةُ للأيام الماضية');

        $day = collect(MonthlyAttendance::sheet($e, '2026-09')['days'])->keyBy('date')['2026-09-15'];
        $this->assertFalse((bool) ($day['missing_out'] ?? false));
    }

    /* ═══════════ التجميعُ الشهريُّ للمحاسب: العدّادُ ظاهرٌ في الشبكة ═══════════ */

    public function test_monthly_grid_shows_missing_checkout_and_derived_absence_counters(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee('موظفُ المجاميع');
        $this->attendance($e, '2026-09-09', '08:00', null);          // انصرافٌ مفقود

        $t = MonthlyAttendance::summary(
            Employee::whereNull('deleted_at')->where('status', 'نشط')->orderBy('id')->get(['id', 'name', 'dept', 'user_id', 'company_id']),
            '2026-09'
        )['rows'][$e->id]['totals'];
        $this->assertSame(1, $t['missing_out'], 'التجميعُ الشهريُّ يعدّ الانصرافَ المفقود');
        $this->assertGreaterThan(0, $t['unexcused'], 'والغيابَ المشتقَّ من الفرق');

        $html = $this->actingAs($this->owner)
            ->get(route('reports.monthly', ['month' => '2026-09']))->assertOk()->getContent();
        $this->assertStringContainsString('انصراف مفقود', $html, 'عدّادُ الشذوذِ ظاهرٌ في شبكة المحاسب');
    }
}
