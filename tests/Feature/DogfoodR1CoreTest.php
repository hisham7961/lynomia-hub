<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\HubNotification;
use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkUpdate;
use App\Support\Ops\AlertEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **الجولة 1 من المحاكاة البشريّة — ثوابتُ المنسّق** (docs/archive/human-simulation/round-1).
 *
 * كلُّ اختبارٍ هنا يقابل احتكاكاً أثبته وكيلُ محاكاةٍ بشريٌّ عبر المتصفّح ثم أُصلح:
 * عهدتي خدمةٌ ذاتيّة (F1/F3)، حجبُ الاسم لا يقلب الحقيقة (F2)، قرارُ الإجازة
 * أزرارٌ لا تحريرَ سجلٍّ خام (F5)، مديرُ المشروع يقرّر من طابوره (F7)، صحّةُ
 * المشروع لا تجامل (F12)، ختمُ الإنجاز عند الإنشاء (F13)، حساباتُ العملاء ليست
 * خياراتِ إسناد (F32)، وخرقُ SLA يُصعَّد ولا يغرق (F33).
 */
class DogfoodR1CoreTest extends TestCase
{
    private function user(string $email, array $matrix, array $flags = [], string $scope = 'all'): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => $scope, 'flags' => $flags,
            'matrix' => $matrix]);

        return User::create(['name' => 'مستخدم ' . $email, 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /* ═══════ F1 — عهدتي تُرى بحكم الحيازة لا بصلاحيّة وحدة الأصول ═══════ */

    public function test_my_custody_visible_without_assets_permission(): void
    {
        $this->seedCore();
        $emp = $this->user('holder@test.local', ['tasks' => ['v' => 1]]);
        Asset::create(['name' => 'لابتوب ديل المخزون', 'status' => 'قيد الاستخدام',
            'holder_id' => $emp->id]);

        $this->actingAs($emp);

        // صفحة «عهدتي» المخصّصة — بلا assets:v إطلاقاً
        $this->get(route('portal.custody'))
            ->assertOk()
            ->assertSee('لابتوب ديل المخزون');

        // وقسمُ العهدة في «بوابتي» يعرضها لصاحبها
        $this->get(route('portal.me'))
            ->assertOk()
            ->assertSee('لابتوب ديل المخزون');
    }

    /* ═══════ F3 — إشعارُ العهدة لا يقود حاملَها إلى 404 ═══════ */

    public function test_custody_notification_routes_holder_to_my_custody(): void
    {
        $this->seedCore();
        $asset = Asset::create(['name' => 'جهاز عهدة', 'status' => 'قيد الاستخدام']);

        $plain = $this->user('plainholder@test.local', ['tasks' => ['v' => 1]]);
        $n = hub_notify($plain->id, 'custody', '🧰 سُجّلت باسمك عهدة', 'assets', $asset->id);
        $this->actingAs($plain);
        $this->get(route('notifications.go', $n->id))
            ->assertRedirect(route('portal.custody'));

        // ومن يملك وحدةَ الأصول يبقى على شاشتها الكاملة
        $itUser = $this->user('it-guy@test.local', ['assets' => ['v' => 1]]);
        $n2 = hub_notify($itUser->id, 'custody', '🧰 سُجّلت باسمك عهدة', 'assets', $asset->id);
        $this->actingAs($itUser);
        $this->get(route('notifications.go', $n2->id))
            ->assertRedirect(route('m.show', ['assets', $asset->id]));
    }

    /* ═══════ F2 — «بلا حائز» لا تُقال زوراً حين يحجب hr:v الاسمَ ═══════ */

    public function test_asset360_reports_hidden_holder_not_false_none(): void
    {
        $this->seedCore();
        $holder = $this->user('realholder@test.local', ['tasks' => ['v' => 1]]);
        $asset = Asset::create(['name' => 'شاشة مكتب', 'status' => 'قيد الاستخدام',
            'holder_id' => $holder->id]);

        $tech = $this->user('tech-nohr@test.local', ['assets' => ['v' => 1]]);   // بلا hr:v
        $ov = (new \App\Support\Assets\Asset360)->overview($asset, $tech);
        $this->assertNull($ov['holder'], 'الاسم محجوب بلا hr:v');
        $this->assertTrue($ov['holder_hidden'], 'الحقيقة تبقى: الأصل مُسلَّم والاسم محجوب — لا «بلا حائز»');

        $hr = $this->user('hr-view@test.local', ['assets' => ['v' => 1], 'hr' => ['v' => 1]]);
        $ov2 = (new \App\Support\Assets\Asset360)->overview($asset, $hr);
        $this->assertNotNull($ov2['holder']);
        $this->assertFalse($ov2['holder_hidden']);
    }

    /* ═══════ F5 — قرارُ الإجازة: تسلسلٌ وأزرارٌ وسببُ رفضٍ ملزَم ═══════ */

    public function test_leave_decision_flow_manager_then_hr(): void
    {
        $this->seedCore();

        $mgr = $this->user('mgr-lv@test.local', ['leaves' => ['v' => 1]]);
        $hr = $this->user('hr-lv@test.local', ['leaves' => ['v' => 1, 'e' => 1], 'hr' => ['v' => 1, 'e' => 1]]);
        $reqUser = $this->user('emp-lv@test.local', ['leaves' => ['v' => 1, 'a' => 1]]);
        $emp = Employee::create(['name' => 'موظف الإجازة', 'user_id' => $reqUser->id,
            'email' => $reqUser->email, 'manager_id' => $mgr->id, 'status' => 'نشط', 'leave_bal' => 21]);

        $lv = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية',
            'date_from' => now()->addDays(10)->toDateString(), 'date_to' => now()->addDays(11)->toDateString(),
            'status' => 'مقدّم', 'mgr_id' => $mgr->id]);

        // صاحبُ الطلب لا يقرّر في طلب نفسه
        $this->actingAs($reqUser);
        $this->post(route('leaves.decide', $lv->id), ['decision' => 'approve'])->assertForbidden();

        // المدير: موافقةٌ توصيةً — لا اعتماد نهائي
        $this->actingAs($mgr);
        $this->post(route('leaves.decide', $lv->id), ['decision' => 'approve'])->assertRedirect();
        $this->assertSame('موافقة المدير', (string) $lv->fresh()->status);

        // الرفض بلا سبب يُردّ
        $this->actingAs($hr);
        $this->from(route('m.show', ['leaves', $lv->id]))
            ->post(route('leaves.decide', $lv->id), ['decision' => 'reject', 'note' => ''])
            ->assertSessionHasErrors('note');
        $this->assertSame('موافقة المدير', (string) $lv->fresh()->status);

        // اعتماد HR النهائي يخصم من الرصيد (غيابٌ فعليّ يومان)
        $this->post(route('leaves.decide', $lv->id), ['decision' => 'approve'])->assertRedirect();
        $this->assertSame('معتمد', (string) $lv->fresh()->status);
        $this->assertEqualsWithDelta(21 - (float) $lv->fresh()->days, (float) $emp->fresh()->leave_bal, 0.001,
            'الاعتمادُ يخصم من الرصيد عبر محرّك LeaveRequest القائم');

        // وصاحبُ الطلب أُخطر بالقرار
        $this->assertTrue(HubNotification::where('user_id', $reqUser->id)
            ->where('text', 'LIKE', '%اعتُمد%')->exists());
    }

    /* ═══════ F7 — مديرُ المشروع يقرّر من طابور المراجعة (كان بلا أيّ سبيل فعل) ═══════ */

    public function test_project_manager_can_accept_report_from_review_queue(): void
    {
        $this->seedCore();
        $pm = $this->user('pm-rv@test.local', ['updates' => ['v' => 1, 'e' => 1], 'projects' => ['v' => 1]]);
        $member = $this->user('member-rv@test.local', ['updates' => ['v' => 1, 'a' => 1]]);
        $p = Project::create(['name' => 'مشروع بوابة المراجعة', 'status' => 'نشط', 'manager_id' => $pm->id]);

        $w = WorkUpdate::create(['project_id' => $p->id, 'created_by' => $member->id,
            'work_date' => now()->toDateString(), 'done' => 'بناء شاشة التقارير']);

        $this->actingAs($pm);
        $this->get(route('reports.review'))->assertOk();   // «مشاريعي» افتراضاً — لا 403
        $this->post(route('reports.review.act', $w->id), ['action' => 'accept'])->assertRedirect();
        $this->assertSame(\App\Support\Workforce\ReportReview::ACCEPTED, (string) $w->fresh()->review_status,
            'القرار متاح من الطابور نفسه بلا المرور ببوّابة hr:v');
    }

    /* ═══════ F12 — صحّةُ المشروع: المتوقّف لا يُقاس «سليماً» والعاجل يُعاقَب أشدّ ═══════ */

    public function test_project_health_paused_and_urgent_delay(): void
    {
        $this->seedCore();

        $paused = Project::create(['name' => 'مشروع متوقف', 'status' => 'متوقف']);
        $h = hub_project_health($paused->id, true);
        $this->assertSame('متوقف — بانتظار قرار', $h['label']);
        $this->assertLessThanOrEqual(60, $h['score'], 'الهدوءُ التامّ للمتوقّف ليس عافية');

        $urgent = Project::create(['name' => 'مشروع عاجل متأخر', 'status' => 'نشط',
            'priority' => 'عاجلة', 'launch_exp' => now()->subDays(5)->toDateString()]);
        $h2 = hub_project_health($urgent->id, true);
        $due = collect($h2['factors'])->firstWhere('k', 'الالتزام بالموعد');
        $this->assertNotNull($due);
        $this->assertLessThanOrEqual(60, $due['s'], 'تأخّرُ 5 أيام في عاجلٍ = 100−5×8');
        $this->assertStringContainsString('مشروع عاجل', (string) $due['note']);
    }

    /* ═══════ F13 — مهمةٌ تُنشأ منجزةً تُختم لحظةَ الإنشاء ═══════ */

    public function test_task_created_done_gets_completed_stamp(): void
    {
        $this->seedCore();
        $u = $this->user('creator-task@test.local', ['tasks' => ['v' => 1, 'a' => 1, 'e' => 1],
            'projects' => ['v' => 1]]);
        $p = Project::create(['name' => 'مشروع الأرشفة', 'status' => 'نشط']);
        $this->actingAs($u);

        $this->post(route('m.store', 'tasks'), ['title' => 'أرشفة تقارير الربع',
            'projectId' => $p->id, 'status' => 'منجزة'])
            ->assertRedirect(route('m.index', 'tasks'));
        $t = \App\Models\Task::where('title', 'أرشفة تقارير الربع')->orderBy('id')->first();
        $this->assertNotNull($t);
        $this->assertSame('منجزة', (string) $t->status, 'الحالة كُتبت كما أُرسلت');
        $this->assertNotNull($t->completed_at, 'الختمُ عند الإنشاء لا عند التحوّل فقط — وإلا عدّت اللوحات «0»');
    }

    /* ═══════ F32 — حسابُ العميل ليس خيارَ إسنادٍ داخليّ ═══════ */

    public function test_users_ref_options_exclude_client_accounts(): void
    {
        $this->seedCore();
        $internal = $this->user('teammate@test.local', ['tasks' => ['v' => 1]]);
        $client = $this->user('portal-client@test.local', ['projects' => ['v' => 1]]);
        $client->forceFill(['account_type' => 'client'])->save();

        $opts = hub_ref_options('users');
        $this->assertArrayHasKey((string) $internal->id, $opts);
        $this->assertArrayNotHasKey((string) $client->id, $opts,
            'عميلُ البوّابة لا يظهر خيارَ «مسؤول/مدير/مسنَد إليه» داخليّاً');

        // وقيمةٌ قائمةٌ محفوظة تبقى تُسترجع كي لا يَعمى نموذجُ سجلٍّ قديم
        $opts2 = hub_ref_options('users', [(string) $client->id]);
        $this->assertArrayHasKey((string) $client->id, $opts2);
    }

    /* ═══════ F21ب — سجلُّ الوثيقة «سري» نفسُه محجوبٌ لا مرفقُها فقط ═══════ */

    public function test_secret_document_record_hidden_without_docsec(): void
    {
        $this->seedCore();
        $hr = $this->user('hr-docsec@test.local', ['files' => ['v' => 1, 'e' => 1, 'docsec' => 1]]);
        $sales = $this->user('sales-nodocsec@test.local', ['files' => ['v' => 1]]);

        $this->actingAs($hr);
        $doc = \App\Models\Document::create(['name' => 'مسير رواتب الاختبار',
            'cat' => 'قانوني', 'secrecy' => 'سري', 'doc_no' => 'HR-TT-001']);
        $open = \App\Models\Document::create(['name' => 'سياسة عامة للاختبار',
            'cat' => 'ملفات الشركة', 'secrecy' => 'داخلي', 'doc_no' => 'POL-TT-001']);

        // مبيعاتٌ بلا docsec: «سري» لا في القائمة ولا بصفحة السجل — والداخليّ يبقى
        $this->actingAs($sales);
        $this->get(route('m.index', 'files'))
            ->assertOk()
            ->assertDontSee('مسير رواتب الاختبار')
            ->assertSee('سياسة عامة للاختبار');
        $this->get(route('m.show', ['files', $doc->id]))->assertNotFound();

        // حاملُ docsec (وهو رافعُها هنا) يبقى يرى — لا فقدَ لمن يحقّ له
        $this->actingAs($hr);
        $this->get(route('m.show', ['files', $doc->id]))->assertOk();
    }

    public function test_secret_document_visible_to_its_uploader(): void
    {
        $this->seedCore();
        $uploader = $this->user('uploader-doc@test.local', ['files' => ['v' => 1, 'a' => 1, 'e' => 1]]);
        $this->actingAs($uploader);
        $doc = \App\Models\Document::create(['name' => 'عقدي السري الخاص',
            'cat' => 'قانوني', 'secrecy' => 'سري', 'doc_no' => 'CT-TT-009']);

        // رافعُ الوثيقة يراها ولو بلا docsec — لا يُحجب المرءُ عمّا رفعه بيده
        $this->get(route('m.show', ['files', $doc->id]))->assertOk()->assertSee('عقدي السري الخاص');
    }

    /* ═══════ F33 — خرقُ SLA يُصعَّد مرةً واحدة (المسنَد إليه + مديره) ═══════ */

    public function test_sla_breach_escalates_once(): void
    {
        $this->seedCore();
        $assignee = $this->user('agent-sla@test.local', ['tickets' => ['v' => 1, 'e' => 1]]);
        $mgr = $this->user('mgr-sla@test.local', ['tickets' => ['v' => 1]]);
        Employee::create(['name' => 'موظف الدعم', 'user_id' => $assignee->id,
            'email' => $assignee->email, 'manager_id' => $mgr->id, 'status' => 'نشط', 'leave_bal' => 0]);

        $t = Ticket::create(['subject' => 'انقطاع خدمة البوابة', 'status' => 'جديدة',
            'priority' => 'عاجلة', 'assignee_id' => $assignee->id]);
        DB::table('tickets')->where('id', $t->id)
            ->update(['created_at' => now()->subDays(14), 'updated_at' => now()->subDays(14)]);

        (new AlertEngine)->evaluate(['components' => []]);

        $forAssignee = HubNotification::where('user_id', $assignee->id)->where('kind', 'ticket')->count();
        $forMgr = HubNotification::where('user_id', $mgr->id)->where('kind', 'ticket')->count();
        $this->assertSame(1, $forAssignee, 'المسنَدُ إليه يسمع بالخرق');
        $this->assertSame(1, $forMgr, 'ومديرُه يسمع بالتصعيد');

        // تقييمٌ ثانٍ لا يغرق أحداً — الختمُ في meta يمنع التكرار
        (new AlertEngine)->evaluate(['components' => []]);
        $this->assertSame(1, HubNotification::where('user_id', $assignee->id)->where('kind', 'ticket')->count());
        $this->assertSame(1, HubNotification::where('user_id', $mgr->id)->where('kind', 'ticket')->count());
    }
}
