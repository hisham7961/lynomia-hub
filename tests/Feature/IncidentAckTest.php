<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Support\Acks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * (WP-6.1) إقرارُ الحادثة عبر سكّة `record_acks` القائمة — بلا مخطّطٍ جديد:
 *
 * — القائد (`lead_id`) هو المُقِرّ الوحيد، وإقرارُه مؤرَّخٌ بدليله ومدقَّق.
 * — **فخُّ critic #37 مُغلق**: الحوادثُ التي يفتحها `hub_security_incident`
 *   آليّاً كانت بلا قائد، و`Acks::targets()` يعيد `[]` فيستحيل الإقرارُ في
 *   الحالة الوحيدة التي تحتاجه — فالقائدُ يُعبَّأ آليّاً بأوّل مالك.
 * — و`reack = false`: كلُّ دقيقةِ توثيقٍ أثناء الاستجابة ترفع النسخة، ولو
 *   أبطلها التعديلُ لَطُلب الإقرارُ من جديد مع كل سطرٍ يُكتب.
 */
class IncidentAckTest extends TestCase
{
    protected function incident(array $extra = []): Incident
    {
        return Incident::create(array_merge([
            'title' => 'انقطاع بوابة الدفع',
            'severity' => 'حرج',
            'status' => 'مفتوح',
        ], $extra));
    }

    public function test_incidents_are_registered_in_the_ack_registry(): void
    {
        $def = config('hub_acks.incidents');
        $this->assertNotNull($def, 'وحدةُ الحوادث غيرُ مسجَّلة في سجلّ الإقرار');
        $this->assertSame('lead_id', $def['who']['col']);
        $this->assertSame('one', $def['who']['type']);
        $this->assertFalse($def['reack'],
            'التحريرُ أثناء الاستجابة يرفع النسخة — reack=true يجعل كل سطرِ توثيقٍ يُبطل الإقرار');
    }

    public function test_the_lead_acknowledges_with_evidence_and_audit_trail(): void
    {
        $this->seedCore();
        $i = $this->incident(['lead_id' => $this->employee->id]);

        $this->actingAs($this->employee)
            ->post("/acks/incidents/{$i->id}", ['note' => 'تسلّمت القيادة'])
            ->assertRedirect();

        $row = DB::table('record_acks')->where('module', 'incidents')->where('record_id', $i->id)->first();
        $this->assertNotNull($row, 'لم يُسجَّل إقرارُ القائد');
        $this->assertSame($this->employee->id, $row->user_id);
        $this->assertNotNull($row->ip, 'إقرارٌ بلا دليلٍ ليس إثباتاً');

        // والأثرُ في التدقيق باسم الإقرار المعرَّف في السجلّ
        $this->assertTrue(DB::table('audits')
            ->where('action', (string) config('hub_acks.incidents.label'))->exists(),
            'إقرارُ الحادثة لم يترك أثراً في التدقيق');
    }

    /** الإقرارُ شهادةُ القائد وحده — لا يُقرّ عنه غيرُه ولو كان المالك */
    public function test_only_the_lead_may_acknowledge(): void
    {
        $this->seedCore();
        $i = $this->incident(['lead_id' => $this->employee->id]);

        $this->actingAs($this->owner)->post("/acks/incidents/{$i->id}")->assertForbidden();
        $this->assertSame(0, DB::table('record_acks')->count());
    }

    /** حادثةٌ بلا قائدٍ لا «تُقرّ» بصمت — الخادم يرفض والرأسُ يقولها */
    public function test_an_incident_without_a_lead_cannot_be_acknowledged(): void
    {
        $this->seedCore();
        $i = $this->incident();

        $this->assertSame([], Acks::targets('incidents', $i));
        $this->actingAs($this->owner)->post("/acks/incidents/{$i->id}")->assertForbidden();
    }

    /** فخُّ critic #37: الحادثةُ الآليّة تُفتح بقائدٍ افتراضيّ فيبقى الإقرارُ بلوغاً ممكناً */
    public function test_an_auto_opened_incident_gets_a_default_lead_so_ack_is_reachable(): void
    {
        $this->seedCore();

        $i = hub_security_incident('فشل فحص سلسلة التدقيق — عبثٌ محتمل', 'حرج');
        $this->assertSame($this->owner->id, $i->lead_id,
            'حادثةٌ آليّة بلا قائد — Acks::targets() فارغ فيستحيل الإقرار حيث يلزم');
        $this->assertNotSame([], Acks::targets('incidents', $i));

        $this->actingAs($this->owner)->post("/acks/incidents/{$i->id}")->assertRedirect();
        $this->assertSame(1, DB::table('record_acks')->where('record_id', $i->id)->count());
    }

    /** توثيقُ الاستجابة يرفع النسخة ولا يُبطل الإقرار (reack=false) */
    public function test_editing_during_response_does_not_invalidate_the_ack(): void
    {
        $this->seedCore();
        $i = $this->incident(['lead_id' => $this->employee->id]);

        $this->actingAs($this->employee)->post("/acks/incidents/{$i->id}");
        $this->assertFalse(Acks::pendingFor('incidents', $i->fresh(), $this->employee->id));

        $i->forceFill(['version' => $i->version + 1, 'steps' => 'عُزل الخادم المصاب'])->save();

        $this->assertFalse(Acks::pendingFor('incidents', $i->fresh(), $this->employee->id),
            'سطرُ توثيقٍ واحد أبطل إقرار القائد — فيُطلب الإقرار مع كل تحديث');
    }
}
