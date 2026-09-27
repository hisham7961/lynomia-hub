<?php

namespace Tests\Feature\Mobile;

use App\Models\Asset;
use App\Models\AssetCustody;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Employee;
use App\Models\FinDocument;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **أهليّةُ الأزرار على الجوال — قراءةٌ لا تخمين** (إضافيّ): ثلاثُ قراءاتٍ تجيب «هل يظهر
 * الزرّ لهذا القارئ؟» **بقواعد الفعل نفسِه** لا بنسخةٍ في التطبيق:
 *  • `GET leaves/{id}/decision` ⇐ `LeaveDecision::abilities` (السببُ الآليُّ نفسُه الذي يردّ به `decide`).
 *  • `GET custody/{id}/abilities` ⇐ بوّابةُ `CustodyHandover` (e أو custodyAssign) + `Custody::scoped` + الحيازة.
 *  • `GET fin/{id}/pay-options` ⇐ بوّابةُ `FinPayment::authorize` + ما يعرضه نموذجُ الدفع الويبيّ.
 * كلُّها بلا أثر، ونطاقُها ٤٠٤ كفعلها، والعميلُ ٤٠٤. وكلُّ «مسموح» يُثبَت بأن الفعلَ نفسَه ينجح،
 * وكلُّ «ممنوعٍ بسبب» يُثبَت بأن الفعلَ يُرفض **بالسبب نفسِه**.
 */
class MobileActionEligibilityTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function person(string $name, array $matrix, array $flags = [], string $scope = 'all', array $fieldRules = []): User
    {
        $role = Role::create(['name' => $name . Str::random(4), 'scope' => $scope, 'flags' => $flags, 'matrix' => $matrix]
            + ($fieldRules ? ['field_rules' => $fieldRules] : []));

        return User::create(['name' => $name, 'email' => Str::random(9) . '@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function h(User $u, array $extra = []): array
    {
        return $this->bearer($this->mobileLogin($u)['access_token']) + $extra;
    }

    private function clientUser(string $roleId): User
    {
        $client = \App\Models\Client::create(['name' => 'عميل']);
        $cu = User::create(['name' => 'عميل', 'email' => Str::random(6) . '@ext.local', 'password' => 'Secret!2026x',
            'role_id' => $roleId, 'status' => 'نشط', 'password_changed_at' => now(), 'account_type' => 'client']);
        \App\Models\ClientMembership::create(['client_id' => $client->id, 'user_id' => $cu->id,
            'role' => 'lead', 'status' => 'active', 'activated_at' => now()]);

        return $cu;
    }

    /* ══════════════════ قرارُ الإجازة ══════════════════ */

    private function leaveWorld(): array
    {
        $this->seedCore();
        $mgr = $this->person('المدير', ['leaves' => ['v' => 1]]);
        $hr = $this->person('الموارد', ['leaves' => ['v' => 1], 'hr' => ['v' => 1, 'e' => 1]]);
        $requester = $this->person('صاحبُ الطلب', ['leaves' => ['v' => 1, 'a' => 1]]);
        $stranger = $this->person('زميلٌ غريب', ['leaves' => ['v' => 1]]);
        $emp = Employee::create(['name' => 'صاحبُ الطلب', 'status' => 'نشط', 'user_id' => $requester->id,
            'manager_id' => $mgr->id, 'leave_bal' => 30]);
        $req = LeaveRequest::create(['emp_id' => $emp->id, 'type' => 'إجازة سنوية',
            'date_from' => '2026-10-01', 'date_to' => '2026-10-05', 'days' => 5, 'status' => 'مقدّم']);

        return compact('mgr', 'hr', 'requester', 'stranger', 'emp', 'req');
    }

    private function decision(User $u, string $id)
    {
        return $this->withHeaders($this->h($u))->getJson('/api/mobile/v1/leaves/' . $id . '/decision');
    }

    public function test_leave_decision_reports_each_party_with_the_decide_reasons(): void
    {
        $w = $this->leaveWorld();

        $m = $this->decision($w['mgr'], $w['req']->id)->assertOk();
        $this->assertTrue($m->json('data.can_decide'));
        $this->assertNull($m->json('data.reason'));
        $this->assertSame('مقدّم', $m->json('data.status'));
        $this->assertTrue($m->json('data.can_approve'));
        $this->assertTrue($m->json('data.can_reject'));
        $this->assertSame('موافقة المدير', $m->json('data.approve_status'), 'المديرُ يوصي لا يعتمد');
        $this->assertTrue($m->json('data.reject_reason_required'));

        $hr = $this->decision($w['hr'], $w['req']->id)->assertOk();
        $this->assertTrue($hr->json('data.can_decide'));
        $this->assertSame('معتمد', $hr->json('data.approve_status'));

        $self = $this->decision($w['requester'], $w['req']->id)->assertOk();
        $this->assertFalse($self->json('data.can_decide'));
        $this->assertSame('self_request', $self->json('data.reason'));
        $this->assertFalse($self->json('data.can_approve'));
        $this->assertFalse($self->json('data.can_reject'));
        $this->assertNull($self->json('data.approve_status'));

        $str = $this->decision($w['stranger'], $w['req']->id)->assertOk();
        $this->assertFalse($str->json('data.can_decide'));
        $this->assertSame('not_decider', $str->json('data.reason'));

        // القراءةُ بلا أثر
        $this->assertSame('مقدّم', (string) $w['req']->fresh()->status);
        $this->assertSame(0, DB::table('audits')->where('action', 'قرار إجازة')->count());

        // التكافؤ: «ممنوعٌ بسبب» ⇒ الفعلُ يُرفض بالسبب نفسِه
        foreach (['requester' => 'self_request', 'stranger' => 'not_decider'] as $who => $why) {
            $this->withHeaders($this->h($w[$who]))->postJson('/api/mobile/v1/leaves/' . $w['req']->id . '/decide', ['decision' => 'approve'])
                ->assertStatus(403)->assertJsonPath('details.reason', $why);
        }

        // بعد توصية المدير: المديرُ يرفض ولا يوصي ثانيةً، والموارد تحسم
        $this->withHeaders($this->h($w['mgr']))->postJson('/api/mobile/v1/leaves/' . $w['req']->id . '/decide', ['decision' => 'approve'])
            ->assertOk();
        $m2 = $this->decision($w['mgr'], $w['req']->id)->assertOk();
        $this->assertSame('موافقة المدير', $m2->json('data.status'));
        $this->assertTrue($m2->json('data.can_decide'));
        $this->assertFalse($m2->json('data.can_approve'));
        $this->assertTrue($m2->json('data.can_reject'));

        $this->withHeaders($this->h($w['hr']))->postJson('/api/mobile/v1/leaves/' . $w['req']->id . '/decide', ['decision' => 'approve'])
            ->assertOk();
        foreach (['mgr', 'hr'] as $who) {
            $done = $this->decision($w[$who], $w['req']->id)->assertOk();
            $this->assertFalse($done->json('data.can_decide'));
            $this->assertSame('already_decided', $done->json('data.reason'));
            $this->assertSame('معتمد', $done->json('data.status'));
        }
        $this->withHeaders($this->h($w['hr']))->postJson('/api/mobile/v1/leaves/' . $w['req']->id . '/decide',
            ['decision' => 'reject', 'reason' => 'متأخّر'])->assertStatus(422)->assertJsonPath('details.reason', 'already_decided');
    }

    public function test_leave_decision_is_gated_and_scoped_like_decide(): void
    {
        $w = $this->leaveWorld();

        $blind = $this->person('بلا رؤية', ['tasks' => ['v' => 1]]);
        $this->decision($blind, $w['req']->id)->assertStatus(403);

        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $w['req']->forceFill(['company_id' => $coA->id])->saveQuietly();
        $isolated = $this->person('مواردُ شركةٍ أخرى', ['leaves' => ['v' => 1], 'hr' => ['v' => 1, 'e' => 1]]);
        $isolated->forceFill(['companies' => [$coB->id]])->save();
        $this->decision($isolated, $w['req']->id)->assertStatus(404)->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $this->decision($w['hr'], (string) Str::uuid())->assertStatus(404);

        $cu = $this->clientUser($w['hr']->role_id);
        $this->decision($cu, $w['req']->id)->assertStatus(404);
    }

    /* ══════════════════ العهدة ══════════════════ */

    private function abilities(User $u, string $id)
    {
        return $this->withHeaders($this->h($u))->getJson('/api/mobile/v1/custody/' . $id . '/abilities');
    }

    public function test_custody_abilities_follow_the_handover_rail(): void
    {
        $this->seedCore();
        $clerk = $this->person('أمينُ العهدة', ['assets' => ['v' => 1, 'custodyAssign' => 1]]);
        $editor = $this->person('محرّرُ الأصول', ['assets' => ['v' => 1, 'e' => 1]]);
        $viewer = $this->person('مشاهد', ['assets' => ['v' => 1]]);
        $free = Asset::create(['name' => 'شاشةٌ في المخزن', 'type' => 'شاشة', 'status' => 'متاح']);
        $held = Asset::create(['name' => 'هاتفٌ بيد موظّفة', 'type' => 'هاتف', 'holder_id' => $this->employee->id,
            'status' => 'قيد الاستخدام']);

        $a = $this->abilities($clerk, $free->id)->assertOk();
        $this->assertTrue($a->json('data.can_handover'));
        $this->assertFalse($a->json('data.can_recover'));
        $this->assertNull($a->json('data.holder_id'));
        $this->assertSame('not_held', $a->json('data.reason'));
        // التكافؤ: الاستردادُ مرفوضٌ فعلاً على غير المحوز
        $this->withHeaders($this->h($clerk))->postJson('/api/mobile/v1/custody/' . $free->id . '/recover',
            ['at' => now()->toDateString()])->assertStatus(422);

        $b = $this->abilities($editor, $held->id)->assertOk();
        $this->assertTrue($b->json('data.can_handover'));
        $this->assertTrue($b->json('data.can_recover'));
        $this->assertSame($this->employee->id, $b->json('data.holder_id'));
        $this->assertNull($b->json('data.reason'));

        $c = $this->abilities($viewer, $held->id)->assertOk();
        $this->assertFalse($c->json('data.can_handover'));
        $this->assertFalse($c->json('data.can_recover'));
        $this->assertSame('not_permitted', $c->json('data.reason'));
        $this->withHeaders($this->h($viewer))->postJson('/api/mobile/v1/custody/' . $held->id . '/recover',
            ['at' => now()->toDateString()])->assertStatus(403);

        $this->assertSame(0, AssetCustody::count(), 'القراءةُ بلا أثر');
        $this->assertSame($this->employee->id, $held->fresh()->holder_id);
    }

    public function test_custody_abilities_are_gated_and_scoped_like_the_action(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $asset = Asset::create(['name' => 'طابعة', 'type' => 'طابعة', 'company_id' => $coA->id,
            'holder_id' => $this->employee->id]);

        $isolated = $this->person('أمينُ باء', ['assets' => ['v' => 1, 'custodyAssign' => 1]]);
        $isolated->forceFill(['companies' => [$coB->id]])->save();
        $this->abilities($isolated, $asset->id)->assertStatus(404);

        $blind = $this->person('بلا أصول', ['tasks' => ['v' => 1]]);
        $this->abilities($blind, $asset->id)->assertStatus(403);

        $cu = $this->clientUser($this->employee->role_id);
        $this->abilities($cu, $asset->id)->assertStatus(404);
    }

    /* ══════════════════ خياراتُ الدفعة ══════════════════ */

    private function invoice(array $extra = []): FinDocument
    {
        return FinDocument::create(array_merge(['doc_no' => 'INV-P1', 'kind' => 'فاتورة مبيعات',
            'total' => 100, 'paid' => 25.5, 'state' => 'مرسلة', 'currency' => 'KWD'], $extra));
    }

    private function payOptions(User $u, string $id)
    {
        return $this->withHeaders($this->h($u))->getJson('/api/mobile/v1/fin/' . $id . '/pay-options');
    }

    public function test_pay_options_mirror_the_web_pay_form(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $bankB = BankAccount::create(['name' => 'بنكُ باء', 'currency' => 'KWD', 'balance' => 0, 'company_id' => $coB->id]);
        $bankA2 = BankAccount::create(['name' => 'صندوقُ ألف', 'currency' => 'KWD', 'balance' => 0, 'company_id' => $coA->id]);
        $bankA1 = BankAccount::create(['name' => 'بنكُ ألف', 'currency' => 'USD', 'balance' => 0, 'company_id' => $coA->id]);
        $gone = BankAccount::create(['name' => 'بنكٌ محذوف', 'currency' => 'KWD', 'balance' => 0, 'company_id' => $coA->id]);
        $gone->forceFill(['deleted_at' => now()])->saveQuietly();
        $doc = $this->invoice(['company_id' => $coA->id, 'bank_id' => $bankA2->id]);

        $acc = $this->person('محاسبُ ألف', ['fin' => ['v' => 1, 'e' => 1], 'banks' => ['v' => 1, 'e' => 1]]);
        $acc->forceFill(['companies' => [$coA->id]])->save();

        $r = $this->payOptions($acc, $doc->id)->assertOk();
        $this->assertTrue($r->json('data.can_pay'));
        $this->assertNull($r->json('data.reason'));
        $this->assertSame('74.500', $r->json('data.remaining'));
        $this->assertSame('KWD', $r->json('data.currency'));
        $this->assertSame((string) $bankA2->id, $r->json('data.default_bank_id'));
        $this->assertSame('action:fin:pay', $r->json('data.step_up_purpose'));
        // بنوكُ نطاقي وحدَها، بترتيب الاسم كنموذج الويب — لا بنكُ الشركة الأخرى ولا المحذوف
        $banks = $r->json('data.banks');
        $this->assertSame([(string) $bankA1->id, (string) $bankA2->id], array_column($banks, 'id'));
        $this->assertSame('بنكُ ألف', $banks[0]['name']);
        $this->assertSame('USD', $banks[0]['currency']);
        $this->assertSame('KWD', $banks[1]['currency']);
        $this->assertSame(0, DB::table('audits')->where('action', 'دفعة')->count(), 'القراءةُ بلا أثر');
        $this->assertSame(25.5, (float) $doc->fresh()->paid);

        // من لا يرى البنوك: لا قائمة (الويبُ يُخفي المنتقي) — والدفعةُ ممكنة
        $noBanks = $this->person('محاسبٌ بلا بنوك', ['fin' => ['v' => 1, 'e' => 1]]);
        $nb = $this->payOptions($noBanks, $doc->id)->assertOk();
        $this->assertSame([], $nb->json('data.banks'));
        $this->assertNull($nb->json('data.default_bank_id'));
        $this->assertTrue($nb->json('data.can_pay'));
    }

    public function test_pay_options_report_dead_and_settled_documents(): void
    {
        $this->seedCore();
        $dead = $this->invoice(['doc_no' => 'INV-D', 'state' => 'ملغاة']);
        $settled = $this->invoice(['doc_no' => 'INV-S', 'paid' => 100, 'state' => 'مدفوعة']);

        $d = $this->payOptions($this->employee, $dead->id)->assertOk();
        $this->assertFalse($d->json('data.can_pay'));
        $this->assertSame('dead_state', $d->json('data.reason'));

        $s = $this->payOptions($this->employee, $settled->id)->assertOk();
        $this->assertFalse($s->json('data.can_pay'));
        $this->assertSame('settled', $s->json('data.reason'));
        $this->assertSame('0.000', $s->json('data.remaining'));
    }

    public function test_pay_options_gate_scope_and_mask_like_pay(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $foreign = $this->invoice(['doc_no' => 'INV-B', 'company_id' => $coB->id]);
        $doc = $this->invoice(['company_id' => $coA->id]);

        // عرضٌ فقط ⇒ 403 كالفعل
        $this->payOptions($this->viewer, $doc->id)->assertForbidden();

        $iso = $this->person('محاسبُ ألف', ['fin' => ['v' => 1, 'e' => 1]]);
        $iso->forceFill(['companies' => [$coA->id]])->save();
        $this->payOptions($iso, $foreign->id)->assertNotFound();
        // بلا تصعيد: القراءةُ لا تطلبه (الفعلُ وحدَه يطلبه)
        $this->payOptions($iso, $doc->id)->assertOk();

        // الإجماليُّ محجوبٌ على الدور ⇒ المتبقّي null (لا يُشتقّ منه المحجوب)
        $masked = $this->person('محاسبٌ محجوب', ['fin' => ['v' => 1, 'e' => 1]], [], 'all', ['fin' => ['total' => 'hide']]);
        $m = $this->payOptions($masked, $doc->id)->assertOk();
        $this->assertNull($m->json('data.remaining'));
        $this->assertTrue($m->json('data.can_pay'), 'الحجبُ عرضٌ لا يغيّر قاعدةَ الفعل');
        $this->assertMaskedValueAbsent((string) $m->getContent(), '74.500');

        $cu = $this->clientUser($this->employee->role_id);
        $this->payOptions($cu, $doc->id)->assertStatus(404);
    }
}
