<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\ErrorEvent;
use App\Models\HubNotification;
use App\Models\Task;
use App\Support\ErrorLog;
use App\Support\IssueState;
use App\Support\SecurityEvents;
use Tests\TestCase;

/**
 * **دورةُ حياة الخطأ (WP-3.3): خمسُ حالات، وانحدارٌ مختوم، وإسنادٌ مدقَّق.**
 *
 * القواعد التي يحرسها هذا الملف:
 *   · الإهمالُ («متجاهَل») **يشترط سبباً** — إخفاءُ عطلٍ بلا تعليلٍ ليس قراراً بل نسيان.
 *   · الحلُّ يختم `resolved_at/by/release` — فيُعرف «أُصلح متى وبأيّ نسخة».
 *   · خطأٌ حُلّ ثم عاد ⇒ `regressed_at` + `regression_release` + **إشعارٌ واحد** لا أكثر —
 *     العودةُ خبرٌ يُقال مرةً، والتكرارُ بعدها عدٌّ صامت.
 *   · كلُّ انتقالِ حالةٍ وإسنادٍ وإهمالٍ وكتمٍ **يكتب قيدَ تدقيق** (§31) — كانت
 *     `status/toTask` بلا أثرٍ إطلاقاً، فتغييرُ مستوى التحكّم لا شاهدَ عليه.
 *   · الإسنادُ يستعمل مهمّةَ الإصلاح القائمة ولا يكرّرها.
 */
class ErrorLifecycleTest extends TestCase
{
    protected function err(array $extra = []): ErrorEvent
    {
        return ErrorEvent::create(array_merge([
            'hash' => hash('sha256', uniqid('', true)), 'kind' => 'php',
            'message' => 'انفجارٌ في مولّد كشوف الشهر',
            'file' => base_path('app/Support/helpers.php'), 'line' => 42,
            'count' => 3, 'status' => 'جديد',
            'first_seen' => now()->subDay(), 'last_seen' => now(),
        ], $extra));
    }

    protected function audits(string $action): int
    {
        return AuditEntry::where('action', $action)->count();
    }

    /** الإهمالُ بلا سببٍ يُرفض ٤٢٢ — بالمفتاح الإنجليزي وبالتسمية العربية سواء */
    public function test_ignoring_without_a_reason_is_rejected_with_422(): void
    {
        $this->seedCore();
        $e = $this->err();

        $this->actingAs($this->owner)
            ->postJson('/admin/errors/' . $e->id . '/status', ['to' => 'متجاهَل'])
            ->assertStatus(422);
        $this->actingAs($this->owner)
            ->postJson('/admin/errors/' . $e->id . '/status', ['to' => 'ignored'])
            ->assertStatus(422);

        $this->assertSame('جديد', $e->fresh()->status, 'رفضُ السبب الغائب لا يغيّر الحالة');
        $this->assertSame(0, $this->audits('تجاهل خطأ'), 'لا قيدَ تدقيقٍ لانتقالٍ لم يقع');
    }

    /** الإهمالُ بسببٍ يختم السببَ وصاحبَه ويُدقَّق */
    public function test_ignoring_with_a_reason_stamps_and_audits(): void
    {
        $this->seedCore();
        $e = $this->err();

        $this->actingAs($this->owner)
            ->post('/admin/errors/' . $e->id . '/status', ['to' => 'متجاهَل', 'reason' => 'ضجيجُ مكوّنٍ خارجيّ لا نملك إصلاحه'])
            ->assertRedirect();

        $f = $e->fresh();
        $this->assertSame('متجاهَل', $f->status);
        $this->assertStringContainsString('ضجيجُ مكوّنٍ خارجيّ', (string) $f->ignored_reason);
        $this->assertSame($this->owner->id, $f->ignored_by);
        $this->assertSame(1, $this->audits('تجاهل خطأ'), 'الإهمالُ قرارٌ يُدقَّق (§31)');
    }

