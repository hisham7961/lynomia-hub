<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyAck;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المرحلة ٤ — الملف الخليجي والتعيين ولوحة الامتثال:
 *  1) حقول الملف الوظيفي التي يحتاجها العمل في الكويت/الخليج كانت غائبة.
 *  2) «تم التعيين» كانت تنشئ مهمة تجهيزٍ فقط ويُعاد إدخال بيانات الموظف يدوياً.
 *  3) تعليق هجرة الإقرارات يعِد بأن تحديث النسخة يُبطل الإقرار القديم — والحالة
 *     «منتهية بتحديث النسخة» لم يكن يكتبها شيء أبداً.
 */
class HrProfileRound6Test extends TestCase
{
    public function test_gulf_profile_fields_are_saved(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/m/hr', [
            'name' => 'موظف الملف', 'status' => 'نشط',
            'email' => 'emp@corp.local', 'phone' => '+96550000000', 'nationality' => 'كويتي',
            'civilId' => '290010112345', 'iban' => 'KW81CBKU0000000000001234560101',
            'emergency' => 'أبو الموظف — 99887766',
        ])->assertSessionHasNoErrors();

        $e = Employee::where('name', 'موظف الملف')->firstOrFail();
        $this->assertSame('emp@corp.local', $e->email);
        $this->assertSame('كويتي', $e->nationality);
        $this->assertSame('290010112345', $e->civil_id);
        $this->assertStringContainsString('KW81', (string) $e->iban);
        $this->assertStringContainsString('99887766', (string) $e->emergency);
    }

    public function test_hiring_candidate_creates_linked_employee_once(): void
    {
        $this->seedCore();
        $c = Candidate::create(['name' => 'مرشحة ناجحة', 'job' => 'مطورة واجهات', 'dept' => 'التقنية',
            'email' => 'cand@test.local', 'phone' => '99001122', 'expect' => '900', 'stage' => 'عرض وظيفي']);

        $this->actingAs($this->owner)->post('/candidates/' . $c->id . '/hire');

        $emp = Employee::where('name', 'مرشحة ناجحة')->first();
        $this->assertNotNull($emp, 'التعيين ينشئ الملف الوظيفي بلا إعادة إدخال');
        $this->assertSame('مطورة واجهات', $emp->title);
        $this->assertSame('cand@test.local', $emp->email);
        $this->assertEquals(900.0, (float) $emp->salary);
        $this->assertNotNull($emp->hired);

        $c->refresh();
        $this->assertSame('تم التعيين', $c->stage);
        $this->assertSame($emp->id, ($c->meta['employee_id'] ?? null), 'الطرفان مربوطان');

        // التعيين المتكرر لا يُنشئ ملفاً ثانياً
        $this->actingAs($this->owner)->post('/candidates/' . $c->id . '/hire');
        $this->assertSame(1, Employee::where('name', 'مرشحة ناجحة')->count());
    }

    public function test_policy_version_bump_expires_acks_and_requests_new_ones(): void
    {
        $this->seedCore();
        $p = Policy::create(['title' => 'سياسة الاستخدام المقبول', 'ver' => '1.0',
            'body' => 'النص', 'ack_required' => true]);
        PolicyAck::create(['title' => 'إقرار قديم', 'policy_id' => $p->id,
            'user_id' => $this->employee->id, 'ver' => '1.0', 'status' => 'مُقرّة', 'ack_at' => now()]);

        $p->update(['ver' => '2.0']);

        $this->assertSame('منتهية بتحديث النسخة',
            PolicyAck::where('ver', '1.0')->first()->status,
            'تحديث النسخة يُبطل إقرار السابقة — وعدُ الهجرة يُوفى أخيراً');

        $fresh = PolicyAck::where('ver', '2.0')->where('user_id', $this->employee->id)->first();
        $this->assertNotNull($fresh, 'ويُعاد طلب الإقرار على النسخة الجديدة');
        $this->assertSame('بانتظار الإقرار', $fresh->status);
        $this->assertTrue(DB::table('notifications_hub')->where('user_id', $this->employee->id)
            ->where('kind', 'policy')->exists(), 'وصاحبه يُشعَر');
    }

    public function test_policy_page_shows_compliance_dashboard(): void
    {
        $this->seedCore();
        $p = Policy::create(['title' => 'سياسة الأمن', 'ver' => '3.0', 'body' => 'النص', 'ack_required' => true]);
        PolicyAck::create(['title' => 'أقرّ', 'policy_id' => $p->id, 'user_id' => $this->owner->id,
            'ver' => '3.0', 'status' => 'مُقرّة', 'ack_at' => now()]);
        PolicyAck::create(['title' => 'لم يُقرّ', 'policy_id' => $p->id, 'user_id' => $this->employee->id,
            'ver' => '3.0', 'status' => 'بانتظار الإقرار']);

        $this->actingAs($this->owner)->get('/m/policies/' . $p->id)
            ->assertOk()->assertSee('امتثال النسخة الحالية')->assertSee('50٪')->assertSee('موظفة');
    }
}
