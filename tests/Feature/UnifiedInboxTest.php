<?php

namespace Tests\Feature;

use App\Support\Inbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * «ينتظر تصرّفي» كان موزّعاً على أحد عشر جدولاً، وتعرضه البوابة في **صناديق
 * منفصلة** لكلٍّ سقفُه — فلا يُعرف أيُّ الأشياء أعجل: موافقةٌ تأخرت أسبوعاً في
 * صندوق، أم اجتماعٌ بعد شهرٍ في الصندوق المجاور. ولا عدّاد يقول إن ثمّة شيئاً
 * ينتظر أصلاً، فالبوابة لا تُفتح إلا مصادفةً.
 *
 * الصندوق الموحّد: صفٌّ واحد مرتّب (متأخر ← اليوم ← الأسبوع ← لاحقاً ← بلا
 * موعد)، وعدّادٌ في الشريط من المتأخر والمستحق اليوم، وحسمُ الموافقة من مكانها.
 * وكل مصدرٍ خلف صلاحيته: الصندوق يجمع ولا يفتح باباً مغلقاً.
 */
class UnifiedInboxTest extends TestCase
{
    protected function task(string $title, ?string $due, $who = null): void
    {
        \App\Models\Task::create(['title' => $title, 'due' => $due, 'status' => 'جديدة',
            'assignee_id' => ($who ?? $this->owner)->id]);
    }

    public function test_everything_lands_in_one_list_ordered_by_urgency(): void
    {
        $this->seedCore();
        $this->task('مهمة بعد شهر', now()->addDays(30)->toDateString());
        $this->task('مهمة متأخرة', now()->subDays(4)->toDateString());
        $this->task('مهمة اليوم', now()->toDateString());
        $this->task('مهمة بلا موعد', null);
        $this->task('مهمة هذا الأسبوع', now()->addDays(3)->toDateString());

        $this->actingAs($this->owner);
        $titles = collect(Inbox::items($this->owner))->pluck('title')->all();

        $this->assertSame(
            ['مهمة متأخرة', 'مهمة اليوم', 'مهمة هذا الأسبوع', 'مهمة بعد شهر', 'مهمة بلا موعد'],
            $titles,
            'الترتيب لا يتبع الإلحاح — الصندوق الموحّد بلا ترتيبٍ هو الصناديق المنفصلة نفسها'
        );
    }

    /** الحاويات محسوبةٌ صحيحاً — الترويسة تقول «كم متأخر» قبل أي تفصيل */
    public function test_buckets_are_counted(): void
    {
        $this->seedCore();
        $this->task('أ', now()->subDay()->toDateString());
        $this->task('ب', now()->subDays(9)->toDateString());
        $this->task('ج', now()->toDateString());

        $this->actingAs($this->owner);
        $sum = Inbox::summary(Inbox::items($this->owner));

        $this->assertSame(2, $sum[0]['n'] ?? 0, 'عدد المتأخر خاطئ');
        $this->assertSame(1, $sum[1]['n'] ?? 0, 'عدد المستحق اليوم خاطئ');
    }

    /** العدّاد في الشريط: المتأخر والمستحق اليوم وحدهما — لا كل شيء */
    public function test_the_nav_badge_counts_only_what_cannot_wait(): void
    {
        $this->seedCore();
        $this->task('متأخرة', now()->subDay()->toDateString());
        $this->task('اليوم', now()->toDateString());
        $this->task('بعد شهر', now()->addMonth()->toDateString());
        $this->task('بلا موعد', null);

        $this->actingAs($this->owner);
        \Illuminate\Support\Facades\Cache::flush();

        $this->assertSame(2, Inbox::count($this->owner));
    }

    /** لا يظهر في صندوقي ما لا يخصّني */
    public function test_only_my_own_work_appears(): void
    {
        $this->seedCore();
        $this->task('مهمتي', now()->toDateString(), $this->owner);
        $this->task('مهمة غيري', now()->toDateString(), $this->employee);

        $this->actingAs($this->owner);
        $titles = collect(Inbox::items($this->owner))->pluck('title')->all();

        $this->assertContains('مهمتي', $titles);
        $this->assertNotContains('مهمة غيري', $titles, 'الصندوق سرّب عمل زميل');
    }