    /** الحلُّ يختم، والعودةُ بعده انحدارٌ مختومٌ بإشعارٍ واحد */
    public function test_resolve_then_recurrence_marks_regression_with_one_notification(): void
    {
        $this->seedCore();
        $boom = fn () => ErrorLog::capture('php', 'عطلُ المولّد بعد الترقية', '/app/Gen.php', 7);

        $this->actingAs($this->owner);
        $boom();   // أول ظهور ⇒ إشعار «عطل جديد»
        $e = ErrorEvent::orderBy('first_seen')->orderBy('id')->firstOrFail();

        // الحلُّ يختم من/متى/بأيّ نسخة
        $this->post('/admin/errors/' . $e->id . '/status', ['to' => 'محلول'])->assertRedirect();
        $f = $e->fresh();
        $this->assertSame('محلول', $f->status);
        $this->assertNotNull($f->resolved_at, 'الحل يختم وقته');
        $this->assertSame($this->owner->id, $f->resolved_by, 'الحل يختم صاحبه');
        $this->assertSame(mb_substr((string) config('hub.version'), 0, 20), $f->resolved_release, 'الحل يختم نسخته');
        $this->assertSame(1, $this->audits('تغيير حالة خطأ'), 'انتقالُ الحالة يُدقَّق');

        // العودةُ بعد الحل ⇒ انحدارٌ مختوم + عودةٌ إلى «جديد» + إشعارٌ واحد
        $boom();
        $f = $e->fresh();
        $this->assertSame('جديد', $f->status, 'العائد يعود «جديد» ليلفت النظر');
        $this->assertNotNull($f->regressed_at, 'العودةُ بعد الحل تُختم انحداراً');
        $this->assertSame(mb_substr((string) config('hub.version'), 0, 20), $f->regression_release, 'نسخةُ الانحدار مختومة');
        $this->assertSame(1, ErrorEvent::whereNotNull('regressed_at')->count(), 'انحدارٌ واحدٌ معدود');

        $regressionNews = HubNotification::where('user_id', $this->owner->id)->where('kind', 'error')
            ->where('text', 'LIKE', '%عاد بعد أن حُسب محلولاً%')->count();
        $this->assertSame(1, $regressionNews, 'العودةُ خبرٌ يُقال مرة واحدة');

        // تكرارٌ ثالثٌ بعد العودة: عدٌّ صامت لا إشعارَ ثانياً ولا انحدارَ ثانياً
        $boom();
        $this->assertSame(1, HubNotification::where('user_id', $this->owner->id)->where('kind', 'error')
            ->where('text', 'LIKE', '%عاد بعد أن حُسب محلولاً%')->count());
        $this->assertSame(3, (int) $e->fresh()->count, 'العدّ يستمر بلا ضجيج');
    }

    /** كلُّ انتقالِ حالةٍ يكتب قيدَ تدقيق — والمفتاحُ الإنجليزي يُقبل ويُخزَّن بتسميته */
    public function test_every_transition_writes_an_audit_row(): void
    {
        $this->seedCore();
        $e = $this->err();
        $this->actingAs($this->owner);

        foreach (['investigating' => 'قيد التحقيق', 'in_progress' => 'قيد المعالجة', 'resolved' => 'محلول'] as $key => $label) {
            $this->post('/admin/errors/' . $e->id . '/status', ['to' => $key])->assertRedirect();
            $this->assertSame($label, $e->fresh()->status, "المفتاح {$key} يُخزَّن بتسميته العربية");
        }
        $this->post('/admin/errors/' . $e->id . '/status', ['to' => 'متجاهَل', 'reason' => 'قرار مالك'])->assertRedirect();

        $this->assertSame(3, $this->audits('تغيير حالة خطأ'), 'ثلاثةُ انتقالاتٍ = ثلاثةُ قيود');
        $this->assertSame(1, $this->audits('تجاهل خطأ'), 'والإهمالُ قيدٌ باسمه');
        $this->assertSame($e->id, AuditEntry::where('action', 'تجاهل خطأ')->orderBy('id')->firstOrFail()->record_id,
            'القيدُ يشير إلى الخطأ نفسه');
    }

