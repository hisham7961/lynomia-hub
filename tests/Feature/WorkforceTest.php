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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **محرك يوم العمل: حضورٌ بضغطة، وتقريرٌ يغذّي المشاريع، وغيابٌ لا يُفترى.**
 *
 * كان الحضور صفَّ إدخالٍ يدويّ بلا زرٍّ ولا منطق، وتحديثاتُ العمل نصوصاً
 * ميتةً لا تصل مهمةً ولا ساعة. صار اليومُ حياً: حضورٌ بوضعه ومشروعه،
 * وانصرافٌ يحسب ويقيّم، وبنودُ التقرير تغذّي «الوقت الفعلي» على المهام
 * فتعمل الربحيةُ والقدراتُ بلا إدخالٍ مزدوج.
 *
 * ما يحرسه هذا الملف:
 *  1) الحضور/الانصراف: مرةً واحدة، بساعاتٍ محسوبة، وسماحيةِ تأخيرٍ من الإعداد.
 *  2) **غيابُ التقرير ≠ غياب**: حالتُه «حاضر — بلا تقرير»، والغيابُ يختمه
 *     كنسُ نهاية اليوم حصراً — لا في عطلةٍ ولا لصاحب إجازةٍ معتمدة.
 *  3) بنودٌ متعددة بمشاريعَ متعددة في اليوم، والساعاتُ تُصالَح مع المهمة
 *     إنشاءً وتعديلاً وحذفاً، والنسبةُ المقترحة بسياسة المنشأة.
 *  4) شاشةُ المدير خلف صلاحيتها، والموظفُ بلا ملفٍ مربوطٍ يُرفض بلطف.
 */
class WorkforceTest extends TestCase
{
    protected function employee(?User $u = null): array
    {
        $u = $u ?: User::create(['name' => 'أحمد', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => Role::first()->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $e = Employee::create(['name' => $u->name, 'status' => 'نشط', 'user_id' => $u->id]);

        return [$u, $e];
    }

    /* ────────── ١) الحضور والانصراف ────────── */

    public function test_check_in_and_out_compute_hours_and_refuse_duplicates(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '23:59');       // لا تأخيرَ في هذا الاختبار
        [$u, $e] = $this->employee();

        $this->actingAs($u)->post('/workday/check-in', ['mode' => 'مكتب'])
            ->assertRedirect()->assertSessionHas('ok');

        $row = Workday::today($e->id);
        $this->assertNotNull($row->time_in);
        $this->assertSame('حاضر', $row->status);
        $this->assertSame('مكتب', $row->mode);
        $this->assertNotNull(data_get($row->meta, 'checkin.ip'), 'لحظةُ الحضور تُوثَّق بعنوانها');

        // النداءُ الثاني لا يكرر صفاً ولا يدهس الوقت
        $this->actingAs($u)->post('/workday/check-in', [])->assertSessionHas('err');
        $this->assertSame(1, Attendance::where('emp_id', $e->id)->count());

        // الانصراف: ساعاتٌ محسوبةٌ لا مكتوبة
        $this->actingAs($u)->post('/workday/check-out')->assertSessionHas('ok');
        $row = $row->fresh();
        $this->assertNotNull($row->time_out);
        $this->assertIsNumeric($row->hours);
        $this->actingAs($u)->post('/workday/check-out')->assertSessionHas('err', fn ($m) => str_contains($m, 'مسجَّل'));
    }

    public function test_late_arrival_is_flagged_by_the_configurable_grace(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '00:00');
        $this->hubSetting('work.late_grace', '0');
        [$u, $e] = $this->employee();

        $this->actingAs($u)->post('/workday/check-in', []);
        $this->assertSame('متأخر', Workday::today($e->id)->status,
            'بعد البداية والسماحية = متأخر — وسمُ مراجعةٍ لا عقوبة');
    }