    /** ولا يُدرَج ما لا يملك صاحب الصندوق رؤية وحدته */
    public function test_a_source_is_skipped_when_its_module_is_not_visible(): void
    {
        $this->seedCore();
        $this->task('مهمتي', now()->toDateString(), $this->employee);

        $role = $this->employee->role;
        $m = $role->matrix;
        $m['tasks'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);

        $this->actingAs($u = $this->employee->fresh());
        $this->assertSame([], collect(Inbox::items($u))->pluck('title')->all(),
            'الصندوق أدرج سجلاً من وحدةٍ لا يراها صاحبه');
    }

    /** سياسة إقرارها إلزامي تنتظر — وإذا أُقرّت بنسختها اختفت، وتعود إذا حُدّثت النسخة */
    public function test_a_policy_awaiting_my_acknowledgement_appears_per_version(): void
    {
        $this->seedCore();
        $pid = (string) Str::uuid();
        DB::table('policies')->insert(['id' => $pid, 'title' => 'سياسة أمن المعلومات',
            'ver' => '1.0', 'ack_required' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner);
        $this->assertContains('سياسة أمن المعلومات', collect(Inbox::items($this->owner))->pluck('title')->all());

        \App\Models\PolicyAck::create(['title' => 'إقرار', 'policy_id' => $pid,
            'user_id' => $this->owner->id, 'ver' => '1.0', 'ack_at' => now()]);

        $this->assertNotContains('سياسة أمن المعلومات', collect(Inbox::items($this->owner))->pluck('title')->all());

        // نسخةٌ جديدة = إقرارٌ جديد: الإقرار القديم لا يُغطّي نصّاً تغيّر
        \App\Models\Policy::where('id', $pid)->first()->update(['ver' => '2.0']);
        $this->assertContains('سياسة أمن المعلومات', collect(Inbox::items($this->owner))->pluck('title')->all(),
            'سياسةٌ حُدّثت ولم تُطلب من أحدٍ إقراراً جديداً — الإقرار القديم يُغطّي نصّاً لم يقرأه');
    }

    /** الشاشة تعرض الصف الموحّد وتتيح التصفية بالنوع */
    public function test_the_portal_renders_the_unified_row_and_filters(): void
    {
        $this->seedCore();
        $this->task('مهمة اليوم', now()->toDateString());
        $pid = (string) Str::uuid();
        DB::table('policies')->insert(['id' => $pid, 'title' => 'سياسة الحضور',
            'ver' => '1', 'ack_required' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $res = $this->actingAs($this->owner)->get('/me');
        $res->assertOk();
        $res->assertSee('ينتظر تصرّفي');
        $res->assertSee('مهمة اليوم');
        $res->assertSee('سياسة الحضور');

        // تصفيةٌ بنوعٍ واحد تُخفي البقية
        $only = $this->actingAs($this->owner)->get('/me?k=policy');
        $only->assertOk();
        $only->assertSee('سياسة الحضور');
        $only->assertDontSee('مهمة اليوم');
    }

    /** الموافقة تُحسم من الصندوق — لمن يملك علم الاعتماد وحده */
    public function test_approvals_carry_inline_actions_only_for_approvers(): void
    {
        $this->seedCore();
        DB::table('approvals')->insert(['id' => (string) Str::uuid(), 'title' => 'صرف مبلغ',
            'type' => 'مالي', 'approver_id' => $this->owner->id, 'status' => 'معلّق',
            'due' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner);
        $item = collect(Inbox::items($this->owner))->firstWhere('kind', 'approval');
        $this->assertNotNull($item, 'موافقةٌ تنتظرني ولم تصل صندوقي');
        $this->assertNotNull($item['act'], 'المالك لا يستطيع الحسم من مكان الانتظار');

        $this->actingAs($this->owner)->get('/me')->assertOk()->assertSee('✔ اعتماد');
    }

    /** ومصدرٌ يسقط لا يُسقط الصندوق كله */
    public function test_a_failing_source_does_not_break_the_inbox(): void
    {
        $this->seedCore();
        $this->task('مهمة باقية', now()->toDateString());
        DB::statement('DROP TABLE IF EXISTS policy_acks');

        $this->actingAs($this->owner);
        $this->assertContains('مهمة باقية', collect(Inbox::items($this->owner))->pluck('title')->all());
        $this->actingAs($this->owner)->get('/me')->assertOk();
    }
}
