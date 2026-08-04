<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\FinDocument;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * الموجة الرابعة — متانة تحويل العرض (QuoteController):
 * (أ) حارس عدم-التكرار كان `FinDocument::find` وSoftDeletes تُقصي المحذوف بنعومة
 *     فيعيد null فتُنشأ فاتورة/عقد ثانٍ بالرقم نفسه — ازدواج إيراد/MRR. الآن الفحص
 *     يشمل withTrashed داخل معاملةٍ على صفٍّ مقفول.
 * (ب) أزرار المسار (accept/send/reject) كانت تقلب الحالة دون استشارة hub_field_mode
 *     — فدورٌ حقلُ حالته «قراءة فقط» يدفع الصفقة رغم القفل. الآن setStatus يستشيره.
 * (ج) رقم الفاتورة INV-<doc_no> وعنوان العقد كانا يفيضان varchar(300) على MySQL
 *     الصارم (خطأ 22001) — الآن يُقصّان بـmb_substr كما في نظائرهما.
 */
class QuoteConversionRobustnessRound4Test extends TestCase
{
    protected function acceptedQuote(string $docNo = 'Q-RB-1'): Quote
    {
        return Quote::create(['doc_no' => $docNo, 'title' => 'عرض', 'status' => 'مقبول',
            'total' => 250, 'currency' => 'د.ك']);
    }

    /** (أ) تحويلٌ ثانٍ بعد حذف الفاتورة بنعومة لا يُنشئ فاتورة ثانية بالرقم نفسه */
    public function test_reconverting_after_soft_deleting_invoice_does_not_duplicate(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->acceptedQuote('Q-DUP-INV');

        $this->post("/quote/{$q->id}/act", ['do' => 'invoice']);
        $inv = FinDocument::where('doc_no', 'INV-Q-DUP-INV')->firstOrFail();
        $inv->delete();   // حذف ناعم من شاشة المالية

        $this->post("/quote/{$q->id}/act", ['do' => 'invoice']);
        $this->assertSame(1, FinDocument::withTrashed()->where('doc_no', 'INV-Q-DUP-INV')->count(),
            'أُنشئت فاتورة ثانية بالرقم نفسه بعد حذف الأولى بنعومة');
    }

    /** (أ) ومثلها العقد */
    public function test_reconverting_after_soft_deleting_contract_does_not_duplicate(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->acceptedQuote('Q-DUP-CTR');

        $this->post("/quote/{$q->id}/act", ['do' => 'contract']);
        $cId = $q->fresh()->meta['contract_id'] ?? null;
        $this->assertNotNull($cId);
        Contract::findOrFail($cId)->delete();

        $this->post("/quote/{$q->id}/act", ['do' => 'contract']);
        $this->assertSame(1, Contract::withTrashed()->where('title', 'LIKE', '%Q-DUP-CTR%')->count(),
            'أُنشئ عقد ثانٍ بعد حذف الأول بنعومة');
    }

    /** (ب) دورٌ حقلُ حالته «قراءة فقط» لا يقلبها من أزرار المسار */
    public function test_status_locked_role_cannot_flip_status_via_buttons(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'مبيعات مقيّد', 'scope' => 'all', 'flags' => [],
            'matrix' => ['quotes' => ['v' => 1, 'e' => 1]], 'field_rules' => ['quotes' => ['status' => 'ro']]]);
        $u = User::create(['name' => 'مقيّد', 'email' => 'ro-sales@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $q = Quote::create(['doc_no' => 'Q-RO-1', 'title' => 'عرض', 'status' => 'مسودة',
            'total' => 100, 'currency' => 'د.ك']);

        $this->actingAs($u)->post("/quote/{$q->id}/act", ['do' => 'accept'])->assertForbidden();
        $this->assertNotSame('مقبول', $q->fresh()->status, 'قُلبت الحالة رغم قفلها قراءةً-فقط');
    }

    /** (ج) رقم الفاتورة وعنوان العقد لا يفيضان عرض العمود (300) */
    public function test_conversion_truncates_overflowing_doc_no_and_title(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $qi = $this->acceptedQuote(str_repeat('ص', 298));   // INV- + 298 = 302 > 300
        $this->post("/quote/{$qi->id}/act", ['do' => 'invoice']);
        $inv = FinDocument::find($qi->fresh()->meta['invoice_id'] ?? null);
        $this->assertNotNull($inv);
        $this->assertLessThanOrEqual(300, mb_strlen((string) $inv->doc_no),
            'رقم الفاتورة يفيض عرض العمود — ينفجر على MySQL بـ22001');

        $qc = $this->acceptedQuote(str_repeat('ع', 298));
        $this->post("/quote/{$qc->id}/act", ['do' => 'contract']);
        $c = Contract::find($qc->fresh()->meta['contract_id'] ?? null);
        $this->assertNotNull($c);
        $this->assertLessThanOrEqual(300, mb_strlen((string) $c->title),
            'عنوان العقد يفيض عرض العمود');
    }
}