    public function test_missing_report_is_a_review_state_never_absence(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_start', '23:59');
        [$u, $e] = $this->employee();

        // حضر وانصرف بلا بندِ عملٍ واحد ⇒ «حاضر — بلا تقرير»
        $this->actingAs($u)->post('/workday/check-in', []);
        $this->actingAs($u)->post('/workday/check-out');
        $this->assertSame('حاضر — بلا تقرير', Workday::today($e->id)->status);

        // ولو كتب بنداً قبل الانصراف لبقي حاضراً
        [$u2, $e2] = $this->employee();
        $p = Project::create(['name' => 'مشروع اليوم']);
        $this->actingAs($u2)->post('/workday/check-in', []);
        WorkUpdate::create(['project_id' => $p->id, 'done' => 'أنجزتُ الإعداد', 'hours' => 2]);
        $this->actingAs($u2)->post('/workday/check-out');
        $this->assertSame('حاضر', Workday::today($e2->id)->status);

        // وبإطفاء اشتراط التقرير لا مراجعةَ أصلاً
        $this->hubSetting('work.report_required', '0');
        [$u3, $e3] = $this->employee();
        $this->actingAs($u3)->post('/workday/check-in', []);
        $this->actingAs($u3)->post('/workday/check-out');
        $this->assertSame('حاضر', Workday::today($e3->id)->status);
    }

    public function test_an_approved_leave_wins_the_final_state(): void
    {
        $this->seedCore();
        [$u, $e] = $this->employee();
        Employee::whereKey($e->id)->update(['leave_bal' => 30]);
        LeaveRequest::create(['emp_id' => $e->id, 'type' => 'إجازة سنوية',
            'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(),
            'days' => 1, 'status' => 'معتمد']);

        $this->actingAs($u)->post('/workday/check-in', []);
        $this->actingAs($u)->post('/workday/check-out');
        $this->assertSame('إجازة', Workday::today($e->id)->status,
            'الإجازةُ المعتمدة تغلب — لا يُحاسَب على تقريرٍ في يوم إجازته');
    }

    public function test_absence_is_stamped_only_by_the_end_of_day_sweep(): void
    {
        $this->seedCore();
        // آخرُ اثنين مضى: يومُ عملٍ مضمونٌ خارج عطلة (5,6 الافتراضية)
        $day = now()->subDay();
        while (in_array((int) $day->format('N'), [5, 6], true)) $day = $day->subDay();
        $date = $day->toDateString();

        [$u1, $e1] = $this->employee();                       // لم يحضر ⇒ غائب
        [$u2, $e2] = $this->employee();                       // حضر ⇒ لا يُمس
        Attendance::create(['emp_id' => $e2->id, 'date' => $date, 'time_in' => '09:00', 'status' => 'حاضر']);
        [$u3, $e3] = $this->employee();                       // إجازة معتمدة ⇒ لا غياب
        Employee::whereKey($e3->id)->update(['leave_bal' => 30]);
        LeaveRequest::create(['emp_id' => $e3->id, 'type' => 'إجازة مرضية',
            'date_from' => $date, 'date_to' => $date, 'days' => 1, 'status' => 'معتمد']);

        $this->assertSame(1, Workday::close($date), 'غائبٌ واحد لا ثلاثة');
        $this->assertSame('غائب', Workday::today($e1->id, $date)->status);
        $this->assertSame('حاضر', Workday::today($e2->id, $date)->status);
        $this->assertNull(Workday::today($e3->id, $date), 'صاحبُ الإجازة بلا صفِّ غياب');

        // idempotent: الكنسُ الثاني لا يكرر
        $this->assertSame(0, Workday::close($date));

        // والعطلةُ ليست غياباً أبداً
        $friday = now()->subDay();
        while ((int) $friday->format('N') !== 5) $friday = $friday->subDay();
        $this->assertSame(0, Workday::close($friday->toDateString()));
    }

    /* ────────── ٣) بنود التقرير تغذّي المهام ────────── */

    public function test_work_entries_feed_task_hours_and_reconcile_on_edit_and_delete(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $p = Project::create(['name' => 'مشروع التغذية']);
        $t = Task::create(['title' => 'ترحيل البيانات', 'project_id' => $p->id, 'est_h' => 10, 'act_h' => 3]);

        $w = WorkUpdate::create(['project_id' => $p->id, 'task_id' => $t->id,
            'done' => 'رحّلتُ العملاء', 'hours' => 2.5]);
        $this->assertSame(5.5, (float) $t->fresh()->act_h, 'ساعاتُ البند دخلت المهمة');
        $this->assertSame(now()->toDateString(), $w->fresh()->work_date->toDateString(), 'بلا تاريخٍ = بندُ اليوم');

        $w->update(['hours' => 4]);
        $this->assertSame(7.0, (float) $t->fresh()->act_h, 'التعديلُ يُصالَح بالفارق لا يُضاف فوقه');

        $w->delete();
        $this->assertSame(3.0, (float) $t->fresh()->act_h, 'الحذفُ يستردّ — لا ساعاتَ يتيمة');
    }

