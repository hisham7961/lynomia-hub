<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\FinDocument;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * الموجة الرابعة — تحويل العرض لعقد/فاتورة يتخطّى صلاحية الوحدة الهدف (تصعيد صلاحية):
 * البوابة الوحيدة للمسار كانت hub_can(user,'quotes','e')، ثم toContract/toInvoice
 * ينشئان Contract/FinDocument مباشرةً دون أي hub_can(user,'contracts','a') أو
 * hub_can(user,'fin','a'). فمستخدمٌ مُنح تعديل العروض فقط — ومُنع صراحةً من إنشاء
 * العقود والفواتير — يسكّ عقوداً وفواتير مبيعات حقيقية تدخل MRR بزرّي «تحويل».
 *
 * الحارس الآن يطابق المسار الرسمي: toContract يفرض contracts:a (كـ
 * ContractActionsController::find)، وtoInvoice يفرض fin:a (كـ store لوحدة fin).
 */
class QuoteConversionAuthzRound4Test extends TestCase
{
    /** صلاحية تعديل العروض فقط — لا عقود ولا مالية */
    protected function quotesEditor(): User
    {
        $role = Role::create(['name' => 'مندوب مبيعات', 'scope' => 'all', 'flags' => [],
            'matrix' => ['quotes' => ['v' => 1, 'e' => 1]]]);

        return User::create(['name' => 'مندوب', 'email' => 'sales@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    protected function acceptedQuote(): Quote
    {
        $client = Client::create(['name' => 'عميل', 'stage' => 'عميل حالي']);

        return Quote::create(['doc_no' => 'Q-AUTHZ-1', 'title' => 'عرض', 'status' => 'مقبول',
            'client_id' => $client->id, 'total' => 250, 'currency' => 'د.ك']);
    }

    public function test_quotes_editor_cannot_mint_a_contract(): void
    {
        $this->seedCore();
        $u = $this->quotesEditor();
        $q = $this->acceptedQuote();

        $this->actingAs($u)->post("/quote/{$q->id}/act", ['do' => 'contract'])->assertForbidden();
        $this->assertSame(0, Contract::where('title', 'LIKE', '%Q-AUTHZ-1%')->count(),
            'مستخدم بلا صلاحية عقود سكّ عقداً عبر تحويل العرض');
    }

    public function test_quotes_editor_cannot_mint_an_invoice(): void
    {
        $this->seedCore();
        $u = $this->quotesEditor();
        $q = $this->acceptedQuote();

        $this->actingAs($u)->post("/quote/{$q->id}/act", ['do' => 'invoice'])->assertForbidden();
        $this->assertNull(FinDocument::where('doc_no', 'INV-Q-AUTHZ-1')->first(),
            'مستخدم بلا صلاحية مالية سكّ فاتورة تدخل MRR عبر تحويل العرض');
    }

    /** لا إفراط في المنع: مخوَّلٌ بـcontracts:a وfin:a يحوّل كالمعتاد */
    public function test_authorized_user_can_still_convert(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'مبيعات كامل', 'scope' => 'all', 'flags' => [],
            'matrix' => ['quotes' => ['v' => 1, 'e' => 1], 'contracts' => ['v' => 1, 'a' => 1],
                'fin' => ['v' => 1, 'a' => 1]]]);
        $u = User::create(['name' => 'كامل', 'email' => 'full@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $q = $this->acceptedQuote();

        $this->actingAs($u)->post("/quote/{$q->id}/act", ['do' => 'contract'])->assertRedirect();
        $this->assertSame(1, Contract::where('title', 'LIKE', '%Q-AUTHZ-1%')->count(),
            'حُرم مخوَّلٌ من تحويلٍ مشروع');
    }
}
