<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ExecutionStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **ملفُّ عمل الموظف وخطُّه الزمنيّ (WP-7.3 · spec §5.3 · §5.8).**
 *
 * ما يحرسه هذا الملف:
 *  1) **قارئُ تنفيذٍ واحد:** `ExecutionStats::person` مصدرُ أرقام العمل، و
 *     `PerformanceController::peopleKpis` يقرأ منه — لا ثلاثَ نسخٍ متداخلة.
 *     ومسمارُ الانحدار: الرقمان **متطابقان عدداً** على البذور نفسِها.
 *  2) **التنطيق محفوظ:** قارئُ شركةٍ أخرى لا يرى الملفَّ أصلاً (٤٠٤)، وقارئُ
 *     شركةٍ يرى الملفَّ لا تُحسب له مهامُّ شركةٍ أخرى في بطاقات العمل.
 *  3) **صلاحيةُ الحقل:** «تقييم المدير» (`hr.perf`) و«الراتب» (`hr.salary`)
 *     يختفيان عن دورٍ محجوبٌ عنه الحقل — في لوحة الأداء وفي ملفّ الموظف معاً.
 *     (العيبُ القائم: `peopleKpis` كان يقرأ `employees.perf` خاماً.)
 *  4) **لا مراقبةَ شخصية (spec §5):** «الأفعالُ ذاتُ المعنى» قائمةُ سماحٍ من
 *     إضافة/تعديل/حذف/تصدير — لا تشمل الدخولَ ولا «عرضاً حسّاساً»، ومحصورةٌ
 *     بالوحدات التي يراها **القارئ**؛ والخطُّ الزمنيّ بلا زياراتِ صفحاتٍ خام.
 *  5) **لا سحبَ لجدولٍ كامل:** لوحُ الأداء لا يقرأ جدولَ الموظفين كلَّه ولا
 *     يستعلم لكل موظفٍ على حدة (N+1).
 */
class EmployeeWorkProfileTest extends TestCase
{
    /** نافذةُ الثلاثين يوماً التي يقرؤها لوحُ الأداء وبطاقةُ الموظف */
    protected function range(): \App\Support\TimeRange
    {
        return \App\Support\TimeRange::fromRequest(new Request(['range' => '30d']));
    }

