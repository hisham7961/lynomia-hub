<?php

namespace Tests\Feature;

use App\Models\HubNotification;
use App\Models\Meeting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **دليلٌ يُمحى، وطابورٌ يُلتفّ عليه، وتذكيرٌ بلا سقف.**
 *
 *   ١) **الإقرارُ الأول يُمحى بإقرارٍ ثانٍ**: `updateOrInsert` على المفتاح نفسه
 *      يكتب فوق الصفّ. فمن أقرّ في يناير من مكتبه ثم ضغط الزر ثانيةً في مارس
 *      من هاتفه، صار الدليلُ يقول «مارس، من هذا الجهاز» — و**وقتُ العلم الأول
 *      ضاع**. وهو بالضبط ما يُحتجّ به: متى عَلِم لا متى أعاد الضغط.
 *
 *   ٢) **أزرارُ المسار تلتفّ على طابور الموافقات**: تعديلُ أمر الشراء من نموذج
 *      السجل يُصفّ طلب موافقة، وزرُّ «أرسل للمورد» في الشاشة نفسها **يكتب
 *      الحالة مباشرة**. القاعدةُ التي يُلتَفُّ عليها بزرٍّ مجاورٍ ليست قاعدة.
 *
 *   ٣) **التذكيرُ بلا سقفٍ ولا مهلة**: كل ضغطةٍ تُطلق إشعاراً لكل من لم يُقرّ،
 *      بلا حدٍّ ولا تبريد — فسجلٌّ بمئتَي مُخاطَب يُغرِق صناديقهم بضغطاتٍ متتالية،
 *      والإشعارُ الذي يتكرّر بلا معنى يُدرّب الناس على تجاهل كل الإشعارات.
 */
class EvidenceAndBypassTest extends TestCase
{
    /* ── ١) دليل الإقرار ── */

    private function meeting(): Meeting
    {
        return Meeting::create(['title' => 'اجتماع', 'dt' => now()->toDateTimeString(),
            'parts' => [$this->owner->id], 'status' => 'انعقد']);
    }

    public function test_a_second_acknowledgement_never_erases_the_first(): void
    {
        $this->seedCore();
        $m = $this->meeting();

        $this->actingAs($this->owner)->post("/acks/meetings/{$m->id}");
        $first = DB::table('record_acks')->where('record_id', $m->id)->value('ack_at');
        $this->assertNotNull($first, 'لم يُسجَّل الإقرار أصلاً');

        $this->travel(40)->days();
        $this->actingAs($this->owner)->post("/acks/meetings/{$m->id}");

        $this->assertSame($first, DB::table('record_acks')->where('record_id', $m->id)->value('ack_at'),
            'مُحي وقتُ العلم الأول بضغطةٍ ثانية — وهو بالضبط ما يُحتجّ به');
        $this->assertSame(1, DB::table('record_acks')->where('record_id', $m->id)->count());
    }

    /** ونسخةٌ جديدة تُسجَّل إقراراً مستقلاً — التاريخان يبقيان معاً */
    public function test_a_new_version_keeps_both_acknowledgements(): void
    {
        $this->seedCore();
        $m = $this->meeting();

        $this->actingAs($this->owner)->post("/acks/meetings/{$m->id}");
        $m->update(['title' => 'اجتماعٌ عُدِّل']);
        $this->actingAs($this->owner)->post("/acks/meetings/{$m->fresh()->id}");

        $this->assertSame(2, DB::table('record_acks')->where('record_id', $m->id)->count(),
            'نسختان من السجل وإقرارٌ واحد — أيُّهما أقرّ عليه؟');
    }

    /* ── ٢) الالتفاف على الطابور ── */

    public function test_a_purchase_workflow_button_respects_the_approval_queue(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'purchases:e');
        $p = \App\Models\Purchase::create(['doc_no' => 'PO-1', 'title' => 'أمر شراء', 'status' => 'مسودة']);

