<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\FinDocument;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * الفحص النهائيّ (بند ٨) — عيبا المال العاليان، كلٌّ فاشلٌ أولاً:
 *  (١) حرّاسُ pay() كانت مشروطةً بوسيط bankId؛ فمستندٌ ضُبط بنكُه عبر النموذج
 *      ثم دُفع بلا bankId يحرّك رصيدَ بنكٍ خارج الصلاحية/النطاق/العملة.
 *  (٢) لا حارس يمنع حذف مستندٍ مدفوع — فيبقى رصيدُ بنكٍ يتيمٌ لا يُردّ.
 */
class FinalAuditFinanceTest extends TestCase
{
    public function test_pay_without_bankId_guards_the_stored_bank_scope(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ']);
        $b = Company::create(['name_ar' => 'شركة ب']);
        $bankB = BankAccount::create(['name' => 'بنك ب', 'balance' => 1000, 'company_id' => $b->id, 'status' => 'نشط']);
        // مستندٌ في شركة أ لكن بنكُه المخزَّن بنكُ الشركة ب (ضُبط عبر النموذج، exists وحدها)
        $doc = FinDocument::create(['doc_no' => 'INV-STORED', 'kind' => 'فاتورة مبيعات',
            'total' => 500, 'state' => 'مرسلة', 'company_id' => $a->id, 'bank_id' => $bankB->id]);

        $finRole = Role::create(['name' => 'محاسب أ٢', 'scope' => 'all', 'flags' => [],
            'matrix' => ['fin' => ['v' => 1, 'e' => 1], 'banks' => ['v' => 1, 'e' => 1]]]);
        $clerk = User::create(['name' => 'محاسب أ٢', 'email' => 'acc-a2@t.local',
            'password' => 'Secret!2026x', 'role_id' => $finRole->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => [$a->id]]);

        // دفعةٌ بلا bankId — البنكُ المخزَّن (خارج نطاق أ) لا يجوز أن يتحرّك عبر البابِ الجانبيّ
        $this->actingAs($clerk)->post("/fin/{$doc->id}/act", ['do' => 'pay', 'amount' => 500])
            ->assertStatus(422);

        $this->assertSame(1000.0, (float) $bankB->fresh()->balance, 'رصيدُ بنكٍ خارج النطاق تحرّك بدفعةٍ بلا bankId');
        $this->assertSame(0.0, (float) $doc->fresh()->paid, 'الدفعة سُجّلت رغم رفض البنك المخزَّن');
    }

    public function test_paid_fin_document_cannot_be_deleted(): void
    {
        $this->seedCore();
        $bank = BankAccount::create(['name' => 'الصندوق', 'balance' => 0, 'status' => 'نشط']);
        $doc = FinDocument::create(['doc_no' => 'INV-PAID', 'kind' => 'فاتورة مبيعات', 'total' => 250, 'state' => 'مرسلة']);

        // ادفع كاملاً فيتحرّك رصيدُ البنك ويصير المستند «مدفوعة»
        $this->actingAs($this->owner)->post("/fin/{$doc->id}/act",
            ['do' => 'pay', 'amount' => 250, 'bankId' => $bank->id])->assertRedirect();
        $this->assertSame(250.0, (float) $doc->fresh()->paid);
        $this->assertSame(250.0, (float) $bank->fresh()->balance);

        // حذفُ مستندٍ مدفوعٍ محظور — وإلا بقي رصيدُ بنكٍ يتيمٌ لا يوازيه مستندٌ ظاهر
        $this->actingAs($this->owner)->delete("/m/fin/{$doc->id}");

        $this->assertNotNull(FinDocument::find($doc->id), 'مستندٌ مدفوعٌ حُذف — رصيدُ البنك صار يتيماً');
        $this->assertNull($doc->fresh()->deleted_at, 'المستند المدفوع نُقل إلى السلة رغم دفعته');
    }
}
