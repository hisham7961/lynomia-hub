<?php

namespace Tests\Feature\Mobile;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\FinDocument;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Purchase;
use App\Models\Quote;
use App\Models\Role;
use App\Models\StockItem;
use App\Models\StockMove;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **أفعالٌ ماليّةٌ على الجوال عبر المحرّكات الموحّدة** (خطّة التطبيق 4.6):
 *  • الدفعة ⇐ `FinPayment` (+ `JournalPosting`): `fin:e` + النطاق + قفلُ الحالة + حرّاسُ البنك
 *    + **تصعيدُ الجوال** + Idempotency + التدقيق.
 *  • عرضُ السعر ⇐ `QuoteActions`/`QuoteAcceptance`: `quotes:e` + الطابور + قفلُ حقل الحالة + العتبة.
 *  • الاستلام ⇐ `PurchaseFlow`: `purchases:e` + آلةُ الحالة + حركاتُ مخزونٍ مرّةً واحدة.
 * وحسابُ العميل ٤٠٤ ولو كانت `fin` ضمن وحداته.
 */
class MobileFinanceActionsTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function h(User $u): array
    {
        return $this->bearer($this->mobileLogin($u)['access_token']);
    }

    private function stepUp(array $h, string $purpose = 'action:fin:pay'): void
    {
        $this->withHeaders($h)->postJson('/api/mobile/v1/auth/step-up', ['purpose' => $purpose, 'credential' => 'Secret!2026x'])
            ->assertOk();
    }

    private function invoice(array $extra = []): FinDocument
    {
        return FinDocument::create(array_merge(['doc_no' => 'INV-M1', 'kind' => 'فاتورة مبيعات',
            'total' => 100, 'paid' => 0, 'state' => 'مرسلة', 'currency' => 'KWD'], $extra));
    }

    public function test_pay_requires_fin_edit_scope_and_mobile_step_up(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $doc = $this->invoice();
        $foreign = $this->invoice(['doc_no' => 'INV-B', 'company_id' => $coB->id]);

        // عرضٌ فقط ⇒ 403
        $this->withHeaders($this->h($this->viewer))->postJson('/api/mobile/v1/fin/' . $doc->id . '/pay', ['amount' => '10'])
            ->assertForbidden();

        $this->employee->update(['companies' => [$coA->id]]);
        $E = $this->h($this->employee);
        // خارجَ النطاق ⇒ 404 قبل أيّ شيء
        $this->withHeaders($E)->postJson('/api/mobile/v1/fin/' . $foreign->id . '/pay', ['amount' => '10'])->assertNotFound();

        // بلا تصعيد ⇒ 428 بغرضٍ مسمّى — ولا أثر
        $doc->forceFill(['company_id' => $coA->id])->save();
        $this->withHeaders($E)->postJson('/api/mobile/v1/fin/' . $doc->id . '/pay', ['amount' => '40'])
            ->assertStatus(428)->assertJsonPath('code', 'STEP_UP_REQUIRED')->assertJsonPath('details.purpose', 'action:fin:pay');
        $this->assertSame(0.0, (float) $doc->fresh()->paid);

        // مبلغٌ بفواصلَ لا يُفسَّر بصمت
        $this->stepUp($E);
        $this->withHeaders($E)->postJson('/api/mobile/v1/fin/' . $doc->id . '/pay', ['amount' => '2,50.00'])->assertStatus(422);

        $res = $this->withHeaders($E + ['Idempotency-Key' => 'pay-1'])
            ->postJson('/api/mobile/v1/fin/' . $doc->id . '/pay', ['amount' => '40', 'payRef' => 'TRX-9'])->assertOk();
        $res->assertJsonPath('data.amount', '40.000')->assertJsonPath('data.document.paid', '40.000')
            ->assertJsonPath('data.document.remaining', '60.000')->assertJsonPath('data.document.state', 'مدفوعة جزئياً')
            ->assertJsonPath('data.payment.ref', 'TRX-9')->assertJsonPath('data.payment.seq', 1);

        // إعادةُ المحاولة بالمفتاح نفسِه ⇒ الردُّ المحفوظ لا دفعةٌ ثانية
        $this->withHeaders($E + ['Idempotency-Key' => 'pay-1'])
            ->postJson('/api/mobile/v1/fin/' . $doc->id . '/pay', ['amount' => '40', 'payRef' => 'TRX-9'])
            ->assertOk()->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame(40.0, (float) $doc->fresh()->paid);
        $this->assertCount(1, (array) ($doc->fresh()->meta['payments'] ?? []));
        $this->assertTrue(DB::table('audits')->where('action', 'دفعة')->where('record_id', $doc->id)->exists());
    }

    public function test_pay_status_locks_clip_and_post_the_journal_through_the_one_engine(): void
    {
        $this->seedCore();
        LedgerAccount::create(['code' => '1010', 'name' => 'الصندوق', 'type' => 'أصل']);
        LedgerAccount::create(['code' => '4010', 'name' => 'المبيعات', 'type' => 'إيراد']);
        $this->artisan('hub:set', ['key' => 'finance.accounts', 'value' => json_encode(['cash' => '1010', 'sales' => '4010'])]);
        $this->artisan('hub:set', ['key' => 'finance.auto_journal', 'value' => '1']);
        $doc = $this->invoice();
        $dead = $this->invoice(['doc_no' => 'INV-X', 'state' => 'ملغاة']);
        $E = $this->h($this->employee);
        $this->stepUp($E);

        $this->withHeaders($E)->postJson('/api/mobile/v1/fin/' . $dead->id . '/pay', ['amount' => '10'])->assertStatus(422);
        $this->assertSame(0.0, (float) $dead->fresh()->paid);

        // الفائضُ يُقصّ إلى المتبقّي، والمسدَّدُ لا يُدفع ثانية
        $this->withHeaders($E)->postJson('/api/mobile/v1/fin/' . $doc->id . '/pay', ['amount' => '1000'])->assertOk()
            ->assertJsonPath('data.amount', '100.000')->assertJsonPath('data.document.state', 'مدفوعة');
        $this->withHeaders($E)->postJson('/api/mobile/v1/fin/' . $doc->id . '/pay', ['amount' => '1'])->assertStatus(422);

        $entry = JournalEntry::where('fin_id', $doc->id)->orderBy('id')->firstOrFail();
        $this->assertSame('fin', (string) $entry->source_module);
        $this->assertSame('payment:1', (string) $entry->source_key);
        $this->assertSame(1, JournalEntry::where('fin_id', $doc->id)->count());
    }

    public function test_pay_guards_the_bank_that_actually_moves(): void
    {
        $this->seedCore();
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $role = Role::create(['name' => 'محاسبٌ بلا بنوك', 'scope' => 'all', 'flags' => [],
            'matrix' => ['fin' => ['v' => 1, 'e' => 1], 'banks' => ['v' => 1]]]);
        $acc = User::create(['name' => 'محاسب', 'email' => 'acc@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $bank = BankAccount::create(['name' => 'بنك', 'currency' => 'KWD', 'balance' => 500, 'company_id' => $coB->id]);
        $doc = $this->invoice();
        $H = $this->h($acc);
        $this->stepUp($H);

        $this->withHeaders($H)->postJson('/api/mobile/v1/fin/' . $doc->id . '/pay', ['amount' => '10', 'bankId' => (string) $bank->id])
            ->assertStatus(422);
        $this->assertSame(0.0, (float) $doc->fresh()->paid, 'المعاملةُ رجعت كلُّها');
        $this->assertSame(500.0, (float) $bank->fresh()->balance);
    }

    public function test_quote_send_and_accept_follow_the_web_act_gates(): void
    {
        $this->seedCore();
        $q = Quote::create(['doc_no' => 'Q-M1', 'title' => 'عرضُ الصيانة', 'total' => 900, 'status' => 'مسودة']);
        $url = '/api/mobile/v1/quotes/' . $q->id;

        $this->withHeaders($this->h($this->viewer))->postJson($url . '/send')->assertForbidden();

        // عتبةُ الاعتماد: الموظفةُ بلا رايةِ اعتماد ⇒ تُحال للمراجعة الداخليّة
        $this->hubSetting('quotes.approve_amount', '500');
        $E = $this->h($this->employee);
        $this->withHeaders($E)->postJson($url . '/send')->assertOk()
            ->assertJsonPath('data.outcome', 'escalated')->assertJsonPath('data.quote.status', 'مراجعة داخلية');
        $this->assertNull($q->fresh()->sent_at);

        // طابورُ الموافقات لا تلتفّ عليه أزرارُ المسار ⇒ 409
        $this->hubSetting('approval.rules', 'quotes:e');
        $this->withHeaders($E)->postJson($url . '/accept')->assertStatus(409)->assertJsonPath('code', 'APPROVAL_REQUIRED');
        $this->hubSetting('approval.rules', '');

        // المالكُ يرسل (فوق العتبة) ثمّ يقبل — والقبولُ الثاني بلا أثر
        $O = $this->h($this->owner);
        $this->withHeaders($O)->postJson($url . '/send')->assertOk()->assertJsonPath('data.outcome', 'sent')
            ->assertJsonPath('data.quote.status', 'مُرسل');
        $this->assertNotNull($q->fresh()->sent_at);
        $this->withHeaders($O)->postJson($url . '/accept')->assertOk()->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.quote.status', 'مقبول');
        $this->withHeaders($O)->postJson($url . '/accept')->assertOk()->assertJsonPath('data.accepted', false);
        $this->assertSame('mobile', (string) ($q->fresh()->meta['acceptance']['via'] ?? ''));
        $this->assertSame((string) $this->owner->id, (string) ($q->fresh()->meta['acceptance']['user_id'] ?? ''));
    }

    public function test_quote_status_field_locked_for_role_refuses_accept(): void
    {
        $this->seedCore();
        $q = Quote::create(['doc_no' => 'Q-M2', 'title' => 'عرض', 'total' => 10, 'status' => 'مُرسل']);
        $this->employee->role->forceFill(['field_rules' => ['quotes' => ['status' => 'ro']]])->save();

        $this->withHeaders($this->h($this->employee->fresh()))->postJson('/api/mobile/v1/quotes/' . $q->id . '/accept')
            ->assertForbidden();
        $this->assertSame('مُرسل', (string) $q->fresh()->status);
    }

    public function test_purchase_receive_moves_stock_once_and_respects_the_state_machine(): void
    {
        $this->seedCore();
        $item = StockItem::create(['name' => 'كيبل', 'qty' => 10, 'reorder' => 2, 'status' => 'متاح']);
        $draft = Purchase::create(['doc_no' => 'PO-D', 'title' => 'مسودة', 'status' => 'مسودة', 'items' => 'كيبل | 5 | 2']);
        $p = Purchase::create(['doc_no' => 'PO-M', 'title' => 'أمر', 'status' => 'أُرسل للمورد', 'amount' => 100,
            'items' => "كيبل | 15 | 2\nصنفٌ مجهول | 3 | 1"]);

        $this->withHeaders($this->h($this->viewer))->postJson('/api/mobile/v1/purchases/' . $p->id . '/receive')->assertForbidden();

        $E = $this->h($this->employee);
        $this->withHeaders($E)->postJson('/api/mobile/v1/purchases/' . $draft->id . '/receive')->assertStatus(422);

        $this->withHeaders($E + ['Idempotency-Key' => 'rcv-1'])->postJson('/api/mobile/v1/purchases/' . $p->id . '/receive')
            ->assertOk()->assertJsonPath('data.moves', 1)->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.purchase.status', 'مستلم');
        $this->withHeaders($E + ['Idempotency-Key' => 'rcv-1'])->postJson('/api/mobile/v1/purchases/' . $p->id . '/receive')
            ->assertOk()->assertHeader('X-Idempotent-Replay', 'true');
        // بلا مفتاح (الترويساتُ تُصفّى — `withHeaders` يُبقيها) ⇒ آلةُ الحالة: المستلَمُ لا يُستلم ثانية
        $this->flushHeaders();
        $this->withHeaders($E)->postJson('/api/mobile/v1/purchases/' . $p->id . '/receive')->assertStatus(422);

        $this->assertEquals(25.0, (float) $item->fresh()->qty);
        $this->assertSame(1, StockMove::where('reference', 'PO-M')->count());
    }

    public function test_client_account_is_404_on_every_finance_action(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [],
            'matrix' => ['fin' => ['v' => 1, 'e' => 1], 'quotes' => ['v' => 1, 'e' => 1], 'purchases' => ['v' => 1, 'e' => 1]]]);
        $client = User::create(['name' => 'عميل', 'email' => 'cl@ext.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => 'client', 'password_changed_at' => now()]);
        $doc = $this->invoice();
        $q = Quote::create(['doc_no' => 'Q-C', 'title' => 'عرض', 'total' => 10, 'status' => 'مُرسل']);
        $p = Purchase::create(['doc_no' => 'PO-C', 'title' => 'أمر', 'status' => 'أُرسل للمورد', 'items' => '']);
        $H = $this->h($client);

        $this->withHeaders($H)->postJson('/api/mobile/v1/fin/' . $doc->id . '/pay', ['amount' => '1'])->assertNotFound();
        $this->withHeaders($H)->postJson('/api/mobile/v1/quotes/' . $q->id . '/accept')->assertNotFound();
        $this->withHeaders($H)->postJson('/api/mobile/v1/purchases/' . $p->id . '/receive')->assertNotFound();
        $this->assertSame(0.0, (float) $doc->fresh()->paid);
        $this->assertSame('مُرسل', (string) $q->fresh()->status);
    }
}
