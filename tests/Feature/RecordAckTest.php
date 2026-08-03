<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Decision;
use App\Models\Meeting;
use App\Support\Acks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ثلاثة أشياء كانت تُسجَّل ولا يُقرّ بها أحد:
 *
 * — **المحضر** يُكتب ويُغلق دون موافقة من حضر، فإذا اختُلف بعد شهرٍ في قرارٍ
 *   قيل «لم يُتّفق على هذا» ولا شيء يردّ.
 * — **العهدة** تُسلَّم بجدولٍ فيه اسم الحائز بلا توقيعٍ منه؛ فإذا ضاع الجهاز
 *   فلا إثبات تسليم.
 * — **القرار** يُسنَد لمنفّذٍ لم يقل يوماً إنه علم به.
 *
 * السكّة الموحّدة `(module, record_id)` تعطي الثلاثة إقراراً موثَّقاً بدليله:
 * وقتٌ وعنوانٌ وجهاز — ونسخةُ السجل، فمحضرٌ عُدِّل بعد اعتماده يُطلب اعتماده ثانيةً.
 */
class RecordAckTest extends TestCase
{
    protected function meeting(array $parts): Meeting
    {
        return Meeting::create(['title' => 'اجتماع الربع', 'dt' => now()->toDateTimeString(),
            'parts' => $parts, 'status' => 'انعقد']);
    }

    public function test_a_participant_can_acknowledge_the_minutes_with_evidence(): void
    {
        $this->seedCore();
        $m = $this->meeting([$this->owner->id, $this->employee->id]);

        $this->actingAs($this->employee)->post("/acks/meetings/{$m->id}", ['note' => 'مع تحفّظ على البند ٣'])
            ->assertRedirect();

        $row = DB::table('record_acks')->where('record_id', $m->id)->first();
        $this->assertNotNull($row, 'لم يُسجَّل الإقرار');
        $this->assertSame($this->employee->id, $row->user_id);
        $this->assertNotNull($row->ack_at);
        $this->assertNotNull($row->ip, 'إقرارٌ بلا دليل ليس إثباتاً');
        $this->assertSame('مع تحفّظ على البند ٣', $row->note);

        // ويترك أثراً في التدقيق
        $this->assertTrue(DB::table('audits')->where('action', 'اعتماد المحضر')->exists());
    }

    /** ولا يُقرّ أحدٌ نيابةً عن غيره — الإقرار شهادةٌ شخصية */
    public function test_a_non_participant_cannot_acknowledge(): void
    {
        $this->seedCore();
        $m = $this->meeting([$this->owner->id]);

        $this->actingAs($this->employee)->post("/acks/meetings/{$m->id}")->assertForbidden();
        $this->assertSame(0, DB::table('record_acks')->count());
    }

    /** محضرٌ عُدِّل بعد اعتماده يحتاج اعتماداً جديداً */
    public function test_editing_the_record_invalidates_the_previous_acknowledgement(): void
    {
        $this->seedCore();
        $m = $this->meeting([$this->employee->id]);

        $this->actingAs($this->employee)->post("/acks/meetings/{$m->id}");
        $this->assertFalse(Acks::pendingFor('meetings', $m->fresh(), $this->employee->id));

        $m->forceFill(['version' => $m->version + 1, 'notes' => 'نصٌّ مختلف'])->save();

        $this->assertTrue(Acks::pendingFor('meetings', $m->fresh(), $this->employee->id),
            'نصّ المحضر تغيّر بعد الاعتماد وبقي الاعتماد القديم يُغطّيه');
        $st = Acks::state('meetings', $m->fresh());
        $this->assertTrue($st['people'][0]['stale'], 'الإقرار القديم لم يُعلَّم متقادماً');
    }

