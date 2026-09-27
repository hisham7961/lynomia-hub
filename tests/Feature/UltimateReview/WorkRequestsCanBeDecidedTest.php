<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Approval;
use App\Models\Role;
use App\Models\User;
use App\Support\Platform\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **«قراراتٌ تنتظر حسمك» تنتظر ما لا مسارَ له** (المراجعةُ الشاملة · الطبقة ٢ · L2-01).
 *
 * وُجِد حيّاً: مديرٌ يحمل رايةَ الاعتماد يفتح صباحَه فيقرأ بطاقةً تقول
 * **«✋ قرارات تنتظر حسمك ٢ — عمليات موقوفة لن تُنفَّذ قبل اعتمادك»**، وتحتها
 * البندان بأسمائهما مربوطَين. يفتح أحدَهما — فلا زرَّ اعتمادٍ ولا رفض. النماذجُ
 * الوحيدةُ في الصفحة: خروجٌ، وتعليقٌ، وإرفاق.
 *
 * والسببُ أنّ بطاقةَ الحسمِ ملفوفةٌ بـ`@if ($row->mod)` — بُنيت **للعمليّاتِ
 * المحميّة** (تعديلٌ/حذفٌ على سجلّ) لا **لطلباتِ العمل** (شراءٌ · مصروفٌ ·
 * إجازة). وطلبُ العملِ لا `mod` له ولا `record_id`.
 *
 * وفي بيانات المحاكاة: ثلاثةُ طلباتٍ — الوحيدُ الذي له `mod` **محسومٌ**،
 * والمعلّقان **كلاهما بلا `mod`**. أي أنّ **١٠٠٪ ممّا ينتظر قراراً غيرُ قابلٍ
 * للحسم**.
 *
 * والمنتجُ يعرف ذلك ويقوله بصدق — في ردٍّ لن يراه أحد:
 * «هذا طلبُ عملٍ … **ولا مسارَ حسمٍ له في المنتج بعد**». صدقٌ محبوسٌ خلف زرٍّ
 * لم يُرسَم. فالمديرُ يقرأ أنّ العملَ موقوفٌ عليه، ولا يجد ما يرفعه به.
 *
 * **وهذه الحزمةُ تُكمل القدرةَ لا تحذف البطاقة** — والإكمالُ يلزمه حارسٌ:
 * طلبُ عملٍ لا سجلَّ خلفَه، فلا يُحمى بنطاقِ سجلٍّ كما تُحمى العمليّةُ المحميّة.
 * فيُحمى بنطاقِ وحدتِه نفسِها (`hub_scope(approvals)`) وبمن يُنتظَر قرارُه
 * (`approver_id` أو `chain`) — **بالتعريفِ نفسِه الذي تَعُدُّ به لوحةُ التنفيذ**،
 * كي يتّفق ما تُعلنه الشاشةُ وما يسمح به الحارس.
 */
class WorkRequestsCanBeDecidedTest extends TestCase
{
    /**
     * معتمِدٌ معزولٌ بشركة — **والعزلُ من `companies` لا من `company_id`**:
     * الأوّلُ قائمةُ ما يراه (يقرؤها `hub_company_ids`)، والثاني انتماؤه هو.
     * وضبطُ الثاني وحدَه يُنتج مستخدماً **غيرَ معزولٍ أصلاً** — فيمرُّ اختبارُ
     * العزلِ وهو لم يختبر شيئاً.
     */
    private function approver(string $label = 'معتمِد', ?string $companyId = null): User
    {
        $role = Role::create(['name' => $label . Str::random(4), 'scope' => 'all',
            'flags' => ['approve' => 1], 'matrix' => ['approvals' => ['v' => 1, 'a' => 1, 'e' => 1]]]);

        return User::create(['name' => $label, 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'company_id' => $companyId,
            'companies' => $companyId ? [$companyId] : [],
            'password_changed_at' => now()]);
    }

    /** طلبُ عملٍ حقيقيّ: شراءٌ بمبلغ، بلا `mod` ولا `record_id` */
    private function workRequest(User $approver, ?User $requester = null, ?string $companyId = null): Approval
    {
        return Approval::create([
            'title' => 'شراءُ ثلاثةِ حواسيبَ محمولةٍ لفريقِ التطوير',
            'type' => 'شراء', 'amount' => 1500, 'currency' => 'د.ك',
            'reason' => 'أجهزةُ الفريقِ تجاوزت عمرَها',
            'status' => 'معلّق', 'approver_id' => $approver->id,
            'requested_by' => $requester?->id, 'company_id' => $companyId,
        ]);
    }

    // ═══════════ ١ · الشاشةُ تعرض ما يُحسَم به ═══════════