    /** الإسنادُ يُدقَّق ولا يكرّر المهمة — ويختم المسؤول والأولوية والموعد على الخطأ */
    public function test_assignment_is_audited_and_does_not_duplicate_the_task(): void
    {
        $this->seedCore();
        $e = $this->err(['count' => 12]);
        $due = now()->addDays(3)->toDateString();

        $this->actingAs($this->owner)->post('/admin/errors/' . $e->id . '/task', [
            'assignee_id' => $this->employee->id, 'priority' => 'متوسطة', 'due_at' => $due,
        ])->assertRedirect();

        $task = Task::where('title', 'LIKE', '%إصلاح%')->firstOrFail();
        $this->assertSame($this->employee->id, $task->assignee_id, 'المهمة تحمل المسؤول');
        $this->assertSame('متوسطة', $task->priority, 'الأولويةُ المختارة تغلب المشتقّة');

        $f = $e->fresh();
        $this->assertSame($this->employee->id, $f->assignee_id, 'الخطأ يختم مسؤوله');
        $this->assertSame('متوسطة', $f->priority);
        $this->assertSame($due, (string) $f->due_at);
        $this->assertSame(1, $this->audits('إسناد خطأ لمهمة'), 'الإسنادُ يُدقَّق (§31)');

        // النقرة الثانية: لا مهمةَ ثانية ولا قيدَ إسنادٍ ثانياً
        $this->actingAs($this->owner)->post('/admin/errors/' . $e->id . '/task', ['assignee_id' => $this->employee->id]);
        $this->assertSame(1, Task::where('title', 'LIKE', '%إصلاح%')->count(), 'مهمةٌ واحدة لا تتكرر');
        $this->assertSame(1, $this->audits('إسناد خطأ لمهمة'));
    }

    /** الكتمُ يختم مدّته ويُدقَّق — واحترامُه في الإشعار حزمةُ 3.5 */
    public function test_mute_stamps_until_and_audits(): void
    {
        $this->seedCore();
        $e = $this->err();

        $this->actingAs($this->owner)
            ->post('/admin/errors/' . $e->id . '/status', ['to' => 'جديد', 'mute_days' => 7])
            ->assertRedirect();

        $f = $e->fresh();
        $this->assertNotNull($f->muted_until, 'الكتم يختم أمده');
        $this->assertTrue(now()->addDays(6)->lt(\Illuminate\Support\Carbon::parse($f->muted_until)), 'الأمدُ سبعةُ أيام');
        $this->assertSame(1, $this->audits('كتم تنبيه خطأ'), 'الكتمُ إسكاتُ شاهدٍ — يُدقَّق');
        $this->assertSame(0, $this->audits('تغيير حالة خطأ'), 'الحالةُ لم تتغيّر فلا قيدَ انتقالٍ زائف');
    }

    /** الصيغُ الجديدة مصنَّفةٌ في كتالوج الأحداث الأمنية (§31) */
    public function test_new_lifecycle_actions_classify_as_security_events(): void
    {
        $this->assertSame('ERROR_STATE_CHANGED', SecurityEvents::codeFor('تغيير حالة خطأ'));
        $this->assertSame('ERROR_IGNORED', SecurityEvents::codeFor('تجاهل خطأ'));
        $this->assertSame('ERROR_MUTED', SecurityEvents::codeFor('كتم تنبيه خطأ'));
        $this->assertSame('ERROR_ASSIGNED', SecurityEvents::codeFor('إسناد خطأ لمهمة'));
    }

    /** الحالاتُ الموروثة الثلاث تبقى كما خُزّنت وتُعرَض عبر خريطة IssueState بلا إعادة كتابة */
    public function test_legacy_states_display_without_rewrite(): void
    {
        $this->seedCore();
        $this->err(['status' => 'قيد المعالجة', 'message' => 'خطأ موروث بحالة قديمة']);

        $this->actingAs($this->owner)->get('/admin/errors?st=in_progress')
            ->assertOk()->assertSee('خطأ موروث بحالة قديمة');
        $this->assertSame('قيد المعالجة', IssueState::label('in_progress'), 'الخريطة واحدة (الطور ١) لا مفردات سادسة');
    }
}