    /**
     * بذورٌ مصنوعةٌ بيدنا لمهندسةٍ واحدة — كلُّ رقمٍ في التأكيدات محسوبٌ منها:
     * مهمّتان أُنجزتا (واحدةٌ في الموعد)، ومفتوحتان (واحدةٌ متأخّرة) على
     * مشروعين، وتذكرتان حُلّتا في ٢٤ ساعةً لكلٍّ وثالثةٌ مفتوحة، واعتمادٌ
     * ينتظرها مباشرةً وآخرُ بسلسلةٍ فيها اسمُها وثالثٌ حسمته، ومشروعٌ تديره.
     */
    protected function seedPin(): array
    {
        $this->seedCore();
        $uid = $this->employee->id;

        $emp = Employee::create(['name' => 'مهندسة', 'user_id' => $uid,
            'dept' => 'الهندسة', 'status' => 'نشط', 'perf' => 'ممتاز', 'salary' => 1234]);

        $p1 = Project::create(['name' => 'مشروع أ', 'status' => 'قيد التنفيذ', 'manager_id' => $uid]);
        $p2 = Project::create(['name' => 'مشروع ب', 'status' => 'قيد التنفيذ', 'manager_id' => $this->owner->id]);

        // t1: أُنجزت قبل يومين وموعدُها غداً ⇒ في الموعد
        Task::create(['title' => 't1', 'assignee_id' => $uid, 'status' => 'منجزة', 'project_id' => $p1->id,
            'due' => now()->addDay()->toDateString(), 'completed_at' => now()->subDays(2),
            'created_at' => now()->subDays(4), 'updated_at' => now()->subDays(2)]);
        // t2: أُنجزت قبل يومين وموعدُها قبل خمسة ⇒ متأخرةُ الإنجاز
        Task::create(['title' => 't2', 'assignee_id' => $uid, 'status' => 'منجزة', 'project_id' => $p1->id,
            'due' => now()->subDays(5)->toDateString(), 'completed_at' => now()->subDays(2),
            'created_at' => now()->subDays(6), 'updated_at' => now()->subDays(2)]);
        // t3: مفتوحةٌ فات موعدُها ⇒ متأخّرةٌ الآن
        Task::create(['title' => 't3', 'assignee_id' => $uid, 'status' => 'قيد التنفيذ', 'project_id' => $p1->id,
            'due' => now()->subDay()->toDateString()]);
        // t4: مفتوحةٌ بموعدٍ قادم على مشروعٍ ثانٍ
        Task::create(['title' => 't4', 'assignee_id' => $uid, 'status' => 'قيد التنفيذ', 'project_id' => $p2->id,
            'due' => now()->addDays(5)->toDateString()]);

        // k1: حُلّت في ٢٤ ساعةً (بلا ختمِ حلٍّ في meta ⇒ آخرُ تعديلٍ هو الحلّ)
        Ticket::create(['subject' => 'k1', 'assignee_id' => $uid, 'status' => 'تم الحل',
            'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(2)]);
        // k2: ختمُ الحلّ في meta قبل آخر تعديلٍ بيوم ⇒ ٢٤ ساعةً كذلك
        Ticket::create(['subject' => 'k2', 'assignee_id' => $uid, 'status' => 'تم الحل',
            'meta' => ['resolved_at' => (string) now()->subDays(4)],
            'created_at' => now()->subDays(5), 'updated_at' => now()->subDay()]);
        // k3: مفتوحةٌ الآن
        Ticket::create(['subject' => 'k3', 'assignee_id' => $uid, 'status' => 'قيد المعالجة',
            'created_at' => now()->subDays(2)]);

        // a1: ينتظر حسمَها مباشرةً · a2: ضمن سلسلتها · a3: حسمتها في النافذة
        Approval::create(['title' => 'a1', 'type' => 'شراء', 'approver_id' => $uid]);
        Approval::create(['title' => 'a2', 'type' => 'شراء', 'chain' => [$uid]]);
        Approval::create(['title' => 'a3', 'type' => 'شراء', 'status' => 'موافق',
            'decided_by' => $uid, 'decided_at' => now()->subDays(3)]);

        return ['emp' => $emp, 'uid' => (string) $uid];
    }

    /** دورٌ ضيّق: وحداتٌ بعينها للعرض، مع رايةٍ اختيارية وقواعدِ حقول */
    protected function reader(string $email, array $modules, array $flags = [], array $fieldRules = []): User
    {
        $role = Role::create(['name' => 'قارئ ' . $email, 'scope' => 'all', 'flags' => $flags,
            'matrix' => collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all(),
            'field_rules' => $fieldRules ?: null]);

        return User::create(['name' => 'قارئ', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /** استدعاءُ `peopleKpis` المحميّ — الصفُّ الذي يرسمه لوحُ الأداء فعلاً */
    protected function peopleKpis()
    {
        $c = new \App\Http\Controllers\Web\PerformanceController();
        $m = new \ReflectionMethod($c, 'peopleKpis');
        $m->setAccessible(true);

        return collect($m->invoke($c));
    }

    /* ════════ ١) قارئُ التنفيذ الواحد ════════ */

    public function test_the_person_reader_counts_work_from_the_completion_stamp(): void
    {
        ['uid' => $uid] = $this->seedPin();
        $x = ExecutionStats::person($uid, $this->range());

        // المهام — «أُنجز» من completed_at، و«متأخّرة» لقطةُ اللحظة
        $this->assertSame(2, $x['completed'], 'مهمّتان أُنجزتا في النافذة');
        $this->assertSame(2, $x['on_time']['with_due']);
        $this->assertSame(1, $x['on_time']['on_time']);
        $this->assertSame(50, $x['on_time']['pct']);
        $this->assertSame(2, $x['open'], 'مفتوحتان');
        $this->assertSame(1, $x['overdue'], 'واحدةٌ فات موعدُها');

        // المشاريع — مشروعان في مهامّها المفتوحة وواحدٌ تديره
        $this->assertSame(2, $x['projects_open'], 'مشروعان في المهام المفتوحة');
        $this->assertSame(1, $x['projects_managed'], 'مشروعٌ واحدٌ تديره');

        // التذاكر — عددُ المحلولة دقيقٌ، ومتوسّطُ الحلّ من ختم meta أو آخر تعديل
        $this->assertSame(2, $x['tickets_resolved']);
        $this->assertSame(1, $x['tickets_open']);
        $this->assertEqualsWithDelta(24.0, $x['resolution']['avg_h'], 0.2, 'متوسط الحل ٢٤ ساعة');
        $this->assertSame(2, $x['resolution']['of']);
        $this->assertFalse($x['resolution']['capped']);

        // الاعتمادات — من `decided_by`/`decided_at`، والمنتظرُ مباشرةً أو بالسلسلة
        $this->assertSame(1, $x['approvals_decided'], 'اعتمادٌ واحدٌ حسمته في النافذة');
        $this->assertSame(2, $x['approvals_waiting'], 'اثنان ينتظران حسمَها');
    }

    public function test_a_cancelled_task_is_not_a_completed_one(): void
    {
        ['uid' => $uid] = $this->seedPin();
        // ملغاةٌ «منتهية» في قاموس الحالات لكنها ليست إنجازاً: لا ختمَ إنجازٍ لها
        // (ModuleController يختم completed_at لـ«منجزة/مكتملة» وحدَها)
        Task::create(['title' => 'ملغاة', 'assignee_id' => $uid, 'status' => 'ملغاة',
            'due' => now()->subDay()->toDateString(), 'updated_at' => now()->subDays(2)]);

        $x = ExecutionStats::person($uid, $this->range());
        $this->assertSame(2, $x['completed'], 'الإلغاءُ ليس إنجازاً');
        $this->assertSame(50, $x['on_time']['pct'], 'ولا يدخل نسبةَ الالتزام');
        $this->assertSame(1, $x['overdue'], 'ولا يُعَدّ متأخّراً — فهو منتهٍ');
    }

    public function test_people_kpis_read_the_same_single_reader(): void
    {
        ['uid' => $uid] = $this->seedPin();
        $this->actingAs($this->owner);

        $x = ExecutionStats::person($uid, $this->range());
        $row = $this->peopleKpis()->firstWhere('id', $uid);

        $this->assertNotNull($row, 'الموظفةُ في لوح الأداء');
        $this->assertSame($x['completed'], $row->done, 'أُنجز');
        $this->assertSame($x['on_time']['pct'], $row->onTimePct, 'الالتزام');
        $this->assertSame($x['overdue'], $row->lateNow, 'متأخرة الآن');
        $this->assertSame($x['tickets_resolved'], $row->tix, 'تذاكر حُلّت');
        $this->assertSame($x['resolution']['avg_h'], $row->avgRes, 'متوسط الحل');

        // ومسمارُ الأرقام الذهبية: القيمُ نفسُها محسوبةً باليد من البذور
        $this->assertSame(2, $row->done);
        $this->assertSame(50, $row->onTimePct);
        $this->assertSame(1, $row->lateNow);
        $this->assertSame(2, $row->tix);
        $this->assertEqualsWithDelta(24.0, $row->avgRes, 0.2);
    }

    public function test_the_single_reader_has_no_rival_counter(): void
    {
        // القارئُ واحد (نقدُ الخطّة #١): لا صنفَ عدّادٍ ثانٍ باسمٍ آخر،
        // ولا حسابَ أداءٍ محليٌّ في المتحكّمين — كلاهما يستهلك ExecutionStats.
        $this->assertFileDoesNotExist(app_path('Support/WorkforceStats.php'));
        foreach (['Http/Controllers/Web/PerformanceController.php',
                  'Http/Controllers/Web/PortalController.php'] as $rel) {
            $src = file_get_contents(app_path($rel));
            $this->assertStringContainsString('ExecutionStats', $src, $rel . ' يقرأ من القارئ الواحد');
            $this->assertStringNotContainsString('completed_at', $src,
                $rel . ' يحسب الإنجاز بنفسه بدل أن يقرأه');
        }
    }

    /* ════════ ٢) التنطيق ════════ */

    public function test_a_reader_from_another_company_sees_nothing(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ', 'status' => 'نشطة']);
        $b = Company::create(['name_ar' => 'شركة ب', 'status' => 'نشطة']);

        $emp = Employee::create(['name' => 'مهندسة', 'user_id' => $this->employee->id,
            'company_id' => $a->id, 'status' => 'نشط']);

        $out = $this->reader('other@test.local', ['hr', 'tasks']);
        User::whereKey($out->id)->update(['companies' => json_encode([$b->id])]);

        $this->actingAs(User::find($out->id))->get(route('portal.employee', $emp->id))
            ->assertNotFound();
    }

    public function test_the_work_cards_never_count_another_companys_rows(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ', 'status' => 'نشطة']);
        $b = Company::create(['name_ar' => 'شركة ب', 'status' => 'نشطة']);
        $uid = $this->employee->id;

        $emp = Employee::create(['name' => 'مهندسة', 'user_id' => $uid,
            'company_id' => $a->id, 'status' => 'نشط']);

        Task::create(['title' => 'مهمة أ', 'assignee_id' => $uid, 'status' => 'قيد التنفيذ',
            'company_id' => $a->id]);
        Task::create(['title' => 'مهمة ب', 'assignee_id' => $uid, 'status' => 'قيد التنفيذ',
            'company_id' => $b->id]);

        $in = $this->reader('ina@test.local', ['hr', 'tasks']);
        User::whereKey($in->id)->update(['companies' => json_encode([$a->id])]);
        $in = User::find($in->id);

        $this->assertSame(1, ExecutionStats::person($uid, $this->range(), $in)['open'],
            'المهمّةُ في شركةٍ أخرى لا تُعَدّ لقارئٍ محصورٍ بشركته');
        // وبلا قارئٍ (إسقاطُ المنشأة خلف حارس العزل) تُعَدّ الاثنتان
        $this->assertSame(2, ExecutionStats::person($uid, $this->range())['open']);

        $this->actingAs($in)->get(route('portal.employee', $emp->id))
            ->assertOk()->assertDontSee('مهمة ب');
    }

    public function test_a_module_the_reader_cannot_see_yields_an_honest_blank(): void
    {
        ['uid' => $uid, 'emp' => $emp] = $this->seedPin();
        $hrOnly = $this->reader('hronly@test.local', ['hr']);

        $x = ExecutionStats::person($uid, $this->range(), $hrOnly);
        $this->assertNull($x['completed'], 'بلا صلاحية المهام لا رقمَ مهامّ — لا صفرٌ كاذب');
        $this->assertNull($x['tickets_resolved']);
        $this->assertNull($x['approvals_waiting']);
        $this->assertFalse($x['may']['tasks']);

        $this->actingAs($hrOnly)->get(route('portal.employee', $emp->id))->assertOk();
    }

    /* ════════ ٣) صلاحيةُ الحقل ════════ */

    public function test_the_manager_rating_obeys_field_permissions(): void
    {
        ['emp' => $emp] = $this->seedPin();

        // دورٌ يرى كلَّ شيءٍ ويراقب، لكن حقلا التقييم والراتب محجوبان عنه
        $blind = $this->reader('blind@test.local', array_keys(hub_modules()),
            ['monitor' => 1], ['hr' => ['perf' => 'hide', 'salary' => 'hide']]);

        $this->actingAs($blind)->get('/performance')->assertOk()->assertDontSee('ممتاز');
        $this->actingAs($blind)->get(route('portal.employee', $emp->id))->assertOk()
            ->assertDontSee('ممتاز')->assertDontSee('1,234');

        // والمالكُ يراه — الحجبُ صلاحيةٌ لا حذفٌ للبيانات
        $this->actingAs($this->owner)->get('/performance')->assertOk()->assertSee('ممتاز');
    }

    /* ════════ ٤) لا مراقبةَ شخصية ════════ */

    /** قيدُ تدقيقٍ صريحٌ للمستخدم — الفعلُ والوحدةُ كما يكتبهما النظام */
    protected function audit(string $uid, string $action, ?string $module, string $name = 'س'): void
    {
        AuditEntry::create(['user_id' => $uid, 'action' => $action, 'module' => $module,
            'record_id' => (string) Str::uuid(), 'name' => $name, 'created_at' => now()->subDay()]);
    }

    public function test_meaningful_actions_exclude_logins_and_sensitive_views(): void
    {
        ['uid' => $uid] = $this->seedPin();

        $this->audit($uid, 'إضافة', 'tasks');
        $this->audit($uid, 'تعديل', 'tickets');
        $this->audit($uid, 'حذف', 'tasks');
        $this->audit($uid, 'تصدير كبير', 'tasks');
        // ما لا يُعَدّ عملاً: الدخولُ والخروجُ والاطّلاعُ الحسّاس
        $this->audit($uid, 'دخول ناجح', null);
        $this->audit($uid, 'دخول مريب', null);
        $this->audit($uid, 'خروج', null);
        $this->audit($uid, 'عرض حساس', 'hr');
        $this->audit($uid, 'عرض حساس عبر API', 'hr');

        $act = ExecutionStats::personActivity($uid, $this->range(), $this->owner);
        $this->assertSame(4, $act['actions']['n'],
            'الأفعالُ ذاتُ المعنى أربعةٌ — لا دخولَ ولا اطّلاعٌ حسّاس');

        $labels = collect($act['actions']['rows'])->pluck('module')->all();
        $this->assertNotContains('hr', $labels, 'وحدةُ الاطّلاع الحسّاس لا تدخل اللوحة');
    }

    public function test_meaningful_actions_are_filtered_by_reader_visible_modules(): void
    {
        ['uid' => $uid] = $this->seedPin();
        $this->audit($uid, 'إضافة', 'tasks');
        $this->audit($uid, 'إضافة', 'fin');

        $narrow = $this->reader('narrow@test.local', ['hr', 'tasks'], ['audit' => 1]);
        $act = ExecutionStats::personActivity($uid, $this->range(), $narrow);

        $this->assertSame(1, $act['actions']['n'], 'الوحدةُ المحجوبةُ عن القارئ لا تُعَدّ له');
        $this->assertSame(2, ExecutionStats::personActivity($uid, $this->range(), $this->owner)['actions']['n'],
            'والمالكُ يرى الاثنين');
    }

    public function test_the_personal_timeline_never_carries_raw_page_visits(): void
    {
        ['uid' => $uid, 'emp' => $emp] = $this->seedPin();
        $this->audit($uid, 'إضافة', 'tasks', 'قيدٌ مهم');

        // زياراتُ صفحاتٍ كثيفة — لا يجوز أن يظهر منها حرفٌ في الخطّ الزمنيّ
        for ($i = 0; $i < 5; $i++) {
            DB::table('page_visits')->insert(['id' => (string) Str::uuid(), 'user_id' => $uid,
                'path' => '/secret-path-' . $i, 'at' => now()->subHours($i + 1)]);
        }

        $tl = ExecutionStats::personTimeline($uid, $this->range(), $this->owner);
        $this->assertNotEmpty($tl, 'الخطُّ الزمنيّ يحمل عملاً حقيقياً');
        foreach ($tl as $e) {
            $this->assertStringNotContainsString('secret-path', json_encode($e, JSON_UNESCAPED_UNICODE));
        }
        // مهمّةٌ أُنجزت وتذكرةٌ حُلّت واعتمادٌ حُسم — كلُّها في الخط
        $labels = collect($tl)->pluck('label')->unique()->all();
        $this->assertContains('مهمة أُنجزت', $labels);
        $this->assertContains('تذكرة حُلّت', $labels);
        $this->assertContains('اعتماد حُسم', $labels);

        // والقارئُ لا يرى مسارَ زيارةٍ على الشاشة نفسِها
        $this->actingAs($this->owner)->get(route('portal.employee', $emp->id))
            ->assertOk()->assertDontSee('secret-path');

        // وفحصُ المصدر: قارئُ التنفيذ لا يعرف جدولَ الزيارات أصلاً
        $this->assertStringNotContainsString('page_visits',
            file_get_contents(app_path('Support/ExecutionStats.php')));
    }

    /* ════════ ٥) كلفةُ القراءة ════════ */

    public function test_no_whole_table_reads_build_the_people_board(): void
    {
        $this->seedPin();
        $this->actingAs($this->owner);

        // اثنا عشرَ حساباً بملفّاتها — الكلفةُ لا تنمو بعددهم
        for ($i = 0; $i < 12; $i++) {
            $u = User::create(['name' => 'ع' . $i, 'email' => "bulk$i@test.local",
                'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
                'status' => 'نشط', 'password_changed_at' => now()]);
            Employee::create(['name' => 'ع' . $i, 'user_id' => $u->id, 'status' => 'نشط', 'perf' => 'جيد']);
            Task::create(['title' => 'م' . $i, 'assignee_id' => $u->id, 'status' => 'قيد التنفيذ']);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $this->peopleKpis();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertGreaterThan(1, $rows->count());
        $this->assertLessThanOrEqual(20, count($log),
            'لوحُ الأداء استعلاماتٌ مجمَّعة لا استعلامٌ لكل موظف: ' . count($log));

        // ولا قراءةَ لجدول الموظفين كلِّه — كلُّ مسٍّ له محصورٌ بقائمة الحسابات
        foreach ($log as $q) {
            if (preg_match('/from\s+[`"\[]?employees/i', $q['query'])) {
                $this->assertMatchesRegularExpression('/\bin\s*\(/i', $q['query'],
                    'قراءةُ الموظفين بلا قيدٍ على الحسابات: ' . $q['query']);
            }
        }
    }
}
