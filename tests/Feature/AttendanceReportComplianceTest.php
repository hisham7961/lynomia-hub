<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkUpdate;
use App\Support\Workday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الحضور × التقرير اليومي × الامتثال — الحقائقُ الثلاث منفصلة.**
 *
 * العيبُ المُبلَّغ: موظفٌ حضر، انصرف، ثمّ قدّم تقريرَه — فصُنِّف يومُه «حاضر —
 * بلا تقرير» خطأً. الجذرُ (ROOT_CAUSE.md): الحالةُ تُحسَب مرّةً عند الانصراف
 * وتُخزَّن، ولا يُعيد تقييمَها تقديمُ التقريرِ بعده؛ و«بلا تقرير» تطمس الحضورَ
 * الفيزيائيّ في عمودِ `status` واحد.
 *
 * ما يحرسه هذا الملف — الحقائقُ الثلاث (§6):
 *  - **الحضورُ الفيزيائيّ** (حاضر/متأخر/ميداني/عن بعد) لا يُطمَس أبداً.
 *  - **الامتثالُ التقريريّ** (قُدِّم/ناقص/بانتظار) يُشتقّ مركزيّاً لا يُخزَّن في status.
 *  - **الأثرُ الفعّال للموارد البشرية** (غياب بسبب عدم تقديم التقرير) بعد المهلة
 *    وبالسياسة — مع بقاء ختمَي الحضور/الانصراف كما هما.
 */
class AttendanceReportComplianceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /** موظفٌ (E) ← مستخدمٌ (U) حيث E.id ≠ U.id — الجسرُ القانونيّ Employee.user_id ↔ User.id.
     *  دورٌ منفِّذٌ عاديّ: يدير تحديثاتِ عمله (updates) لا مسؤولَ موارد بشرية ولا مالك. */
    protected function linkedEmployee(): array
    {
        $role = Role::firstOrCreate(['name' => 'موظّفٌ منفّذ'],
            ['scope' => 'all', 'flags' => [],
             'matrix' => ['updates' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0], 'tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'أحمد', 'email' => Str::random(10) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => $u->name, 'status' => 'نشط', 'user_id' => $u->id]);

        return [$u, $e];
    }

    /* ═══════════ §102/§5/§108 — العيبُ الأصليّ: تقريرٌ بعد الانصراف ═══════════ */

    public function test_report_after_checkout_is_recognized_not_present_without_report(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '23:59');       // لا تأخيرَ في هذا الاختبار
        [$u, $e] = $this->linkedEmployee();

        // §108: المعرّفان مختلفان فعلاً — لا اعتمادَ على تصادفِ UUID
        $this->assertNotSame($e->id, $u->id, 'Employee.id يجب أن يخالف User.id');

        // 1) حضر  2) انصرف بلا تقرير  3) ثمّ قدّم تقريرَه اليوميّ بعد الانصراف
        $this->actingAs($u)->post('/workday/check-in', ['mode' => 'مكتب']);
        $this->actingAs($u)->post('/workday/check-out');

        $p = Project::create(['name' => 'مشروع اليوم']);
        $this->actingAs($u);
        WorkUpdate::create(['project_id' => $p->id, 'done' => 'أنجزتُ ترحيلَ العملاء', 'hours' => 4]);

        $row = Workday::today($e->id);

        // الحضورُ الفيزيائيّ لم يُطمَس: ليس «حاضر — بلا تقرير»
        $this->assertNotSame(Workday::NO_REPORT, $row->status,
            'تقريرٌ قُدِّم بعد الانصراف يجب ألّا يترك اليومَ «حاضر — بلا تقرير»');
        $this->assertSame(Workday::PRESENT, $row->status, 'الحضورُ الفيزيائيّ محفوظ: حاضر');

        // الامتثالُ التقريريّ يُقرّ بالتقرير — من المُحلِّل المركزيّ (§101)
        $c = \App\Support\DailyWorkCompliance::resolve($e);
        $this->assertTrue($c['report_submitted'], 'has_report = true');
        $this->assertSame(\App\Support\DailyWorkCompliance::PRESENT_REPORTED, $c['state']);
        $this->assertSame('present', $c['effective'], 'الأثرُ الفعّال: حاضر/ممتثل');
    }

    /* ═══════════ أدواتٌ مساعدة ═══════════ */

    /** صفُّ حضورٍ مباشرٌ (لضبطِ الأوقاتِ والتاريخ) */
    protected function attendance(Employee $e, string $date, ?string $in = '08:00', ?string $out = '16:00', array $extra = []): Attendance
    {
        return Attendance::create(array_merge([
            'emp_id' => $e->id, 'date' => $date, 'time_in' => $in, 'time_out' => $out,
            'status' => Workday::PRESENT, 'hours' => 8,
        ], $extra));
    }

    /** بندُ عملٍ صالحٌ لمستخدمٍ في تاريخ */
    protected function report(User $u, string $date, ?Project $p = null, string $done = 'أنجزتُ عملَ اليوم'): WorkUpdate
    {
        $this->actingAs($u);
        return WorkUpdate::create(['project_id' => $p?->id, 'done' => $done, 'hours' => 3, 'work_date' => $date]);
    }

    /* ═══════════ §103 — لا تقرير: بانتظار ثم حضورٌ بلا تقرير بعد المهلة ═══════════ */

    public function test_no_report_is_pending_before_deadline_and_missing_after(): void
    {
        $this->seedCore();
        $this->hubSetting('work.report_grace_minutes', '0');
        $this->hubSetting('work.report_cutoff_time', '');
        $this->hubSetting('work.missing_report_policy', 'absence_equivalent');
        [$u, $e] = $this->linkedEmployee();
        $date = '2026-09-07';

        // قبل المهلة: الآن 08:30 والانصراف لم يقع — وردية مفتوحة/بانتظار
        Carbon::setTestNow(Carbon::parse($date . ' 08:30:00', config('app.timezone')));
        $this->attendance($e, $date, '08:00', null);   // بلا انصراف بعد
        $c = \App\Support\DailyWorkCompliance::resolve($e, $date);
        $this->assertSame(\App\Support\DailyWorkCompliance::CHECKED_IN, $c['state']);
        $this->assertSame('present', $c['effective'], 'وردية مفتوحة — لا مخالفة (§106)');

        // انصرف 09:00، والآن 18:00 (بعد المهلة grace=0) ⇒ حضورٌ بلا تقرير + غياب محتسَب
        Carbon::setTestNow(Carbon::parse($date . ' 18:00:00', config('app.timezone')));
        Attendance::where('emp_id', $e->id)->update(['time_out' => '09:00']);
        $c = \App\Support\DailyWorkCompliance::resolve($e, $date);
        $this->assertSame(\App\Support\DailyWorkCompliance::PRESENT_WITHOUT_REPORT, $c['state']);
        $this->assertSame('absent_due_to_missing_report', $c['effective']);
        $this->assertSame(Workday::PRESENT, $c['physical'], 'الحضورُ الفيزيائيُّ لا يُطمَس (§7)');
        $this->assertNotNull($c['time_in'], 'ختمُ الحضورِ باقٍ');
    }

    /* ═══════════ §104 — تقريرٌ متأخّر: يُحفَظ تأخّرُه ويُراجَع ═══════════ */

    public function test_late_report_preserves_lateness_and_needs_review(): void
    {
        $this->seedCore();
        $this->hubSetting('work.report_grace_minutes', '0');
        [$u, $e] = $this->linkedEmployee();
        $date = '2026-09-07';

        Carbon::setTestNow(Carbon::parse($date . ' 18:00:00', config('app.timezone')));
        $this->attendance($e, $date, '08:00', '09:00');
        $this->report($u, $date);   // قُدِّم 18:00 — بعد مهلة 09:00

        $c = \App\Support\DailyWorkCompliance::resolve($e, $date);
        $this->assertSame(\App\Support\DailyWorkCompliance::LATE_REPORT, $c['state']);
        $this->assertTrue($c['late'], 'التأخّرُ محفوظ');
        $this->assertTrue($c['needs_review'], 'يحتاج مراجعة (§40)');
    }

    /* ═══════════ §105 — إجازة: لا تقرير مطلوب، لا مخالفة ═══════════ */

    public function test_approved_leave_requires_no_report_and_no_warning(): void
    {
        $this->seedCore();
        $this->hubSetting('work.report_grace_minutes', '0');
        [$u, $e] = $this->linkedEmployee();
        $date = '2026-09-07';
        Carbon::setTestNow(Carbon::parse($date . ' 18:00:00', config('app.timezone')));

        Employee::whereKey($e->id)->update(['leave_bal' => 30]);
        LeaveRequest::create(['emp_id' => $e->id, 'type' => 'إجازة سنوية',
            'date_from' => $date, 'date_to' => $date, 'days' => 1, 'status' => 'معتمد']);
        $this->attendance($e, $date, '08:00', '09:00');

        $c = \App\Support\DailyWorkCompliance::resolve($e, $date);
        $this->assertSame(\App\Support\DailyWorkCompliance::NOT_REQUIRED, $c['state']);
        $this->assertSame('leave', $c['effective']);
        $this->assertFalse($c['report_required']);
    }

    /* ═══════════ §106 — وردية مفتوحة: لا غياب-تقرير نهائيّ ═══════════ */

    public function test_open_shift_is_never_a_final_missing_report(): void
    {
        $this->seedCore();
        $this->hubSetting('work.report_grace_minutes', '0');
        [$u, $e] = $this->linkedEmployee();
        $date = '2026-09-07';
        Carbon::setTestNow(Carbon::parse($date . ' 18:00:00', config('app.timezone')));
        $this->attendance($e, $date, '08:00', null);   // بلا انصراف

        $c = \App\Support\DailyWorkCompliance::resolve($e, $date);
        $this->assertSame(\App\Support\DailyWorkCompliance::CHECKED_IN, $c['state']);
        $this->assertNotSame('absent_due_to_missing_report', $c['effective']);
    }

    /* ═══════════ §107 — تقريرٌ قبل الانصراف: يُعرَف صحيحاً ═══════════ */

    public function test_report_before_checkout_is_recognized(): void
    {
        $this->seedCore();
        [$u, $e] = $this->linkedEmployee();
        $date = '2026-09-07';
        Carbon::setTestNow(Carbon::parse($date . ' 12:00:00', config('app.timezone')));
        $this->report($u, $date);                       // تقريرٌ أولاً
        $this->attendance($e, $date, '08:00', '16:00'); // ثم الانصراف

        $c = \App\Support\DailyWorkCompliance::resolve($e, $date);
        $this->assertTrue($c['report_submitted']);
        $this->assertSame(\App\Support\DailyWorkCompliance::PRESENT_REPORTED, $c['state']);
    }

    /* ═══════════ §110 — يومٌ متعدّدُ المشاريع: ممتثل، بلا عدٍّ مزدوج ═══════════ */

    public function test_multi_project_day_is_compliant_and_hours_not_double_counted(): void
    {
        $this->seedCore();
        [$u, $e] = $this->linkedEmployee();
        $date = '2026-09-07';
        Carbon::setTestNow(Carbon::parse($date . ' 12:00:00', config('app.timezone')));
        $pa = Project::create(['name' => 'مشروع أ']);
        $pb = Project::create(['name' => 'مشروع ب']);
        $this->attendance($e, $date, '08:00', '16:00');
        $this->report($u, $date, $pa, 'عملُ أ');
        $this->report($u, $date, $pb, 'عملُ ب');

        $c = \App\Support\DailyWorkCompliance::resolve($e, $date);
        $this->assertTrue($c['report_submitted']);
        $this->assertSame(2, $c['report_count']);
        $this->assertEqualsCanonicalizing([$pa->id, $pb->id], $c['projects']);
        $this->assertSame(6.0, $c['reported_hours'], 'ساعاتُ البندين تُجمَع مرّةً — لا ازدواج');
    }

    /* ═══════════ §111 — عملٌ داخليٌّ بلا مشروع: تقريرٌ صالح ═══════════ */

    public function test_non_project_work_counts_as_a_valid_report(): void
    {
        $this->seedCore();
        [$u, $e] = $this->linkedEmployee();
        $date = '2026-09-07';
        Carbon::setTestNow(Carbon::parse($date . ' 12:00:00', config('app.timezone')));
        $this->attendance($e, $date, '08:00', '16:00');
        $this->report($u, $date, null, 'عملٌ إداريٌّ داخليّ');   // project_id = null

        $c = \App\Support\DailyWorkCompliance::resolve($e, $date);
        $this->assertTrue($c['report_submitted'], 'عملٌ داخليٌّ يُرضي الاشتراط (§16)');
        $this->assertTrue($c['has_non_project']);
        $this->assertSame(\App\Support\DailyWorkCompliance::PRESENT_REPORTED, $c['state']);
    }

    /* ═══════════ §13/§24 — نائبٌ فارغٌ لا يُرضي اشتراطاً ═══════════ */

    public function test_placeholder_report_does_not_satisfy_requirement(): void
    {
        $this->seedCore();
        [$u, $e] = $this->linkedEmployee();
        $date = '2026-09-07';
        Carbon::setTestNow(Carbon::parse($date . ' 12:00:00', config('app.timezone')));
        $this->attendance($e, $date, '08:00', '16:00');
        $this->report($u, $date, null, '-');   // نائبٌ رمزيّ

        $c = \App\Support\DailyWorkCompliance::resolve($e, $date);
        $this->assertFalse($c['report_submitted'], 'الشرطةُ ليست تقريراً (§24)');
    }

    /* ═══════════ §116 — التنبيهُ لا يتكرّر عبرَ تشغيلاتِ المصالحة ═══════════ */

    public function test_missing_report_notification_is_idempotent_across_reconcile_runs(): void
    {
        $this->seedCore();
        $this->hubSetting('work.report_grace_minutes', '0');
        $this->hubSetting('work.report_reminder', '1');
        $this->hubSetting('work.missing_report_policy', 'absence_equivalent');
        [$u, $e] = $this->linkedEmployee();
        $date = '2026-09-07';
        Carbon::setTestNow(Carbon::parse($date . ' 18:00:00', config('app.timezone')));
        // حضورٌ بلا تقرير، ومهلةٌ ماضية (report_deadline_at في الماضي)
        $this->attendance($e, $date, '08:00', '09:00', ['report_deadline_at' => $date . ' 09:00:00']);

        \Illuminate\Support\Facades\Artisan::call('attendance:reconcile-reports');
        \Illuminate\Support\Facades\Artisan::call('attendance:reconcile-reports');
        \Illuminate\Support\Facades\Artisan::call('attendance:reconcile-reports');

        $n = \App\Models\HubNotification::where('user_id', $u->id)
            ->where('kind', 'daily_report_missing')->count();
        $this->assertSame(1, $n, 'تنبيهٌ واحدٌ لا ثلاثة (§39/§116)');
    }

    /* ═══════════ §117 — تغييرُ السماحية يسري فوراً على التقييم ═══════════ */

    public function test_changing_grace_immediately_changes_evaluation(): void
    {
        $this->seedCore();
        $this->hubSetting('work.report_cutoff_time', '');
        [$u, $e] = $this->linkedEmployee();
        $date = '2026-09-07';
        Carbon::setTestNow(Carbon::parse($date . ' 10:00:00', config('app.timezone')));
        $this->attendance($e, $date, '08:00', '09:00');

        // سماحيةٌ واسعة: 10:00 < 09:00+180 = 12:00 ⇒ بانتظار
        $this->hubSetting('work.report_grace_minutes', '180');
        $this->assertSame(\App\Support\DailyWorkCompliance::REPORT_PENDING,
            \App\Support\DailyWorkCompliance::resolve($e, $date)['state']);

        // تضييقُها فوراً: 10:00 > 09:00+0 ⇒ حضورٌ بلا تقرير
        $this->hubSetting('work.report_grace_minutes', '0');
        $this->assertSame(\App\Support\DailyWorkCompliance::PRESENT_WITHOUT_REPORT,
            \App\Support\DailyWorkCompliance::resolve($e, $date)['state']);
    }

    /* ═══════════ §72 — استعادةُ بندٍ محذوفٍ تُعيد ساعاتِه للمهمة ═══════════ */

    public function test_restoring_a_work_update_restores_task_hours(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $p = Project::create(['name' => 'مشروع الساعات']);
        $t = Task::create(['title' => 'مهمّة', 'project_id' => $p->id, 'act_h' => 2]);
        $w = WorkUpdate::create(['project_id' => $p->id, 'task_id' => $t->id, 'done' => 'عمل', 'hours' => 3]);
        $this->assertSame(5.0, (float) $t->fresh()->act_h);

        $w->delete();
        $this->assertSame(2.0, (float) $t->fresh()->act_h, 'الحذفُ خصم');
        $w->restore();
        $this->assertSame(5.0, (float) $t->fresh()->act_h, 'الاستعادةُ تُعيد — لا نقصَ صامت (§72)');
    }

    /* ═══════════ §112/§30 — دورةُ المراجعة: تنقيح → تعديل → قبول → قفل ═══════════ */

    public function test_review_cycle_and_accepted_report_is_locked_for_author(): void
    {
        $this->seedCore();
        [$u, $e] = $this->linkedEmployee();
        $date = now()->toDateString();
        $p = Project::create(['name' => 'مشروع المراجعة']);
        $w = $this->report($u, $date, $p, 'مسودةٌ أوّليّة');

        // المدير يطلب تنقيحاً
        \App\Support\ReportReview::needsRevision($w->fresh(), $this->owner, 'فصّل ما أنجزت');
        $this->assertSame('needs_revision', $w->fresh()->review_status);
        // إشعارُ الموظف
        $this->assertTrue(\App\Models\HubNotification::where('user_id', $u->id)
            ->where('kind', 'report_needs_revision')->exists());

        // الموظف يعدّل (مسموحٌ ما دام غيرَ مقبول)
        $this->actingAs($u)->put(route('m.update', ['updates', $w->id]),
            ['projectId' => $p->id, 'done' => 'أنجزتُ س وص وع', 'hours' => 3, '_version' => $w->fresh()->version]);
        $this->assertStringContainsString('أنجزتُ', (string) $w->fresh()->done);

        // المدير يقبل — لا يمسّ الساعات (§73)
        \App\Support\ReportReview::accept($w->fresh(), $this->owner);
        $this->assertSame('accepted', $w->fresh()->review_status);

        // §30: بعد القبول، الموظفُ لا يعدّل صامتاً
        $this->actingAs($u)->put(route('m.update', ['updates', $w->id]),
            ['projectId' => $p->id, 'done' => 'محاولةُ تغييرٍ بعد القبول', 'hours' => 3, '_version' => $w->fresh()->version])
            ->assertSessionHas('err');
        $this->assertStringNotContainsString('محاولةُ تغيير', (string) $w->fresh()->done);
    }

    /* ═══════════ §113 — لا يراجع موظّفٌ تقريرَ آخرَ بلا صلاحية ═══════════ */

    public function test_unauthorized_user_cannot_review_anothers_report(): void
    {
        $this->seedCore();
        [$u, $e] = $this->linkedEmployee();
        // مستخدمٌ ثانٍ بلا صلاحيةِ مراجعة (دورٌ بلا hr ولا updates:e ولا مالك)
        $role = Role::create(['name' => 'زميل', 'scope' => 'all', 'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);
        $other = User::create(['name' => 'زميل', 'email' => 'peer@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $p = Project::create(['name' => 'مشروع']);
        $w = $this->report($u, now()->toDateString(), $p);

        $this->assertFalse(\App\Support\ReportReview::canReview($other, $w->fresh()));
        // ولا صاحبُ التقريرِ يراجع نفسَه (فصلُ التنفيذ عن الحكم §76)
        $this->assertFalse(\App\Support\ReportReview::canReview($u, $w->fresh()));
        // المسارُ يردّ 403
        $this->actingAs($other)->post(route('reports.review.act', $w->id), ['action' => 'accept'])
            ->assertForbidden();
    }

    /* ═══════════ §114 — حسابُ العميلِ لا يرى أيَّ تقريرٍ داخليّ ═══════════ */

    public function test_client_account_cannot_reach_reports_surfaces(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [],
            'matrix' => ['updates' => ['v' => 1, 'e' => 1], 'hr' => ['v' => 1]]]);
        $client = User::create(['name' => 'حسابُ عميل', 'email' => 'cli@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => 'client', 'password_changed_at' => now()]);

        foreach (['reports.index', 'reports.review', 'reports.mine'] as $r) {
            $this->actingAs($client)->get(route($r))->assertNotFound();
        }
        // ولا الواجهةُ: my-daily تُردّ 404 للعميل
        $this->assertFalse(\App\Support\ReportReview::canReviewAny($client));
    }

    /* ═══════════ §115 — لا تسرّب تقارير/حضور عبرَ الشركات ═══════════ */

    public function test_company_isolation_in_reports_center(): void
    {
        $this->seedCore();
        $coA = \App\Models\Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = \App\Models\Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $date = now()->toDateString();

        $empA = Employee::create(['name' => 'موظفُ ألف', 'status' => 'نشط', 'company_id' => $coA->id]);
        $empB = Employee::create(['name' => 'موظفُ باء', 'status' => 'نشط', 'company_id' => $coB->id]);
        $this->attendance($empA, $date, '08:00', '16:00');
        $this->attendance($empB, $date, '08:00', '16:00');

        // مديرٌ منطَّقٌ على «ألف» بصلاحيةِ hr:v
        $role = Role::create(['name' => 'مديرُ ألف', 'scope' => 'company',
            'flags' => [], 'matrix' => ['hr' => ['v' => 1], 'updates' => ['v' => 1]], 'companies' => [$coA->id]]);
        $mgr = User::create(['name' => 'مديرُ ألف', 'email' => 'mgrA@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'companies' => [$coA->id], 'password_changed_at' => now()]);

        $html = $this->actingAs($mgr)->get(route('reports.index', ['date' => $date]))->assertOk()->getContent();
        $this->assertStringContainsString('موظفُ ألف', $html, 'يرى موظّفَ شركته');
        $this->assertStringNotContainsString('موظفُ باء', $html, 'لا يرى موظّفَ شركةٍ أخرى (§115)');
    }

    /* ═══════════ §45 — تقريرٌ بلا حضور: الحقائقُ منفصلةٌ لا تُفترى ═══════════ */

    public function test_report_without_attendance_keeps_facts_separate(): void
    {
        $this->seedCore();
        [$u, $e] = $this->linkedEmployee();
        $date = '2026-09-07';
        Carbon::setTestNow(Carbon::parse($date . ' 12:00:00', config('app.timezone')));
        $this->report($u, $date, null, 'عملٌ عن بعدٍ بلا تسجيلِ حضور');   // تقريرٌ بلا صفِّ حضور

        $c = \App\Support\DailyWorkCompliance::resolve($e, $date);
        $this->assertFalse($c['checked_in'], 'لا حضورَ مفتَرى (§45)');
        $this->assertTrue($c['report_submitted'], 'التقريرُ حقيقةٌ منفصلة');
        $this->assertNull($c['physical']);
    }

    /* ═══════════ §93/§94 — تكافؤُ الواجهة: نفسُ المُحلِّل، والعميلُ محجوب ═══════════ */

    public function test_api_v1_my_daily_uses_the_same_resolver(): void
    {
        $this->seedCore();
        $date = '2026-09-07';
        Carbon::setTestNow(Carbon::parse($date . ' 12:00:00', config('app.timezone')));
        [$u, $e] = $this->linkedEmployee();
        $p = Project::create(['name' => 'مشروع']);
        $this->attendance($e, $date, '08:00', '16:00');
        $this->report($u, $date, $p);

        $token = $this->apiToken($u);
        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/reports/my-daily?date=' . $date)->assertOk();
        $res->assertJsonPath('compliance.report_submitted', true);
        $res->assertJsonPath('compliance.state', \App\Support\DailyWorkCompliance::PRESENT_REPORTED);
    }

    public function test_api_v1_reports_deny_client_account(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'عميلُ API', 'scope' => 'all', 'flags' => [],
            'matrix' => ['updates' => ['v' => 1], 'hr' => ['v' => 1]]]);
        $client = User::create(['name' => 'عميل', 'email' => 'cliapi@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => 'client', 'password_changed_at' => now()]);
        $token = $this->apiToken($client);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/reports/my-daily')->assertNotFound();
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/reports/daily')->assertNotFound();
    }

    /* ═══════════ §121 — الاكتشافيّة: المراكزُ روابطُ ظاهرةٌ في الشريط، لا مدفونة ═══════════ */

    public function test_reports_surfaces_are_discoverable_in_the_sidebar(): void
    {
        $this->seedCore();

        // الرئيسيّة تُرسَم بلا خطأ (حارسٌ ضدّ انهيارِ الشريط بـTypeError من الكتالوج الصوريّ)
        $ownerHtml = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();
        // المدير/المالك يرى المراكزَ الثلاثةَ روابطَ مباشرةً في «الأدوات واللوحات»
        $this->assertStringContainsString('تقرير اليوم', $ownerHtml);
        $this->assertStringContainsString('مركز التقارير اليومية', $ownerHtml);
        $this->assertStringContainsString('تقارير للمراجعة', $ownerHtml);
        // روابطُ حقيقيّةٌ لا نصٌّ فقط
        $this->assertStringContainsString('href="' . route('reports.index') . '"', $ownerHtml);
        $this->assertStringContainsString('href="' . route('reports.mine') . '"', $ownerHtml);

        // موظّفٌ منفِّذٌ بلا hr:v: يرى «تقرير اليوم» ولا يرى «مركز التقارير اليومية» (§80)
        [$u, $e] = $this->linkedEmployee();
        $empHtml = $this->actingAs($u)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('تقرير اليوم', $empHtml);
        $this->assertStringNotContainsString('مركز التقارير اليومية', $empHtml,
            'مركزُ HR لا يظهر لموظّفٍ بلا صلاحيّة (لا تسريبَ ولا زحمة)');

        // حسابُ العميل: لا «تقرير اليوم» ولا مراكزُ تقاريرَ في شريطه
        $role = Role::create(['name' => 'عميلُ شريط', 'scope' => 'all', 'flags' => [],
            'matrix' => ['updates' => ['v' => 1]]]);
        $client = User::create(['name' => 'عميل', 'email' => 'clbar@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => 'client', 'password_changed_at' => now()]);
        $tl = collect(hub_top_links($client))->pluck('key')->all();
        $this->assertEmpty(array_intersect(['myreport', 'reportsc', 'reportsr'], $tl),
            'لا مراكزَ تقاريرَ داخليّةً في شريطِ العميل (§79)');
    }
}