    public function test_an_approver_sees_decision_controls_on_a_pending_work_request(): void
    {
        $this->seedCore();
        $ap = $this->workRequest($this->owner, $this->employee);

        $html = $this->actingAs($this->owner)
            ->get(route('m.show', ['approvals', $ap->id]))->assertOk()->getContent();

        $this->assertStringContainsString(route('approvals.approve', $ap->id), $html,
            'صفحةُ طلبِ العملِ لا تحمل نموذجَ اعتماد — والبطاقةُ تقول إنّه ينتظر قرارَه');
        $this->assertStringContainsString(route('approvals.reject', $ap->id), $html,
            'ولا نموذجَ رفض — فالقرارُ الوحيدُ المتاحُ هو «لا قرار»');
    }

    // ═══════════ ٢ · الحسمُ يقع فعلاً ويصل صاحبَه ═══════════

    public function test_approving_a_work_request_marks_it_approved_and_tells_the_requester(): void
    {
        $this->seedCore();
        $ap = $this->workRequest($this->owner, $this->employee);

        $this->actingAs($this->owner)->post(route('approvals.approve', $ap->id))
            ->assertRedirect();

        $ap->refresh();
        $this->assertSame('معتمد', $ap->status, 'لم تُعتمد');
        $this->assertNotNull($ap->decided_at, '`decided_at` لم يُختَم — فالحسمُ لا أثرَ له');
        $this->assertSame($this->owner->id, $ap->decided_by);
        $this->assertTrue(
            DB::table('notifications_hub')->where('user_id', $this->employee->id)
                ->where('record_id', $ap->id)->exists(),
            'الطالبُ لم يُبلَّغ بقرارٍ يخصّ طلبَه');
    }

    public function test_rejecting_a_work_request_carries_the_reason_to_the_requester(): void
    {
        $this->seedCore();
        $ap = $this->workRequest($this->owner, $this->employee);

        $this->actingAs($this->owner)
            ->post(route('approvals.reject', $ap->id), ['note' => 'الميزانيةُ مستنفدةٌ هذا الربع'])
            ->assertRedirect();

        $ap->refresh();
        $this->assertSame('مرفوض', $ap->status);
        $this->assertNotNull($ap->decided_at);
        $body = (string) DB::table('notifications_hub')->where('user_id', $this->employee->id)
            ->where('record_id', $ap->id)->value('text');
        $this->assertStringContainsString('الميزانيةُ مستنفدةٌ', $body,
            'رُفض الطلبُ ولم يصل صاحبَه السبب — والرفضُ بلا سببٍ يُعاد طلبُه غداً');
    }

    // ═══════════ ٣ · حرّاسٌ لا تُفتَح بالإكمال ═══════════

    public function test_a_decided_work_request_is_not_decided_twice(): void
    {
        $this->seedCore();
        $ap = $this->workRequest($this->owner, $this->employee);
        $this->actingAs($this->owner)->post(route('approvals.approve', $ap->id))->assertRedirect();
        $at = $ap->refresh()->decided_at;

        $this->actingAs($this->owner);
        $res = ApprovalService::decide($ap->id, 'reject', $this->owner, ['note' => 'عدول']);

        $this->assertSame(ApprovalService::ALREADY_DECIDED, $res->code);
        $this->assertSame('معتمد', $ap->refresh()->status, 'انقلب قرارٌ محسوم');
        $this->assertEquals($at, $ap->decided_at, 'تغيّر ختمُ الحسم');
    }

