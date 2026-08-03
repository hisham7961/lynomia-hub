<?php

namespace Tests\Feature;

use App\Models\KbArticle;
use App\Models\Policy;
use App\Models\PolicyAck;
use App\Models\User;
use Tests\TestCase;

/**
 * السياسات كانت **نصّاً يُحفظ ثم يُنسى**: لا إبلاغ للموظف، ولا سبيل لمعرفة
 * من قرأ ومن لم يقرأ، ولا شيء يحدث حين تُحدَّث النسخة — فيبقى الجميع مُقِرّين
 * بنسخةٍ ماتت. وقاعدة المعرفة كذلك: «قراءة إلزامية» مربّعٌ بلا أثر.
 */
class PolicyAckCenterTest extends TestCase
{
    protected function pol(array $x = []): Policy
    {
        return Policy::create(array_merge([
            'title' => 'سياسة أمن المعلومات', 'ver' => '1.0', 'status' => 'سارية',
            'body' => 'نص السياسة الكامل.', 'ack_required' => true,
            'eff_date' => now()->toDateString(),
        ], $x));
    }

    public function test_publishing_notifies_everyone_who_must_acknowledge(): void
    {
        $this->seedCore();
        $p = $this->pol();

        $n = hub_ack_announce('policies', $p->id);
        $this->assertGreaterThanOrEqual(3, $n, 'كل من يلزمه الإقرار يُبلَّغ');

        $this->assertDatabaseHas('notifications_hub', [
            'user_id' => $this->employee->id, 'module' => 'policies', 'record_id' => $p->id,
        ]);
        $this->assertSame($n, PolicyAck::where('record_id', $p->id)->count(),
            'يُفتح لكل مُخاطَبٍ إقرارٌ معلّق — لا نبحث عمّن لم يُقِر بالتخمين');
    }

    public function test_the_board_shows_who_signed_and_who_did_not(): void
    {
        $this->seedCore();
        $p = $this->pol();
        hub_ack_announce('policies', $p->id);

        PolicyAck::where('record_id', $p->id)->where('user_id', $this->employee->id)
            ->update(['status' => 'مُقرّة', 'ack_at' => now(), 'ver' => '1.0']);

        $html = $this->actingAs($this->owner)->get('/policies')->assertOk()->getContent();
        $this->assertStringContainsString('سياسة أمن المعلومات', $html);
        $this->assertStringContainsString('موظفة', $html, 'من أقرّ يُسمّى');
        $this->assertStringContainsString('لم يُقِر', $html, 'ومن لم يُقِر يُسمّى أيضاً');

        $st = hub_ack_state('policies', $p->id);
        $this->assertSame(1, $st['done']);
        $this->assertGreaterThan(0, $st['pending']);
        $this->assertLessThan(100, $st['pct']);
    }

    public function test_a_new_version_expires_old_acknowledgements_and_re_notifies(): void
    {
        $this->seedCore();
        $p = $this->pol();
        hub_ack_announce('policies', $p->id);
        PolicyAck::where('record_id', $p->id)->update(['status' => 'مُقرّة', 'ack_at' => now(), 'ver' => '1.0']);
        $this->assertSame(100, hub_ack_state('policies', $p->id)['pct']);

        // تحديث النسخة من الواجهة
        $this->actingAs($this->owner)->put('/m/policies/' . $p->id, [
            'title' => 'سياسة أمن المعلومات', 'ver' => '2.0', 'status' => 'سارية',
            'body' => 'نص السياسة بعد التعديل.', 'ackRequired' => 1,
            'effDate' => now()->toDateString(),
        ])->assertRedirect();

        $st = hub_ack_state('policies', $p->id);
        $this->assertSame(0, $st['done'], 'الإقرارات بالنسخة القديمة سقطت');
        $this->assertSame(0, $st['pct']);
        $this->assertGreaterThan(0, PolicyAck::where('record_id', $p->id)
            ->where('status', 'منتهية بتحديث النسخة')->count(), 'ويُوسم سقوطها لا يُمحى');

        $this->assertDatabaseHas('notifications_hub', [
            'user_id' => $this->employee->id, 'module' => 'policies', 'record_id' => $p->id, 'read' => 0,
        ]);
    }

    public function test_an_employee_acknowledges_from_their_portal(): void
    {
        $this->seedCore();
        $p = $this->pol();
        hub_ack_announce('policies', $p->id);

        $this->actingAs($this->employee)->post('/policies/' . $p->id . '/ack')->assertRedirect();

        $ack = PolicyAck::where('record_id', $p->id)->where('user_id', $this->employee->id)->firstOrFail();
        $this->assertSame('مُقرّة', $ack->status);
        $this->assertSame('1.0', $ack->ver, 'الإقرار مقرونٌ بالنسخة لا مطلقاً');
        $this->assertNotNull($ack->ack_at);
        $this->assertNotEmpty($ack->ip, 'الدليل يُحفظ: عنوان وجهاز ووقت');
    }

    public function test_knowledge_base_must_read_articles_use_the_same_loop(): void
    {
        $this->seedCore();
        $a = KbArticle::create(['title' => 'دليل التعامل مع العملاء', 'ver' => '1.0',
                                'must_read' => true, 'status' => 'منشور']);

        hub_ack_announce('kb', $a->id);
        $st = hub_ack_state('kb', $a->id);
        $this->assertGreaterThan(0, $st['pending'], '«قراءة إلزامية» كان مربّعاً بلا أثر');

        $this->actingAs($this->employee)->post('/kb/' . $a->id . '/ack')->assertRedirect();
        $this->assertSame(1, hub_ack_state('kb', $a->id)['done']);
    }

    public function test_policy_targets_only_its_project_or_company_when_scoped(): void
    {
        $this->seedCore();
        $prj = \App\Models\Project::create(['name' => 'مشروع مقيّد', 'status' => 'قيد التنفيذ']);
        $out = User::create(['name' => 'عضو المشروع', 'email' => 'out@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $prj->forceFill(['members' => [$out->id]])->save();

        $p = $this->pol(['title' => 'سياسة المشروع', 'project_id' => $prj->id]);
        hub_ack_announce('policies', $p->id);

        $ids = PolicyAck::where('record_id', $p->id)->pluck('user_id')->all();
        $this->assertContains($out->id, $ids, 'من في المشروع مُخاطَب');
    }

    public function test_center_is_permission_guarded(): void
    {
        $this->seedCore();
        $role = $this->viewer->role;
        $m = $role->matrix;
        $m['policies'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->forceFill(['matrix' => $m])->save();

        $this->actingAs($this->viewer->fresh())->get('/policies')->assertForbidden();
    }
}
