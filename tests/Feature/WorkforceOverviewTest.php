<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ExecutionStats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **نظرةُ القوى العاملة (WP-7.2): أرقامُ تنفيذٍ صادقة، لا مراقبةٌ شخصية.**
 *
 * ما يحرسه هذا الملف:
 *  1) كلُّ بطاقةٍ تساوي استعلامَها المرجعيَّ على بذورٍ مصنوعةٍ بيدنا — لا «رقمٌ
 *     يبدو معقولاً» بل رقمٌ محسوبٌ يدوياً من البذور.
 *  2) الحارس: القراءةُ لمالكٍ أو حامل monitor، والحسابُ المعزول بشركةٍ يُصَدّ
 *     بـ403 (hub_org_analytics_guard) — أرقامُ الشاشة تجمع عبر المنشأة كلِّها.
 *  3) النافذةُ (7d/30d) تغيّر النتائجَ حتميّاً.
 *  4) **مسمارُ انحدار:** «في الموعد» من `completed_at` لا من `updated_at` —
 *     تعديلُ مهمةٍ منجزةٍ بعد موعدها لا يقلب نسبةَ الالتزام.
 *  5) لوحُ الأقسام من `employees.dept` (عبر ربط الحساب) لا من `tasks.dept` الحرّ.
 *  6) لا أثرَ لعدد الزيارات في المصدر كلِّه (spec §5.5) — لا قراءةً ولا ترتيباً.
 */
class WorkforceOverviewTest extends TestCase
{
    /**
     * بذورٌ مصنوعة: موظفةُ هندسةٍ مربوطةُ الحساب، وستُّ مهامَّ تغطي كلَّ حالة،
     * وثلاثُ تذاكرَ بأولوياتٍ متباينة، واعتمادان أحدُهما محسوم، وثلاثُ نبضاتِ جلسة.
     */
    private function seedCrafted(): void
    {
        $this->seedCore();

        Employee::create(['name' => 'مهندسة', 'user_id' => $this->employee->id,
            'dept' => 'الهندسة', 'status' => 'نشط']);

        // نبضاتُ الجلسة: اثنان اليوم وواحدٌ أمس ⇒ «نشطون اليوم» = ٢
        foreach ([[$this->owner->id, now()], [$this->employee->id, now()],
                  [$this->viewer->id, now()->subDay()]] as [$uid, $seen]) {
            DB::table('sessions_log')->insert(['id' => (string) Str::uuid(), 'user_id' => $uid,
                'started_at' => $seen->copy()->subHour(), 'last_seen_at' => $seen]);
        }

        // المهام — كلُّها للمهندسة كي يُحسب لوحُ الأقسام من ملفّها:
        // t1: أُنجزت قبل يومين وموعدُها غداً ⇒ في الموعد (داخل 7d)
        Task::create(['title' => 't1', 'assignee_id' => $this->employee->id, 'status' => 'منجزة',
            'due' => now()->addDay()->toDateString(), 'completed_at' => now()->subDays(2),
            'created_at' => now()->subDays(3)]);
        // t2: أُنجزت قبل يومين وموعدُها قبل ٥ أيام ⇒ متأخرةُ الإنجاز (داخل 7d)
        Task::create(['title' => 't2', 'assignee_id' => $this->employee->id, 'status' => 'منجزة',
            'due' => now()->subDays(5)->toDateString(), 'completed_at' => now()->subDays(2),
            'created_at' => now()->subDays(6)]);
        // t3: أُنجزت قبل ١٠ أيام متأخرةً ⇒ خارج 7d وداخل 30d
        Task::create(['title' => 't3', 'assignee_id' => $this->employee->id, 'status' => 'منجزة',
            'due' => now()->subDays(12)->toDateString(), 'completed_at' => now()->subDays(10),
            'created_at' => now()->subDays(13)]);
        // t4: مفتوحةٌ فات موعدُها ⇒ «متأخّرة الآن» = ١
        Task::create(['title' => 't4', 'assignee_id' => $this->employee->id, 'status' => 'قيد التنفيذ',
            'due' => now()->subDay()->toDateString()]);
        // t5: مفتوحةٌ بموعدٍ قادم ⇒ لا تأخير
        Task::create(['title' => 't5', 'assignee_id' => $this->employee->id, 'status' => 'قيد التنفيذ',
            'due' => now()->addDays(5)->toDateString()]);
        // t6: ملغاةٌ فائتةُ الموعد ⇒ منتهيةٌ فلا تُحسب متأخرةً ولا مفتوحة
        Task::create(['title' => 't6', 'assignee_id' => $this->employee->id, 'status' => 'ملغاة',
            'due' => now()->subDay()->toDateString()]);

        // التذاكر: k1 قديمةٌ مفتوحة (خارج نافذة 7d، تُحسب في «مفتوحة الآن»)،
        // k2 عاجلةٌ بلا ردٍّ منذ يومين ⇒ خرقُ استجابةٍ وحلّ، k3 منخفضةٌ عمرُها نصفُ ساعة ⇒ ملتزمة
        Ticket::create(['subject' => 'k1', 'status' => 'قيد المعالجة', 'created_at' => now()->subDays(10)]);
        Ticket::create(['subject' => 'k2', 'status' => 'جديدة', 'priority' => 'عاجلة',
            'created_at' => now()->subDays(2)]);
        Ticket::create(['subject' => 'k3', 'status' => 'جديدة', 'priority' => 'منخفضة',
            'created_at' => now()->subMinutes(30)]);

        // الاعتمادات: معلّقٌ وموافَقٌ عليه ⇒ «معلّقة» = ١
        Approval::create(['title' => 'a1', 'type' => 'شراء']);
        Approval::create(['title' => 'a2', 'type' => 'شراء', 'status' => 'موافق']);
    }