    public function test_an_approver_outside_the_company_cannot_decide_a_work_request(): void
    {
        $this->seedCore();
        if (! hub_company_col('approvals')) {
            $this->markTestSkipped('وحدةُ الموافقاتِ بلا عمودِ شركةٍ في هذه التهيئة');
        }
        $mine = (string) Str::uuid();
        $theirs = (string) Str::uuid();
        DB::table('companies')->insert([
            ['id' => $mine, 'name_ar' => 'شركتي', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $theirs, 'name_ar' => 'شركةٌ أخرى', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $outsider = $this->approver('معتمِدُ شركةٍ أخرى', $theirs);
        $ap = $this->workRequest($outsider, $this->employee, $mine);

        $this->actingAs($outsider);
        $res = ApprovalService::decide($ap->id, 'approve', $outsider);

        $this->assertSame(ApprovalService::FORBIDDEN, $res->code,
            'معتمِدُ شركةٍ حسم طلبَ شركةٍ أخرى — والإكمالُ لا يفتح ما أُغلق');
        $this->assertSame('معلّق', $ap->refresh()->status);
    }

    public function test_a_user_without_the_approve_flag_is_refused(): void
    {
        $this->seedCore();
        $ap = $this->workRequest($this->owner, $this->employee);

        $this->actingAs($this->employee);
        $res = ApprovalService::decide($ap->id, 'approve', $this->employee);

        $this->assertSame(ApprovalService::FORBIDDEN, $res->code);
        $this->assertSame('معلّق', $ap->refresh()->status);
    }

    // ═══════════ ٤ · «تنتظر حسمك» تعني حسمَك أنت ═══════════

    /**
     * **شرطُ البطاقةِ هو شرطُ الحارس — أو البطاقةُ تكذب.**
     *
     * بطاقةُ الصباحِ كانت مشروطةً بـ`hub_can('approvals','v')` وحدَها: **كلُّ من
     * يرى** الموافقاتِ يُقال له «✋ قرارات تنتظر حسمك — عمليات موقوفة لن تُنفَّذ
     * قبل اعتمادك»، ولو كان الطلبُ ينتظر قرارَ غيرِه، بل ولو لم يكن معتمِداً
     * أصلاً. وهذا ليس زخرفاً: إنّه **نسبةُ مسؤوليّةٍ إلى من لا يملكها** — مديرٌ
     * يظنّ العملَ موقوفاً عليه وهو لا يملك حسمَه، وصاحبُ القرارِ الحقيقيُّ يرى
     * البطاقةَ نفسَها فلا يميّز طلبَه من طلبِ غيره.
     *
     * وُجِد حيّاً: طلبان ينتظران **غيثاً**، والبطاقةُ تعرضهما لـ**راشد**
     * منسوبَين إليه — و٤٠٣ الحارسِ هي ما كشفه.
     */
    public function test_the_morning_card_claims_only_the_decisions_that_are_actually_yours(): void
    {
        $this->seedCore();
        $mine = $this->approver('معتمِدٌ صاحبُ القرار');
        $other = $this->approver('معتمِدٌ آخر');

        $ap = $this->workRequest($other, $this->employee);   // ينتظر «الآخر» لا «صاحبَ القرار»
        $this->assertSame($other->id, $ap->approver_id);

        $html = $this->actingAs($mine)->get(route('morning'))->assertOk()->getContent();
        $this->assertStringNotContainsString($ap->title, $html,
            'بطاقةُ الصباحِ نسبت إليه قراراً ينتظر غيرَه — والبطاقةُ التي تكذب تُعلّم قارئَها تجاهلَها');

        // وصاحبُه يراه
        $his = $this->actingAs($other)->get(route('morning'))->assertOk()->getContent();
        $this->assertStringContainsString($ap->title, $his,
            'صاحبُ القرارِ لا يرى ما ينتظره — وهذا أسوأُ من نسبتِه إلى غيره');
    }

    /** ومن يرى الموافقاتِ بلا رايةِ اعتمادٍ لا يُقال له إنّ العملَ موقوفٌ عليه */
    public function test_a_mere_viewer_is_not_told_work_waits_on_their_approval(): void
    {
        $this->seedCore();
        $ap = $this->workRequest($this->owner, $this->employee);

        $html = $this->actingAs($this->viewer)->get(route('morning'))->assertOk()->getContent();

        $this->assertStringNotContainsString('قرارات تنتظر حسمك', $html,
            'قارئٌ بلا رايةِ اعتمادٍ قيل له إنّ عمليّاتٍ موقوفةٌ على اعتماده');
        $this->assertStringNotContainsString($ap->title, $html);
    }

    // ═══════════ ٥ · العمليّةُ المحميّةُ كما كانت حرفاً بحرف ═══════════

    /**
     * الإكمالُ يمسّ طلبَ العملِ وحدَه. والعمليّةُ المحميّةُ (تعديلٌ على سجلّ)
     * تبقى محكومةً بحرّاسها: صلاحيةُ الوحدةِ ونطاقُ السجلِّ الهدف.
     */
    public function test_a_protected_operation_still_requires_module_permission(): void
    {
        $this->seedCore();
        $viewerRole = Role::create(['name' => 'معتمِدٌ بلا تحرير' . Str::random(4), 'scope' => 'all',
            'flags' => ['approve' => 1], 'matrix' => ['tasks' => ['v' => 1]]]);
        $weak = User::create(['name' => 'معتمِدٌ بلا تحرير', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $viewerRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $task = \App\Models\Task::create(['title' => 'مهمّةٌ محميّة', 'status' => 'جديدة']);
        $ap = Approval::create([
            'title' => 'تعديل المهام: مهمّةٌ محميّة', 'type' => 'عملية محمية', 'status' => 'معلّق',
            'mod' => 'tasks', 'record_id' => $task->id, 'op' => 'e',
            'payload' => ['title' => 'عنوانٌ جديد'], 'approver_id' => $weak->id,
        ]);

        $this->actingAs($weak);
        $res = ApprovalService::decide($ap->id, 'approve', $weak);

        $this->assertSame(ApprovalService::FORBIDDEN, $res->code,
            'حُسمت عمليّةٌ محميّةٌ بلا صلاحيةِ وحدتِها — الاعتمادُ تنفيذٌ لا تأشير');
        $this->assertSame('مهمّةٌ محميّة', $task->refresh()->title);
    }
}