    /** أما العهدة فتصحيح وصفها لا يُلغي استلامها */
    public function test_a_custody_acknowledgement_survives_an_edit(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'حاسوب محمول', 'type' => 'جهاز',
            'holder_id' => $this->employee->id, 'status' => 'مستلم']);

        $this->actingAs($this->employee)->post("/acks/assets/{$a->id}")->assertRedirect();
        $a->forceFill(['version' => $a->version + 1, 'notes' => 'تصحيح الرقم التسلسلي'])->save();

        $this->assertFalse(Acks::pendingFor('assets', $a->fresh(), $this->employee->id),
            'تصحيحُ وصف الأصل ألغى إقرار استلامه');
    }

    /** الإقرار المعلّق يصل الصندوق الموحّد */
    public function test_a_pending_acknowledgement_reaches_the_unified_inbox(): void
    {
        $this->seedCore();
        Decision::create(['title' => 'اعتماد الميزانية', 'exec_id' => $this->employee->id,
            'status' => 'لم يبدأ', 'date' => now()->toDateString()]);

        $this->actingAs($this->employee);
        $item = collect(\App\Support\Inbox::items($this->employee))->firstWhere('kind', 'ack');

        $this->assertNotNull($item, 'قرارٌ ينتظر إقراري ولم يصل صندوقي');
        $this->assertSame('اعتماد الميزانية', $item['title']);
        $this->assertSame(1, $item['bucket'], 'الإقرار المعلّق ليس «لاحقاً»');
    }

    /** التذكير لمن يملك التعديل، ويصل من لم يُقرّ وحده */
    public function test_reminding_notifies_only_those_who_have_not_acknowledged(): void
    {
        $this->seedCore();
        $m = $this->meeting([$this->owner->id, $this->employee->id, $this->viewer->id]);
        $this->actingAs($this->viewer)->post("/acks/meetings/{$m->id}");

        $this->actingAs($this->owner)->post("/acks/meetings/{$m->id}/remind")->assertRedirect();

        $to = \App\Models\HubNotification::where('text', 'LIKE', '%ينتظرك اعتماد المحضر%')
            ->pluck('user_id')->all();
        $this->assertContains($this->employee->id, $to);
        $this->assertNotContains($this->viewer->id, $to, 'ذُكِّر من أقرّ أصلاً');
    }

    public function test_reminding_requires_edit_permission(): void
    {
        $this->seedCore();
        $m = $this->meeting([$this->owner->id, $this->viewer->id]);

        $this->actingAs($this->viewer)->post("/acks/meetings/{$m->id}/remind")->assertForbidden();
    }

    /** الشاشة تعرض السكّة للوحدات المسجّلة وحدها */
    public function test_the_panel_appears_for_registered_modules_only(): void
    {
        $this->seedCore();
        $m = $this->meeting([$this->owner->id]);
        $this->actingAs($this->owner)->get("/m/meetings/{$m->id}")->assertOk()
            ->assertSee('اعتماد المحضر');

        $t = \App\Models\Task::create(['title' => 'مهمة عادية', 'status' => 'جديدة',
            'assignee_id' => $this->owner->id]);
        $this->actingAs($this->owner)->get("/m/tasks/{$t->id}")->assertOk()
            ->assertDontSee('اعتماد المحضر');
    }

    /** ووحدةٌ غير مسجَّلة لا مسار إقرارٍ لها أصلاً */
    public function test_an_unregistered_module_has_no_ack_route(): void
    {
        $this->seedCore();
        $t = \App\Models\Task::create(['title' => 'مهمة', 'status' => 'جديدة']);

        $this->actingAs($this->owner)->post("/acks/tasks/{$t->id}")->assertNotFound();
    }

    /** إقرارُ من خرج من قائمة الحضور يُحفظ ولا يُمحى بتغيير القائمة */
    public function test_an_ack_survives_the_person_leaving_the_list(): void
    {
        $this->seedCore();
        $m = $this->meeting([$this->owner->id, $this->employee->id]);
        $this->actingAs($this->employee)->post("/acks/meetings/{$m->id}");

        $m->forceFill(['parts' => [$this->owner->id]])->save();

        $st = Acks::state('meetings', $m->fresh());
        $this->assertSame(1, $st['extra'], 'أثرُ إقرارٍ سابق ضاع بتعديل قائمة الحضور');
        $this->assertSame(1, DB::table('record_acks')->count());
    }
}