    /* ────────── ١) الحارس ────────── */

    public function test_the_screen_is_for_monitor_holders_and_denied_to_isolated_accounts(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->get('/workforce/overview')->assertOk();
        $this->actingAs($this->employee)->get('/workforce/overview')->assertForbidden();
        $this->actingAs($this->viewer)->get('/workforce/overview')->assertForbidden();

        // حاملُ monitor المعزولُ بشركةٍ يُصَدّ: الأرقامُ تجمع عبر المنشأة كلِّها
        $co = \App\Models\Company::create(['name_ar' => 'شركة', 'status' => 'نشطة']);
        $role = Role::create(['name' => 'مراقب معزول', 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => []]);
        $iso = User::create(['name' => 'معزول', 'email' => 'wf-iso@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => [$co->id]]);
        $this->actingAs($iso)->get('/workforce/overview')->assertForbidden();

        // وغيرُ المعزول حاملُ monitor يقرأ
        $mon = User::create(['name' => 'مراقب', 'email' => 'wf-mon@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $this->actingAs($mon)->get('/workforce/overview')->assertOk();
    }

    /**
     * (§49 · §25 — لا طريقَ مسدود) **بطاقةٌ لا تربط إلى بابٍ يُصَدّ عنه قارئُها.**
     *
     * الشاشةُ تُقرأ لحاملِ راية المراقبة، وبطاقتُها الأولى كانت تربط إلى
     * `activity.index` **للمالك وحدَه**، وبقيّتُها إلى وحداتٍ ومركزِ دعمٍ بحسب
     * مصفوفة القارئ. فحاملُ الراية بلا مصفوفةٍ كان يجد سبعَ بطاقاتٍ كلُّها ٤٠٣.
     * الرقمُ يبقى (وهو رقمُ منشأةٍ يحقّ له)، والرابطُ يسقط لمن لا يفتحه.
     */
    public function test_no_card_links_to_a_door_this_reader_cannot_open(): void
    {
        $this->seedCore();

        $role = Role::create(['name' => 'مراقب بلا مصفوفة', 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => []]);
        $mon = User::create(['name' => 'مراقب', 'email' => 'wf-links@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $html = $this->actingAs($mon)->get('/workforce/overview')->assertOk()->getContent();
        foreach (['activity.index' => route('activity.index'), 'support' => route('support'),
                  'workforce.team' => route('workforce.team'),
                  'm.index tasks' => route('m.index', 'tasks'),
                  'm.index projects' => route('m.index', 'projects'),
                  'm.index approvals' => route('m.index', 'approvals')] as $name => $url) {
            $this->assertStringNotContainsString('href="' . $url . '"', $html,
                "بطاقةُ القوى العاملة تربط المراقبَ إلى {$name} وهو يُصَدّ عنها");
        }

        // والأرقامُ نفسُها باقية — الحجبُ للرابط لا للرقم
        $this->assertStringContainsString('نشطون اليوم', $html);
        $this->assertStringContainsString('تذاكر مفتوحة', $html);

        // والمالكُ يفتح كلَّ شيء، فالروابطُ كلُّها عنده
        $ownerHtml = $this->actingAs($this->owner)->get('/workforce/overview')->assertOk()->getContent();
        foreach ([route('activity.index'), route('support'), route('m.index', 'tasks')] as $url) {
            $this->assertStringContainsString('href="' . $url . '"', $ownerHtml,
                "رابطٌ سقط عن المالك: {$url}");
        }
    }

    /* ────────── ٢) كلُّ بطاقةٍ تساوي مرجعَها ────────── */

    public function test_every_card_matches_its_reference_on_crafted_seeds(): void
    {
        $this->seedCrafted();

        $res = $this->actingAs($this->owner)->get('/workforce/overview');
        $res->assertOk();
        $x = $res->viewData('x');

        // نشطون اليوم: نبضتان منذ منتصف الليل (الثالثة أمس)
        $this->assertSame(2, $x['active_today']);

        // أُنجز في نافذة 7d: t1+t2 (t3 قبل ١٠ أيام خارجها) — والنافذةُ السابقة
        // (١٤–٧ أيام خلت، spec §36) تلتقط t3 وحدَها: نافذتان متجاورتان بلا فجوة
        $this->assertSame(2.0, $x['completed']['cur']);
        $this->assertSame(1.0, $x['completed']['prev']);

        // الالتزام: t1 في الموعد وt2 متأخرة ⇒ ١ من ٢ = ٥٠٪
        $this->assertSame(50, $x['on_time']['pct']);
        $this->assertSame(2, $x['on_time']['with_due']);

        // متأخّرة الآن: t4 وحدَها (t6 ملغاةٌ فمنتهية)
        $this->assertSame(1, $x['overdue']);

        // تذاكرُ مفتوحةٌ الآن: الثلاثُ كلُّها لقطةَ اللحظة
        $this->assertSame(3, $x['open_tickets']);

        // خرقُ SLA في النافذة: k2 وحدَها من تذكرتَي النافذة (k1 خارجها، k3 ملتزمة)
        $this->assertSame(1, $x['sla_breaches']['n']);
        $this->assertSame(2, $x['sla_breaches']['of']);

        // اعتماداتٌ معلّقة: a1 وحدَها (a2 حُسمت بمفردة الموافقات)
        $this->assertSame(1, $x['pending_approvals']);

        // لا مشاريعَ مزروعة ⇒ صفرٌ صادق
        $this->assertSame(0, $x['projects_at_risk']['n']);

        // الاتجاه: مجموعُ نقاطه = المنجَزُ في النافذة نفسِها (لا رسمَ يكذب على بطاقته)
        $this->assertSame(2, array_sum(array_column($x['trend'], 'value')));

        // لوحُ الأقسام: صفُّ الهندسة من ملفّ الموظفة — مفتوحتان (t4+t5)،
        // متأخرةٌ واحدة، منجزتان في النافذة، والتزامٌ ٥٠٪
        $dept = collect($x['departments'])->firstWhere('dept', 'الهندسة');
        $this->assertNotNull($dept);
        $this->assertSame(1, $dept['heads']);
        $this->assertSame(2, $dept['open']);
        $this->assertSame(1, $dept['overdue']);
        $this->assertSame(2, $dept['done']);
        $this->assertSame(50, $dept['on_time_pct']);
    }

    /* ────────── ٣) النافذةُ تغيّر النتائج حتميّاً ────────── */

    public function test_the_window_changes_the_numbers_deterministically(): void
    {
        $this->seedCrafted();

        $x30 = $this->actingAs($this->owner)->get('/workforce/overview?range=30d')
            ->assertOk()->viewData('x');

        // 30d تلتقط t3 أيضاً: ٣ منجزات، والالتزامُ ١ من ٣ = ٣٣٪
        $this->assertSame(3.0, $x30['completed']['cur']);
        $this->assertSame(33, $x30['on_time']['pct']);
        $this->assertSame(3, $x30['on_time']['with_due']);

        // ولقطاتُ اللحظة لا تتأثر بالنافذة
        $this->assertSame(1, $x30['overdue']);
        $this->assertSame(3, $x30['open_tickets']);
        $this->assertSame(1, $x30['pending_approvals']);
    }

    /* ────────── ٤) مسمارُ الانحدار: completed_at لا updated_at ────────── */

    public function test_on_time_uses_completed_at_so_late_edits_do_not_flip_it(): void
    {
        $this->seedCore();
        $t = Task::create(['title' => 'أُنجزت في موعدها', 'status' => 'منجزة',
            'due' => now()->subDay()->toDateString(), 'completed_at' => now()->subDays(3)]);

        $pct = fn () => $this->actingAs($this->owner)->get('/workforce/overview')
            ->assertOk()->viewData('x')['on_time']['pct'];
        $this->assertSame(100, $pct());

        // تعديلٌ لاحقٌ بعد فوات الموعد يلمس updated_at ولا يلمس completed_at —
        // لو حُسبت النسبةُ من updated_at لانقلبت من ١٠٠٪ إلى ٠٪
        $t->update(['title' => 'عُدّلت بعد الموعد']);
        $this->assertTrue($t->fresh()->updated_at->gt($t->fresh()->due), 'التعديلُ بعد الموعد فعلاً');
        $this->assertSame(100, $pct(), 'تعديلُ مهمةٍ منجزةٍ لا يقلب الالتزام');
    }

    /* ────────── ٥) القسمُ من ملفّ الموظف لا من حقل المهمة ────────── */

    public function test_departments_come_from_the_employee_file_not_the_free_task_field(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'مهندسة', 'user_id' => $this->employee->id,
            'dept' => 'الهندسة', 'status' => 'نشط']);
        // حقلُ القسم الحرُّ على المهمة يقول شيئاً آخر — ولا يُعتدّ به
        Task::create(['title' => 'مهمة', 'assignee_id' => $this->employee->id,
            'status' => 'قيد التنفيذ', 'dept' => 'خدمة العملاء']);
        // ومهمةٌ لمستخدمٍ بلا ملفّ موظفٍ لا تظهر في اللوح أصلاً
        Task::create(['title' => 'بلا ملف', 'assignee_id' => $this->owner->id,
            'status' => 'قيد التنفيذ', 'dept' => 'المالية']);

        $x = $this->actingAs($this->owner)->get('/workforce/overview')->assertOk()->viewData('x');
        $depts = array_column($x['departments'], 'dept');
        $this->assertContains('الهندسة', $depts);
        $this->assertNotContains('خدمة العملاء', $depts);
        $this->assertNotContains('المالية', $depts);
        $this->assertSame(1, collect($x['departments'])->firstWhere('dept', 'الهندسة')['open']);
    }

    /* ────────── ٦) مشاريعُ في خطر: عتبةُ الصحة ٥٥ ────────── */

    public function test_projects_at_risk_counts_health_below_threshold(): void
    {
        $this->seedCore();
        $sickId = (string) Str::uuid();
        $okId = (string) Str::uuid();
        DB::table('projects')->insert([
            ['id' => $sickId, 'name' => 'مشروع متعثر', 'status' => 'قيد التنفيذ',
             'launch_exp' => now()->subDays(30)->toDateString(),
             'created_at' => now(), 'updated_at' => now()],
            ['id' => $okId, 'name' => 'مشروع سليم', 'status' => 'قيد التنفيذ',
             'launch_exp' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        // انضباطُ مهامّه صفر (٤ من ٤ متأخرة) وثلاثةُ أخطارٍ حرجةٍ مفتوحة
        for ($i = 0; $i < 4; $i++) {
            Task::create(['title' => "متأخرة $i", 'project_id' => $sickId,
                'status' => 'قيد التنفيذ', 'due' => now()->subDays(3)->toDateString()]);
        }
        for ($i = 0; $i < 3; $i++) {
            DB::table('issues')->insert(['id' => (string) Str::uuid(), 'title' => "خطر $i",
                'project_id' => $sickId, 'severity' => 'حرجة', 'status' => 'مفتوحة',
                'created_at' => now(), 'updated_at' => now()]);
        }

        // المرجع: الدرجةُ نفسُها التي يحسبها مصدرُ الصحة الواحد
        $this->assertLessThan(55, hub_project_health($sickId)['score']);
        $this->assertGreaterThanOrEqual(55, hub_project_health($okId)['score']);

        $x = $this->actingAs($this->owner)->get('/workforce/overview')->assertOk()->viewData('x');
        $this->assertSame(1, $x['projects_at_risk']['n']);
        $this->assertSame(2, $x['projects_at_risk']['of']);
        $this->assertSame('مشروع متعثر', $x['projects_at_risk']['rows'][0]['name']);
    }

    /* ────────── ٧) ميزانُ الحمل + لا ترتيبَ بالزيارات ────────── */

    public function test_load_balance_flags_overload_and_heavy_overdue_without_visit_data(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'مثقلة', 'user_id' => $this->employee->id,
            'dept' => 'الهندسة', 'status' => 'نشط']);
        Employee::create(['name' => 'بلا تكليف', 'user_id' => $this->viewer->id,
            'dept' => 'الهندسة', 'status' => 'نشط']);
        Employee::create(['name' => 'بلا حساب', 'status' => 'نشط']);

        // ثلاثُ مهامَّ فائتةِ الموعد بساعاتٍ تفوق أيَّ متاح ⇒ فوق الطاقة + تأخّرٌ كثيف
        for ($i = 0; $i < 3; $i++) {
            Task::create(['title' => "فائتة $i", 'assignee_id' => $this->employee->id,
                'status' => 'قيد التنفيذ', 'est_h' => 500,
                'due' => now()->subDay()->toDateString()]);
        }

        $res = $this->actingAs($this->owner)->get('/workforce/overview');
        $res->assertOk();
        $x = $res->viewData('x');

        $this->assertSame(['مثقلة'], array_column($x['load']['over'], 'name'));
        $this->assertSame([['name' => 'مثقلة', 'n' => 3]], $x['load']['heavy_overdue']);
        $this->assertContains('بلا تكليف', array_column($x['load']['idle'], 'name'));
        $this->assertSame(1, $x['load']['unlinked']);
        $this->assertNotNull($x['load']['spread']);

        // نصُّ المنهجية ظاهرٌ — الشاشةُ تشرح أرقامَها لا تدّعيها
        $res->assertSee('كيف تقرأ هذه الأرقام؟');
        $res->assertSee('لا تدخل زياراتُ الصفحات');

        // **spec §5.5 حرفياً:** لا قراءةَ لجدول الزيارات في مصدر القارئ أو الشاشة
        // أو المتحكم إطلاقاً — فلا يمكن أصلاً أن يُرتَّب موظفٌ بعدد زياراته
        foreach ([app_path('Support/ExecutionStats.php'),
                  app_path('Http/Controllers/Web/WorkforceController.php'),
                  resource_path('views/workforce/overview.blade.php')] as $src) {
            $this->assertStringNotContainsString('page_visits', file_get_contents($src),
                basename($src) . ' لا يقرأ الزيارات');
        }
    }
}