    public function test_suggested_progress_respects_the_policy(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $p = Project::create(['name' => 'مشروع النسب']);
        $t = Task::create(['title' => 'واجهة الدفع', 'project_id' => $p->id, 'progress' => 40]);

        // السياسة الافتراضية: اقتراحٌ على المهمة لا كتابةٌ قسرية
        WorkUpdate::create(['project_id' => $p->id, 'task_id' => $t->id,
            'done' => 'أنهيتُ النماذج', 'hours' => 1, 'progress' => 80]);
        $fresh = $t->fresh();
        $this->assertSame(40.0, (float) $fresh->progress, 'النسبةُ لم تُكتب من طرفٍ واحد');
        $this->assertSame(80.0, (float) data_get($fresh->meta, 'suggested_progress.pct'), 'بل اقتراحاً لمديرها');

        // وبالسياسة الآلية تُكتب — بسقف ٩٩: المئةُ قرارُ إغلاق لا تقدير
        $this->hubSetting('work.progress_auto', '1');
        WorkUpdate::create(['project_id' => $p->id, 'task_id' => $t->id,
            'done' => 'اكتمل كل شيء', 'hours' => 1, 'progress' => 100]);
        $this->assertSame(99.0, (float) $t->fresh()->progress);
    }

    public function test_multiple_projects_in_one_day_and_client_inheritance(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $c = \App\Models\Client::create(['name' => 'عميل التقرير', 'stage' => 'عميل حالي']);
        $pa = Project::create(['name' => 'مشروع داخلي']);
        $pb = Project::create(['name' => 'مشروع العميل', 'client_id' => $c->id]);

        WorkUpdate::create(['project_id' => $pa->id, 'done' => 'صباحاً داخلي', 'hours' => 3]);
        WorkUpdate::create(['project_id' => $pb->id, 'done' => 'مساءً للعميل', 'hours' => 2]);

        $today = WorkUpdate::whereDate('work_date', now()->toDateString())->get();
        $this->assertSame(2, $today->count(), 'يومٌ واحد — مشروعان وبندان');
        $this->assertSame($c->id, $today->firstWhere('project_id', $pb->id)->client_id,
            'بندُ مشروعِ العميل ورث عميلَه بلا سؤال');
        $this->assertNull($today->firstWhere('project_id', $pa->id)->client_id);
    }

    /* ────────── ٤) الأبواب والبوابات ────────── */

    public function test_the_team_screen_and_the_widget_respect_their_gates(): void
    {
        $this->seedCore();
        [$u, $e] = $this->employee();
        $this->actingAs($u)->post('/workday/check-in', []);

        // المدير (hr.v) يرى شاشة الفريق بأرقام اليوم واسم الموظف
        $this->actingAs($this->owner)->get('/workforce')
            ->assertOk()->assertSee('فريقي اليوم')->assertSee($e->name);

        // من لا يملك hr.v: 403
        $role = Role::create(['name' => 'بلا HR', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);
        $blind = User::create(['name' => 'محجوب', 'email' => 'nohr@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $this->actingAs($blind)->get('/workforce')->assertForbidden();

        // مستخدمٌ بلا ملف موظفٍ مربوط: بطاقةُ «يومي» لا تظهر، والحضورُ يُرفض بلطف
        $this->assertNull(Workday::emp($blind));
        $this->actingAs($blind)->post('/workday/check-in', [])
            ->assertRedirect()->assertSessionHas('err', fn ($m) => str_contains($m, 'ملف'));

        // والرئيسية تحمل البطاقة لصاحب الملف
        $this->actingAs($u)->get('/')->assertOk()->assertSee('يومي');
    }
}
