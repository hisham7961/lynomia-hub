<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Idea;
use App\Models\PolicyAck;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * جولة تدقيق ٦ — أخطاء مفردات وحالات مؤكدة كانت تعطّل مزايا بصمت:
 *  1) «معتمدة» في الكود مقابل «معتمد» في السجل — بطاقة «غائبون اليوم» كانت فارغة أبداً
 *     وخصم الإجازات من الطاقة معطلاً (اختبار القدرات نفسه صُحّح فيغطي الشق الثاني).
 *  2) ترقية الفكرة كانت تكتب «قيد التخطيط» (خارج خيارات المشاريع) وتُسقط الشركة.
 *  3) فاتورة العرض كانت تحمل اسم العميل نصاً بلا مرجعه client_id.
 *  4) إقرار السياسة الموقَّع إلكترونياً كان يولد بلا user_id رغم أنه حقل مطلوب.
 */
class WordingBugsRound6Test extends TestCase
{
    public function test_morning_absent_today_card_shows_approved_leave(): void
    {
        $this->seedCore();
        $emp = Employee::create(['name' => 'غائب اليوم فعلاً', 'status' => 'نشط']);
        DB::table('leave_requests')->insert(['id' => (string) Str::uuid(), 'emp_id' => $emp->id,
            'type' => 'إجازة سنوية', 'status' => 'معتمد',
            'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner)->get('/morning')
            ->assertOk()->assertSee('غائبون اليوم')->assertSee('غائب اليوم فعلاً');
    }

    public function test_promoted_idea_lands_in_declared_project_status_with_company(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة الفكرة', 'status' => 'نشطة']);
        $this->employee->update(['companies' => [$co->id]]);
        $idea = Idea::create(['title' => 'فكرة للترقية', 'status' => 'قيد التقييم',
            'impact' => 3, 'confidence' => 3, 'ease' => 3]);

        // معزولٌ على شركة يرقّي فكرة — كان المشروع يولد «قيد التخطيط» وبلا شركة فيختفي
        $this->actingAs($this->employee)->post('/ideas/' . $idea->id . '/promote');

        $p = \App\Models\Project::where('name', 'فكرة للترقية')->first();
        $this->assertNotNull($p, 'لم يُنشأ المشروع');

        $options = collect(hub_mod('projects')['fields'])->firstWhere('key', 'status')['options'];
        $this->assertContains($p->status, $options,
            'حالة المشروع المرقّى يجب أن تكون من الخيارات المعلنة — وإلا اختفى من الكانبان والفلاتر');
        $this->assertSame((string) $co->id, (string) $p->company_id,
            'المشروع المرقّى يُنسب لشركة صاحبه — وإلا اختفى عن المعزول فور إنشائه');
    }

    public function test_quote_invoice_carries_client_reference(): void
    {
        $this->seedCore();
        $client = Client::create(['name' => 'عميل المرجع', 'stage' => 'عميل حالي']);
        $q = Quote::create(['doc_no' => 'Q-REF-1', 'title' => 'عرض مرجعي', 'status' => 'مقبول',
            'client_id' => $client->id, 'total' => 500, 'currency' => 'د.ك']);

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'invoice']);

        $inv = \App\Models\FinDocument::where('doc_no', 'INV-Q-REF-1')->first();
        $this->assertNotNull($inv, 'لم تُنشأ الفاتورة');
        $this->assertSame((string) $client->id, (string) $inv->client_id,
            'الفاتورة المولّدة من عرضٍ يجب أن تحمل مرجع العميل لا اسمه النصي فقط');
    }

    public function test_esigned_policy_ack_resolves_user_from_signer_email(): void
    {
        $this->seedCore();
        $pol = \App\Models\Policy::create(['title' => 'سياسة الإقرار المنسوب', 'ver' => '1.0', 'body' => 'نص']);

        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'توقيع سياسة', 'free_body' => 'نص الوثيقة.',
            'pass' => 'p1234', 'link_module' => 'policies', 'link_id' => $pol->id,
        ]);
        $req = \App\Models\SignRequest::where('link_module', 'policies')->firstOrFail();

        // موقّع الطلب هو الموظفة (بريدها معروف للنظام)
        \App\Models\ContractSigner::where('request_id', $req->id)->update(['email' => $this->employee->email]);

        auth()->logout();
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'p1234']);
        $this->post("/sign/{$req->token}", ['signer_name' => 'موظفة',
            'signature' => 'data:image/png;base64,' . base64_encode(str_repeat('توقيع', 40))])->assertOk();

        $ack = PolicyAck::first();
        $this->assertNotNull($ack, 'لم يتولد سجل الإقرار');
        $this->assertSame((string) $this->employee->id, (string) $ack->user_id,
            'سجل الامتثال بلا صاحب لا يُدقَّق — user_id يُحل من بريد الموقّع');
    }
}
