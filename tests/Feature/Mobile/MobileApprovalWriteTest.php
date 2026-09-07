<?php

namespace Tests\Feature\Mobile;

use App\Models\Approval;
use App\Models\HubNotification;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **F3 · الكتابةُ المحمية + D.4 · الاعتمادات** — Mobile Readiness · الطور D.
 *
 * يُثبِت أنّ الكتابةَ المحميةَ بالموافقات في الجوال **لا تعيد المسدود** («نفّذها من
 * الواجهة») بل **تُصفّ طلباً حقيقيّاً** (صفٌّ في `approvals`) وتعيد `APPROVAL_REQUIRED`
 * (٢٠٢) بوجهةِ اعتماداتٍ للجوال، ثمّ يحسمه المعتمِدُ عبر الجوال فيُطبَّق التغيير — عبر
 * `ApprovalService` المشتركة (F2)، بحرّاسها كاملةً (نطاق + قفلٌ + تقادمُ meta.ver).
 */
class MobileApprovalWriteTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    private function seedTask(string $title = 'مهمة'): Task
    {
        $p = Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);

        return Task::create(['title' => $title, 'status' => 'جديدة', 'project_id' => $p->id]);
    }

    // ═══════════════════════ F3 · تصفيفٌ حقيقيّ لا مسدود ═══════════════════════

    public function test_approval_gated_put_submits_a_real_approval_and_never_the_web_deadend(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'tasks:e');
        $t = $this->seedTask();

        $before = Approval::count();
        $h = $this->auth($this->employee, 'inst-appr-put-1111');

        $res = $this->withHeaders($h)->putJson('/api/mobile/v1/tasks/' . $t->id, [
            'title' => 'عنوانٌ مُعدَّل عبر الجوال', 'projectId' => $t->project_id,
        ]);

        // ٢٠٢ APPROVAL_REQUIRED بوجهةِ اعتماداتٍ للجوال — لا ٤٠٩ مسدودٌ ولا نصُّ الويب
        $res->assertStatus(202)->assertJsonPath('code', 'APPROVAL_REQUIRED')
            ->assertJsonPath('details.approval.module', 'approvals');
        $this->assertStringNotContainsString('نفّذها من الواجهة', $res->getContent(),
            'الجوالُ يجب ألّا يعيد المسدودَ «نفّذها من الواجهة» (F3)');

        // صفٌّ حقيقيٌّ في الطابور — لا تنفيذٌ ولا تجاهل
        $this->assertSame($before + 1, Approval::count(), 'يجب أن يُنشَأ طلبُ موافقةٍ حقيقيّ');
        $apId = $res->json('details.approval.id');
        $ap = Approval::find($apId);
        $this->assertNotNull($ap);
        $this->assertSame('tasks', $ap->mod);
        $this->assertSame((string) $t->id, (string) $ap->record_id);
        $this->assertSame('e', $ap->op);
        $this->assertSame('معلّق', $ap->status);
        $this->assertSame((string) $this->employee->id, (string) $ap->requested_by);
        $this->assertSame('عنوانٌ مُعدَّل عبر الجوال', data_get($ap->payload, 'title'), 'الحمولةُ الملتقطةُ محفوظة');

        // ولم يُطبَّق التغييرُ بعد — الطلبُ معلّق
        $this->assertSame('مهمة', $t->fresh()->title, 'لم يتغيّر السجلُّ قبل الحسم');
    }

    public function test_approval_gated_patch_also_submits_not_deadends(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'tasks:e');
        $t = $this->seedTask();
        $h = $this->auth($this->employee, 'inst-appr-patch-111');

        $res = $this->withHeaders($h)->patchJson('/api/mobile/v1/tasks/' . $t->id, ['title' => 'جزئيٌّ مُعدَّل']);
        $res->assertStatus(202)->assertJsonPath('code', 'APPROVAL_REQUIRED');
        $this->assertStringNotContainsString('نفّذها من الواجهة', $res->getContent());
        $this->assertSame(1, Approval::where('mod', 'tasks')->where('record_id', $t->id)->count());
    }

    public function test_approval_gated_delete_submits_a_delete_request(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'tasks:d');
        $t = $this->seedTask();

        // مستخدمٌ يملك الحذفَ فعلاً (d=1) لكنه ليس معتمِداً ولا مالكاً — فالحذفُ محكومٌ
        // بالموافقة (لا يُطلَب موافقةٌ على عمليةٍ لا يملكها المستخدمُ أصلاً — لذا لا نستعمل
        // الموظفةَ التي d=0 عندها فتُردّ FORBIDDEN بحقّ قبل بلوغ بوّابة الموافقة).
        $u = $this->userWithDelete();
        $h = $this->auth($u, 'inst-appr-del-1111');

        $res = $this->withHeaders($h)->deleteJson('/api/mobile/v1/tasks/' . $t->id);
        $res->assertStatus(202)->assertJsonPath('code', 'APPROVAL_REQUIRED');
        $ap = Approval::where('mod', 'tasks')->where('record_id', $t->id)->firstOrFail();
        $this->assertSame('d', $ap->op);
        $this->assertSame((string) $u->id, (string) $ap->requested_by);
        $this->assertNull($t->fresh()->deleted_at, 'لم يُحذَف قبل الحسم');
    }

    /** مستخدمٌ بمصفوفةٍ كاملة (d=1) بلا رايةِ approve ولا ملكيّة — يملك الحذفَ ويخضع للموافقة */
    private function userWithDelete(): User
    {
        $modules = array_keys(config('hub.modules'));
        $full = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $role = \App\Models\Role::create(['name' => 'مديرُ عملياتٍ (بلا اعتماد)', 'scope' => 'all',
            'flags' => [], 'matrix' => $full]);

        return User::create(['name' => 'مديرُ عمليات', 'email' => 'ops-del@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    // ═══════════════════════ D.4 · الحسمُ عبر الجوال ═══════════════════════

    public function test_approver_approves_via_mobile_and_the_change_applies(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'tasks:e');
        $t = $this->seedTask();

        // الموظفةُ تُصفّ الطلب
        $reqH = $this->auth($this->employee, 'inst-appr-flow-req-1');
        $apId = $this->withHeaders($reqH)->putJson('/api/mobile/v1/tasks/' . $t->id, [
            'title' => 'مُعتمَدة', 'projectId' => $t->project_id,
        ])->assertStatus(202)->json('details.approval.id');

        // المالكُ (معتمِد) يعتمد عبر الجوال ⇒ يُطبَّق التغيير
        $apprH = $this->auth($this->owner, 'inst-appr-flow-apr-1');
        $this->withHeaders($apprH)->postJson('/api/mobile/v1/approvals/' . $apId . '/approve')
            ->assertOk()->assertJsonPath('data.code', 'approved');

        $this->assertSame('مُعتمَدة', $t->fresh()->title, 'الاعتمادُ عبر الجوال طبّق الحمولةَ الملتقطة');
        $this->assertSame('معتمد', Approval::find($apId)->status);
    }

    public function test_approver_rejects_via_mobile_and_notifies_requester_without_applying(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'tasks:e');
        $t = $this->seedTask();

        $reqH = $this->auth($this->employee, 'inst-appr-rej-req-1');
        $apId = $this->withHeaders($reqH)->putJson('/api/mobile/v1/tasks/' . $t->id, [
            'title' => 'مرفوضة', 'projectId' => $t->project_id,
        ])->assertStatus(202)->json('details.approval.id');

        $notifBefore = HubNotification::where('user_id', $this->employee->id)->count();

        $apprH = $this->auth($this->owner, 'inst-appr-rej-apr-1');
        $this->withHeaders($apprH)->postJson('/api/mobile/v1/approvals/' . $apId . '/reject', ['note' => 'غيرُ مبرّر'])
            ->assertOk()->assertJsonPath('data.code', 'rejected');

        $this->assertSame('مهمة', $t->fresh()->title, 'الرفضُ لا يطبّق شيئاً');
        $this->assertSame('مرفوض', Approval::find($apId)->status);
        $this->assertGreaterThan($notifBefore, HubNotification::where('user_id', $this->employee->id)->count(),
            'يُشعَر الطالبُ بقرار الرفض');
    }

    public function test_approving_a_version_drifted_record_is_a_version_conflict(): void
    {
        $this->seedCore();
        $t = $this->seedTask();
        $apId = (string) Str::uuid();
        DB::table('approvals')->insert([
            'id' => $apId, 'title' => 'تعديلٌ متقادم', 'type' => 'تعديل', 'mod' => 'tasks',
            'record_id' => $t->id, 'op' => 'e', 'status' => 'معلّق',
            'payload' => json_encode(['title' => 'قيمةٌ قديمة'], JSON_UNESCAPED_UNICODE),
            'requested_by' => $this->employee->id,
            'meta' => json_encode(['ver' => (int) $t->version]),   // النسخةُ وقت الطلب
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // السجلُّ يتغيّر بعد الطلب — النسخةُ تتزحزح
        $t->update(['title' => 'أُثري بعد الطلب']);

        $apprH = $this->auth($this->owner, 'inst-appr-drift-11');
        $this->withHeaders($apprH)->postJson('/api/mobile/v1/approvals/' . $apId . '/approve')
            ->assertStatus(409)->assertJsonPath('code', 'VERSION_CONFLICT');

        $this->assertSame('معلّق', Approval::find($apId)->status, 'لم يُحسَم — تقادمٌ صريح');
        $this->assertSame('أُثري بعد الطلب', $t->fresh()->title, 'لم تُعَد الحمولةُ القديمة');
    }

    // ═══════════════════════ D.4 · نطاقُ القائمة/العرض/الحسم ═══════════════════════

    public function test_approvals_queue_is_visible_only_to_approvers(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'tasks:e');
        $t = $this->seedTask();

        // طلبٌ معلّق
        $reqH = $this->auth($this->employee, 'inst-appr-q-req-11');
        $apId = $this->withHeaders($reqH)->putJson('/api/mobile/v1/tasks/' . $t->id, [
            'title' => 'في الطابور', 'projectId' => $t->project_id,
        ])->assertStatus(202)->json('details.approval.id');

        // المعتمِد (المالك) يرى الطابور — الطلبُ فيه بوجهةِ رابطٍ عميق
        $apprH = $this->auth($this->owner, 'inst-appr-q-apr-11');
        $list = $this->withHeaders($apprH)->getJson('/api/mobile/v1/approvals')->assertOk();
        $ids = collect($list->json('data'))->pluck('id')->all();
        $this->assertContains($apId, $ids, 'المعتمِدُ يرى الطلبَ المعلّق');
        $card = collect($list->json('data'))->firstWhere('id', $apId);
        $this->assertSame(['module' => 'tasks', 'id' => (string) $t->id], $card['target'],
            'كلُّ عنصرٍ يحمل وجهةَ رابطٍ عميق {module,id}');

        // وغيرُ المعتمِد (المشاهد: لا مالكٌ ولا رايةُ approve) يرى طابوراً فارغاً — لا تسريب.
        // وبنفس مغلَّف القائمة: `data` مصفوفةٌ دائماً (عقدٌ موحّدٌ لا يتفرّع بالدور).
        $viewerH = $this->auth($this->viewer, 'inst-appr-q-view-11');
        $empty = $this->withHeaders($viewerH)->getJson('/api/mobile/v1/approvals')->assertOk();
        $this->assertSame([], $empty->json('data'), 'طابورُ غيرِ المعتمِد فارغٌ بنفس مغلَّف القائمة');
        $this->assertSame(0, $empty->json('total'), 'العددُ صفرٌ في المغلَّف الموحّد');
    }

    /**
     * **إجراءُ حالةٍ محميّ يُصفّ تغييرَ الحالة وحدَه** — لا يركب حقلٌ كاتبٌ آخر دسّه
     * العميلُ في جسم الإجراء على قرارِ المعتمِد خفيةً (المعتمِد يعتمد ما يراه: الحالة).
     */
    public function test_a_protected_status_action_queues_only_the_status_change(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'tasks:e');
        $t = $this->seedTask('العنوانُ الأصليّ');
        $h = $this->auth($this->employee, 'inst-appr-statusonly-1');

        // إجراءُ حالةٍ محميّ، ومعه يُدَسُّ حقلٌ كاتبٌ آخر (title)
        $res = $this->withHeaders($h)->postJson('/api/mobile/v1/tasks/' . $t->id . '/actions/status', [
            'to' => 'قيد التنفيذ', 'title' => 'عنوانٌ مدسوسٌ لا يراه المعتمِد',
        ]);
        $res->assertStatus(202)->assertJsonPath('code', 'APPROVAL_REQUIRED');

        $ap = Approval::latest('id')->first();
        $this->assertNotNull($ap, 'صُفَّ طلبُ موافقةٍ للحالة');
        $payload = (array) $ap->payload;
        $this->assertArrayNotHasKey('title', $payload, 'الحقلُ المدسوسُ لا يدخل حمولةَ إجراءِ الحالة');
        $this->assertNotContains('عنوانٌ مدسوسٌ لا يراه المعتمِد', array_values($payload),
            'قيمةُ الحقلِ المدسوس لا تُصفَّف خفيةً');
        $this->assertSame('العنوانُ الأصليّ', $t->fresh()->title, 'لا كتابةَ قبل الاعتماد');

        // وبعد الاعتماد: الحالةُ تتغيّر والعنوانُ يبقى (لم يُطبَّق المدسوس)
        $apprH = $this->auth($this->owner, 'inst-appr-statusonly-ap');
        $this->withHeaders($apprH)->postJson('/api/mobile/v1/approvals/' . $ap->id . '/approve')->assertOk();
        $fresh = $t->fresh();
        $this->assertSame('العنوانُ الأصليّ', $fresh->title, 'الاعتمادُ لا يطبّق الحقلَ المدسوس');
        $this->assertSame('قيد التنفيذ', $fresh->status, 'الاعتمادُ يطبّق تغييرَ الحالة');
    }

    public function test_requester_can_view_own_approval_but_a_stranger_cannot(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'tasks:e');
        $t = $this->seedTask();

        $reqH = $this->auth($this->employee, 'inst-appr-own-req-1');
        $apId = $this->withHeaders($reqH)->putJson('/api/mobile/v1/tasks/' . $t->id, [
            'title' => 'طلبي', 'projectId' => $t->project_id,
        ])->assertStatus(202)->json('details.approval.id');

        // الطالبُ يرى طلبَه (وإن لم يكن معتمِداً) ليتابع قراره
        $this->withHeaders($reqH)->getJson('/api/mobile/v1/approvals/' . $apId)
            ->assertOk()->assertJsonPath('data.approval.id', $apId);

        // وغريبٌ (لا طالبٌ ولا معتمِد) ٤٠٣
        $strangerH = $this->auth($this->viewer, 'inst-appr-own-str-1');
        $this->withHeaders($strangerH)->getJson('/api/mobile/v1/approvals/' . $apId)
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_a_non_approver_cannot_decide(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'tasks:e');
        $t = $this->seedTask();

        $reqH = $this->auth($this->employee, 'inst-appr-nd-req-1');
        $apId = $this->withHeaders($reqH)->putJson('/api/mobile/v1/tasks/' . $t->id, [
            'title' => 'لا يحسمه غيرُ معتمِد', 'projectId' => $t->project_id,
        ])->assertStatus(202)->json('details.approval.id');

        // المشاهدُ ليس معتمِداً — الحسمُ ممنوع (الحارسُ داخلَ ApprovalService)
        $viewerH = $this->auth($this->viewer, 'inst-appr-nd-view-1');
        $this->withHeaders($viewerH)->postJson('/api/mobile/v1/approvals/' . $apId . '/approve')
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
        $this->assertSame('معلّق', Approval::find($apId)->status);
    }

    // ═══════════════════════ F1 · الحسمُ قابلٌ لإعادة المحاولة (Idempotency) ═══════════════════════

    public function test_mobile_approve_is_idempotent_under_a_key(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'tasks:e');
        $t = $this->seedTask();

        $reqH = $this->auth($this->employee, 'inst-appr-idem-req');
        $apId = $this->withHeaders($reqH)->putJson('/api/mobile/v1/tasks/' . $t->id, [
            'title' => 'حسمٌ لمرّة', 'projectId' => $t->project_id,
        ])->assertStatus(202)->json('details.approval.id');

        $apprH = $this->auth($this->owner, 'inst-appr-idem-apr') + ['Idempotency-Key' => 'decide-key-1'];
        $a = $this->withHeaders($apprH)->postJson('/api/mobile/v1/approvals/' . $apId . '/approve')->assertOk();
        $b = $this->withHeaders($apprH)->postJson('/api/mobile/v1/approvals/' . $apId . '/approve')->assertOk();

        $b->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($a->json('data.code'), $b->json('data.code'));
        $this->assertSame('معتمد', Approval::find($apId)->status);
    }
}