        $this->actingAs($this->employee)->post("/purchase/{$p->id}/act", ['do' => 'submit']);

        $this->assertSame('مسودة', $p->fresh()->status,
            'تعديلُه يمرّ بالموافقات من النموذج، ويُكتب مباشرةً من زرِّ المسار');
    }

    public function test_a_quote_workflow_button_respects_the_approval_queue(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'quotes:e');
        $q = \App\Models\Quote::create(['doc_no' => 'Q-1', 'title' => 'عرض سعر', 'total' => 100, 'status' => 'مسودة']);

        $this->actingAs($this->employee)->post("/quote/{$q->id}/act", ['do' => 'send']);

        $this->assertSame('مسودة', $q->fresh()->status);
    }

    /** ومن لا وحدتُه تحت قاعدةِ موافقةٍ يمضي كما كان — الإصلاح لا يكسر ما يعمل */
    public function test_the_workflow_still_runs_with_no_approval_rule(): void
    {
        $this->seedCore();
        $p = \App\Models\Purchase::create(['doc_no' => 'PO-1', 'title' => 'أمر شراء', 'status' => 'مسودة']);

        $this->actingAs($this->owner)->post("/purchase/{$p->id}/act", ['do' => 'submit']);

        $this->assertSame('بانتظار الاعتماد', $p->fresh()->status);
    }

    /* ── ٣) سقف التذكير ── */

    public function test_reminding_twice_in_a_row_does_not_double_the_noise(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'مُخاطَب', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $ids = [];
        foreach (range(1, 3) as $i) {
            $ids[] = User::create(['name' => "مدعو {$i}", 'email' => "p{$i}@test.local",
                'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
                'password_changed_at' => now()])->id;
        }
        $m = Meeting::create(['title' => 'اجتماع', 'dt' => now()->toDateTimeString(),
            'parts' => $ids, 'status' => 'انعقد']);

        $this->actingAs($this->owner)->post("/acks/meetings/{$m->id}/remind");
        $after1 = HubNotification::whereIn('user_id', $ids)->count();

        $this->actingAs($this->owner)->post("/acks/meetings/{$m->id}/remind");
        $after2 = HubNotification::whereIn('user_id', $ids)->count();

        $this->assertSame($after1, $after2,
            'ضغطتان متتاليتان ضاعفتا الإشعارات — والتذكيرُ الذي يتكرّر بلا معنى يُبطل كل الإشعارات');
        $this->assertSame(3, $after1, 'لم يصل التذكير الأول لمن يستحقه');
    }

    /** والتذكيرُ بعد المهلة يعمل — السقف تهدئةٌ لا إسكات */
    public function test_reminding_works_again_after_the_cooldown(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'مُخاطَب', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $u = User::create(['name' => 'مدعو', 'email' => 'one@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $m = Meeting::create(['title' => 'اجتماع', 'dt' => now()->toDateTimeString(),
            'parts' => [$u->id], 'status' => 'انعقد']);

        $this->actingAs($this->owner)->post("/acks/meetings/{$m->id}/remind");
        $this->travel(2)->days();
        $this->actingAs($this->owner)->post("/acks/meetings/{$m->id}/remind");

        $this->assertSame(2, HubNotification::where('user_id', $u->id)->count(),
            'مضى يومان ولم يُسمح بتذكيرٍ ثانٍ — السقفُ صار إسكاتاً');
    }

    /** ولا يُفرَّغ سجلٌّ بمئات المُخاطَبين في ضغطةٍ واحدة بلا حدّ */
    public function test_the_reminder_fan_out_is_capped(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Web/AckController.php'));

        $this->assertMatchesRegularExpression('/REMIND_CAP|->take\(|array_slice/', $src,
            'التذكير يُفرِّغ على كل مُخاطَبٍ بلا سقف — ضغطةٌ واحدة تكتب مئات الإشعارات');
    }
}
