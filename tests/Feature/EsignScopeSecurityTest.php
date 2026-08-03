<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\SignRequest;
use App\Models\SignTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CLM م1 (v2.117): إغلاق ثغرات العزل السبع في التوقيع الإلكتروني — اختبارٌ يفشل أولاً لكل ثغرة.
 * sign_requests بلا company_id بعد (يأتي في م2)، فالتنطيق المرحلي عبر العقد/الجهة المربوطة والمنشئ.
 */
class EsignScopeSecurityTest extends TestCase
{
    protected Company $coA;
    protected Company $coB;
    protected Contract $contractB;
    protected SignRequest $reqB;

    /** موظفة معزولة على شركة ألف + طلب توقيع لعقد شركة باء أنشأه المالك */
    protected function seedTwoCompanies(): void
    {
        $this->seedCore();
        $this->coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $this->coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $this->employee->update(['companies' => [$this->coA->id]]);

        $this->contractB = Contract::create([
            'title' => 'عقد باء السري', 'type' => 'عقد عميل',
            'company_id' => $this->coB->id, 'status' => 'مسودة',
        ]);
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'توقيع عقد باء السري', 'free_body' => 'نص عقد شركة باء الخاص جداً',
            'pass' => 'Secret1234', 'contract_id' => $this->contractB->id,
        ]);
        $this->reqB = SignRequest::where('title', 'توقيع عقد باء السري')->firstOrFail();
    }

    /** S1: قائمة المركز كانت تعرض طلبات كل الشركات بلا تنطيق */
    public function test_center_list_hides_foreign_company_requests(): void
    {
        $this->seedTwoCompanies();

        $html = $this->actingAs($this->employee)->get('/esign')->assertOk()->getContent();
        $this->assertStringNotContainsString('توقيع عقد باء السري', $html);

        // المالك يرى الكل كما كان
        $ownerHtml = $this->actingAs($this->owner)->get('/esign')->assertOk()->getContent();
        $this->assertStringContainsString('توقيع عقد باء السري', $ownerHtml);
    }

    /** S2: doc/edit/update كانت findOrFail بلا فحص نطاق — قراءة أي وثيقة وتعديل أي غير موقعة */
    public function test_doc_edit_update_deny_out_of_scope_request(): void
    {
        $this->seedTwoCompanies();

        $this->actingAs($this->employee)->get('/esign/' . $this->reqB->id . '/doc')->assertNotFound();
        $this->actingAs($this->employee)->get('/esign/' . $this->reqB->id . '/edit')->assertNotFound();
        $this->actingAs($this->employee)
            ->put('/esign/' . $this->reqB->id, ['title' => 'مخترق', 'body' => 'نص مخترق'])
            ->assertNotFound();
        $this->assertSame('توقيع عقد باء السري', $this->reqB->fresh()->title);

        // منشئ الطلب (المالك) يصل طبيعياً
        $this->actingAs($this->owner)->get('/esign/' . $this->reqB->id . '/doc')->assertOk();
    }

    /** S3: contract_id كان يُقبل نصاً حراً بلا وجود ولا نطاق — تلويث حالة عقد أجنبي */
    public function test_foreign_contract_id_is_rejected_and_status_untouched(): void
    {
        $this->seedTwoCompanies();
        // عقد مسودة عذراء في شركة باء — هدف محاولة التلويث
        $virginB = Contract::create([
            'title' => 'عقد باء العذراء', 'type' => 'عقد عميل',
            'company_id' => $this->coB->id, 'status' => 'مسودة',
        ]);

        $resp = $this->actingAs($this->employee)->post('/esign', [
            'title' => 'محاولة تلويث', 'free_body' => 'نص',
            'pass' => 'Secret1234', 'contract_id' => $virginB->id,
        ]);
        $resp->assertRedirect();
        $resp->assertSessionHas('err');
        $this->assertSame('مسودة', $virginB->fresh()->status, 'عقد خارج النطاق لا تنقلب حالته');
        $this->assertNull(SignRequest::where('title', 'محاولة تلويث')->first());

        // ومعرف لا وجود له يُرفض أيضاً
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'معرف وهمي', 'free_body' => 'نص', 'pass' => 'Secret1234',
            'contract_id' => (string) Str::uuid(),
        ])->assertSessionHas('err');
        $this->assertNull(SignRequest::where('title', 'معرف وهمي')->first());
    }

    /** S4: التعديل بعد اطلاع الموقّع كان صامتاً — الآن يدوّر الرابط ويُسقط الجلسة القديمة */
    public function test_edit_after_open_rotates_token_and_kills_old_link(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'وثيقة للتدوير', 'free_body' => 'النص الأصلي', 'pass' => 'Secret1234',
        ]);
        $req = SignRequest::where('title', 'وثيقة للتدوير')->firstOrFail();
        $oldToken = $req->token;

        // الموقّع فتح الوثيقة فعلاً
        $this->post("/sign/{$oldToken}/unlock", ['pass' => 'Secret1234'])->assertRedirect();
        $this->get("/sign/{$oldToken}")->assertOk();
        $this->assertNotNull($req->fresh()->opened_at);

        // تعديل النص بعد الاطلاع → token جديد + الرابط القديم يموت + تدقيق
        $this->actingAs($this->owner)
            ->put('/esign/' . $req->id, ['title' => 'وثيقة للتدوير', 'body' => 'نصٌ معدل بعد الاطلاع'])
            ->assertRedirect();
        $fresh = $req->fresh();
        $this->assertNotSame($oldToken, $fresh->token, 'التعديل بعد الاطلاع يجب أن يدوّر الرمز');
        $this->get("/sign/{$oldToken}")->assertNotFound();
        $this->assertTrue(
            DB::table('audits')->where('action', 'like', '%تدوير%')->exists(),
            'تدوير الرابط بعد الاطلاع يجب أن يُدقَّق'
        );

        // التعديل قبل أي اطلاع لا يدوّر (لا داعي)
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'لم تُفتح بعد', 'free_body' => 'نص', 'pass' => 'Secret1234',
        ]);
        $req2 = SignRequest::where('title', 'لم تُفتح بعد')->firstOrFail();
        $t2 = $req2->token;
        $this->actingAs($this->owner)->put('/esign/' . $req2->id, ['title' => 'لم تُفتح بعد', 'body' => 'نص آخر']);
        $this->assertSame($t2, $req2->fresh()->token);
    }

    /** S5: حذف قالب مستعمل كان نهائياً بلا حارس */
    public function test_template_in_use_cannot_be_deleted(): void
    {
        $this->seedCore();
        $tpl = SignTemplate::create(['name' => 'قالب مستعمل', 'body' => 'نص {اسم}']);
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'طلب من القالب', 'template_id' => $tpl->id,
            'vars' => ['اسم' => 'فلان'], 'pass' => 'Secret1234',
        ]);

        $this->actingAs($this->owner)->delete('/esign/templates/' . $tpl->id)->assertRedirect();
        $this->assertNotNull(SignTemplate::find($tpl->id), 'القالب المستعمل في طلبات لا يُحذف');

        // وغير المستعمل يُحذف كما كان
        $tpl2 = SignTemplate::create(['name' => 'قالب حر', 'body' => 'نص']);
        $this->actingAs($this->owner)->delete('/esign/templates/' . $tpl2->id);
        $this->assertNull(SignTemplate::find($tpl2->id));
    }

    /** S6: /verify كان يكشف وجود المسودات غير الموقعة وعناوينها لمن يجرب الرموز */
    public function test_verify_answers_only_for_signed_documents(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'مسودة سرية لم توقع', 'free_body' => 'نص', 'pass' => 'Secret1234',
        ]);
        $req = SignRequest::where('title', 'مسودة سرية لم توقع')->firstOrFail();

        $html = $this->post('/verify', ['code' => $req->verify_code])->assertOk()->getContent();
        $this->assertStringNotContainsString('مسودة سرية لم توقع', $html);
        $this->assertStringContainsString('لا مستند بهذا الرمز', $html);
    }

    /** انقلابات العقد صارت عبر Eloquent: تدقيق + إصدار + contract.signed يُطلق فعلاً */
    public function test_signing_fires_contract_signed_event_and_audits(): void
    {
        $this->seedCore();
        $captured = [];
        \App\Support\HubEvents::listen(function ($event) use (&$captured) {
            $captured[] = $event;
        });

        $c = Contract::create(['title' => 'عقد الحدث', 'type' => 'عقد عميل', 'status' => 'مسودة']);
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'توقيع عقد الحدث', 'free_body' => 'نص العقد',
            'pass' => 'Secret1234', 'contract_id' => $c->id,
        ]);
        $this->assertSame('قيد التوقيع', $c->fresh()->status);

        $req = SignRequest::where('title', 'توقيع عقد الحدث')->firstOrFail();
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'Secret1234']);
        $this->get("/sign/{$req->token}");
        $png = 'data:image/png;base64,' . base64_encode(str_repeat('x', 120));
        $this->post("/sign/{$req->token}", ['signer_name' => 'موقّع الحدث', 'signature' => $png])->assertOk();

        $this->assertSame('ساري', $c->fresh()->status);
        $this->assertContains('contract.signed', $captured, 'التوقيع الفعلي يجب أن يطلق contract.signed');
        // الانقلاب مدقق ومُصدَر (Auditable + HasVersions عبر save حقيقي)
        $this->assertTrue(DB::table('audits')->where('module', 'contracts')->where('record_id', $c->id)
            ->where('action', 'تعديل')->exists(), 'انقلاب الحالة يجب أن يمر بالتدقيق');
        $this->assertGreaterThan(1, $c->fresh()->version, 'انقلاب الحالة يجب أن يرفع الإصدار');
    }

    /** الرفض كان يترك العقد معلقاً «قيد التوقيع» للأبد — الآن يعود مسودةً */
    public function test_decline_returns_contract_to_draft(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'عقد سيُرفض', 'type' => 'عقد عميل', 'status' => 'مسودة']);
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'توقيع سيُرفض', 'free_body' => 'نص', 'pass' => 'Secret1234',
            'contract_id' => $c->id, 'opt_decline' => 1,
        ]);
        $req = SignRequest::where('title', 'توقيع سيُرفض')->firstOrFail();
        $this->assertSame('قيد التوقيع', $c->fresh()->status);

        $this->post("/sign/{$req->token}/unlock", ['pass' => 'Secret1234']);
        $this->get("/sign/{$req->token}");
        $this->post("/sign/{$req->token}/decline", ['reason' => 'البنود غير مناسبة'])->assertOk();

        $this->assertSame('رُفض', $req->fresh()->status);
        $this->assertSame('مسودة', $c->fresh()->status, 'الرفض يعيد العقد لمسودة لا يتركه معلقاً');
    }

    /** casts: صف قديم بـ opts خام JSON يقرأ سليماً بعد إضافة الـ cast (فخ الترميز المزدوج) */
    public function test_legacy_raw_opts_row_roundtrips_after_cast(): void
    {
        $this->seedCore();
        $id = (string) Str::uuid();
        DB::table('sign_requests')->insert([
            'id' => $id, 'title' => 'صف قديم', 'body' => 'نص', 'pass' => bcrypt('Secret1234'),
            'token' => Str::random(48), 'verify_code' => 'LYN-OLDR-TEST',
            'opts' => '{"selfie":false,"idno":true,"decline":true}',
            'status' => 'بانتظار التوقيع', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $req = SignRequest::findOrFail($id);
        $this->assertIsArray($req->opts);
        $this->assertTrue($req->opts['idno']);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $req->created_at);

        // الصفحة العامة تقرأ الخيارات من الصف القديم بلا انفجار
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'Secret1234'])->assertRedirect();
        $this->get("/sign/{$req->token}")->assertOk()->assertSee('رقم الهوية');
    }

    /** علتا toContract: عمود start الوهمي (كان 500) وحالة «نشط» خارج الخيارات */
    public function test_quote_to_contract_creates_valid_contract(): void
    {
        $this->seedCore();
        $q = \App\Models\Quote::create(['doc_no' => 'Q-2026-CLM1', 'status' => 'مقبول', 'total' => 1500]);

        $this->actingAs($this->owner)
            ->post('/quote/' . $q->id . '/act', ['do' => 'contract'])
            ->assertRedirect();

        $cid = $q->fresh()->meta['contract_id'] ?? null;
        $this->assertNotNull($cid, 'التحويل يجب أن ينشئ عقداً ويسجله في meta');
        $c = Contract::findOrFail($cid);
        $this->assertNotNull($c->date_start, 'تاريخ البداية في عموده الصحيح date_start');
        $this->assertContains($c->status, ['مسودة', 'قيد التوقيع', 'ساري', 'منتهي', 'ملغى', 'قيد التجديد'],
            'حالة العقد المحول من ضمن الخيارات المعلنة');
    }
}
