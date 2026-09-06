<?php

namespace Tests\Feature;

use App\Models\AccountActivation;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\FinDocument;
use App\Models\OutboxMessage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **لوحةُ عضويّة العميل على «عميل ٣٦٠» + الدعوات** (Work OS · الطور B · WP-B.3 · §13/§98).
 *
 * لوحةٌ **داخليّةٌ** (مديرُ الحساب) على صفحةِ عرض العميل: تُعدّد العضويّاتِ بأدوارها
 * وحالاتها، وتمنح دوراً، وتدعو زميلاً للتفعيل، وتسحب الوصول — كلُّها خلفَ مصفوفة
 * الأدوار (`hub_can('clients','e')`)، ومنحُ Client Owner/سحبُ الوصول = `hub_require_stepup`.
 *
 * القواعدُ الصلبةُ التي يحرسها هذا الملف (يمتدّ سوابقَ `ClientOperationsTest`/
 * `WorkOsClientMembershipTest`/`WorkOsClientPortalTest` لا يستنسخها):
 *  1) **الدعوةُ تُنشئ حسابَ عميلٍ بلا كلمةِ سرٍّ** (نمط C8): account_type=client،
 *     كلمةٌ عشوائيّةٌ لا تُدخِل، `password_changed_at` فارغة = «لم يُفعّل بعد» — ثم
 *     عضويّةٌ ثم تفعيلٌ عبر سكّة B.1 الوحيدة. **لا كلمةَ سرٍّ في أيّ OutboxMessage**.
 *  2) الدعوةُ لا تُكرّر المستخدمَ ولا العضويّة (البريدُ هو الهوية)، ودعوةٌ لعميلٍ
 *     آخر لا تُسرّب وصولَ عميلٍ أوّل (العزلُ بالعميل).
 *  3) منحُ Client Owner يتطلّب تصعيدَ هوية؛ ودورٌ أدنى لا يتطلّبه.
 *  4) سحبُ الوصول يتطلّب تصعيداً، ويُسقط العميلَ من نطاق العضو فوراً، ويُدخل
 *     سلسلةَ التدقيق.
 *  5) مستخدمٌ داخليٌّ للقراءة فقط (بلا `clients:e`) لا يدعو/يمنح/يسحب (٤٠٣).
 *  6) عضوُ Client Finance يرى فواتيرَه ولا يرى تكلفةً/هامشاً (field-mode + audience).
 *  7) الدورةُ كاملةً: دعوة ← تفعيل ← عضويّةٌ فعّالة ← البوابةُ تُفتح على بياناته.
 */
class WorkOsClientMembersTest extends TestCase
{
    /** كلمةٌ يضعها المدعوُّ بنفسه — نعرفها كي نتحرّى تسرّبها في الصادر */
    private const INVITEE_PW = 'Zam1l!Secret2026';

    private function client(string $name = 'شركة ألف'): Client
    {
        return Client::create(['name' => $name . ' ' . Str::random(4), 'stage' => 'عميل حالي']);
    }

    /** يختم نافذةَ تصعيد الهوية للمالك (كلمتُه من seedCore) */
    private function stampStepUp(): void
    {
        $this->post('/stepup', ['answer' => 'Secret!2026x'])->assertRedirect();
    }

    /** آخرُ نصِّ رسالةِ تفعيلٍ في الصادر */
    private function lastActivationText(): string
    {
        $m = OutboxMessage::where('kind', 'account_activation')
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertNotNull($m, 'لم تُصفَّ رسالةُ تفعيلٍ في الصادر');

        return (string) $m->text;
    }

    /** يُتمّ تفعيلَ المدعوِّ بكلمةٍ نعرفها (رمز ← كلمة سرّ) */
    private function activateInvitee(): void
    {
        $text = $this->lastActivationText();
        preg_match('#/activate/([A-Za-z0-9]+)#', $text, $tm);
        preg_match('/\b(\d{6})\b/u', $text, $om);
        $this->assertNotEmpty($tm[1] ?? null, 'لا رابطَ تفعيلٍ في الرسالة');
        $this->assertNotEmpty($om[1] ?? null, 'لا رمزَ سداسيٍّ في الرسالة');

        $this->post("/activate/{$tm[1]}/otp", ['otp' => $om[1]])->assertRedirect();
        $this->post("/activate/{$tm[1]}/set",
            ['password' => self::INVITEE_PW, 'password_confirmation' => self::INVITEE_PW]);
    }

    /* ────────── ١) الدعوةُ تُنشئ حسابَ عميلٍ بلا كلمةِ سرّ + عضويّة + تفعيلاً بلا كلمة ────────── */

    public function test_a_manager_invite_creates_a_passwordless_client_user_membership_and_activation(): void
    {
        $this->seedCore();
        $a = $this->client();

        // مديرُ الحساب (المالك) يدعو زميلاً بدور Finance — دورٌ أدنى، لا تصعيد
        $this->actingAs($this->owner)
            ->post(route('clients.members.invite', $a->id),
                ['email' => 'zamil@client.test', 'name' => 'زميلُ العميل', 'role' => 'finance'])
            ->assertRedirect();

        // (أ) حسابُ عميلٍ صلبٌ بلا كلمةِ سرٍّ صالحة، «لم يُفعّل بعد»
        $u = User::where('email', 'zamil@client.test')->first();
        $this->assertNotNull($u, 'لم يُنشأ حسابُ العميل المدعوّ');
        $this->assertTrue($u->isClientAccount(), 'الحسابُ يجب أن يكون account_type=client');
        $this->assertNull($u->password_changed_at, 'قبل التفعيل لا كلمةَ سرٍّ وضعها العميل');
        // كلمتُه ليست شيئاً يُعرَف: تجزيءٌ عشوائيٌّ لا يُدخِل — لا كلمةٌ مُولَّدةٌ تُرسَل
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check(self::INVITEE_PW, (string) $u->password),
            'الحسابُ أُنشئ بكلمةٍ معروفة — يجب أن تكون عشوائيّةً لا تُدخِل');

        // (ب) عضويّةٌ مطبَّعةٌ بدور Finance وحالةِ «مدعوّ»، مختومةٌ بمن دعا
        $m = ClientMembership::where('client_id', $a->id)->where('user_id', $u->id)->first();
        $this->assertNotNull($m, 'لم تُنشأ عضويّةٌ للمدعوّ');
        $this->assertSame('finance', $m->role);
        $this->assertSame('invited', $m->status, 'الدعوةُ تبدأ «مدعوّ» حتى يُفعّل حسابَه');
        $this->assertSame($this->owner->id, $m->invited_by);

        // (ج) تفعيلٌ صُدِر على سكّة B.1، ونصُّه بلا كلمةِ سرّ (لا وجودَ لها أصلاً)
        $this->assertSame(1, AccountActivation::where('user_id', $u->id)->count());
        $this->assertStringContainsString('/activate/', $this->lastActivationText());

        // نُتمّ التفعيلَ بكلمةٍ نعرفها ثم نمسح **كلَّ** الصادر: لا رسالةَ تحملها
        $this->activateInvitee();
        foreach (OutboxMessage::all() as $out) {
            $this->assertStringNotContainsString(self::INVITEE_PW, (string) $out->text,
                "كلمةُ سرِّ المدعوِّ تسرّبت في رسالةِ صادرٍ (kind={$out->kind}) — يُحظر حظراً باتّاً");
        }
    }

    /* ────────── ٢) لا تكرارَ مستخدمٍ/عضويّة، ولا تسرّبَ عميلٍ آخر ────────── */

    public function test_reinviting_the_same_colleague_does_not_duplicate_and_stays_client_scoped(): void
    {
        $this->seedCore();
        $a = $this->client('ألف');
        $b = $this->client('باء');

        // دعوةٌ أولى إلى «ألف»
        $this->actingAs($this->owner)->post(route('clients.members.invite', $a->id),
            ['email' => 'zamil@client.test', 'name' => 'زميل', 'role' => 'viewer'])->assertRedirect();

        // دعوةٌ ثانيةٌ بنفس البريد إلى «ألف» — لا حسابَ ثانٍ ولا عضويّةٌ ثانية
        $this->actingAs($this->owner)->post(route('clients.members.invite', $a->id),
            ['email' => 'zamil@client.test', 'name' => 'زميل', 'role' => 'viewer'])->assertRedirect();

        $this->assertSame(1, User::where('email', 'zamil@client.test')->count(), 'تكرّر حسابُ المستخدم');
        $u = User::where('email', 'zamil@client.test')->first();
        $this->assertSame(1, ClientMembership::where('client_id', $a->id)->where('user_id', $u->id)->count(),
            'تكرّرت العضويّةُ على العميل نفسه');

        // دعوةٌ للعميل «باء» — المستخدمُ نفسُه، عضويّةٌ منفصلةٌ لباء، ولا يتسرّب أحدُهما للآخر
        $this->actingAs($this->owner)->post(route('clients.members.invite', $b->id),
            ['email' => 'zamil@client.test', 'name' => 'زميل', 'role' => 'viewer'])->assertRedirect();

        $this->assertSame(1, User::where('email', 'zamil@client.test')->count(), 'الدعوةُ لعميلٍ آخر أنشأت حساباً ثانياً');
        $this->assertSame(2, ClientMembership::where('user_id', $u->id)->count(), 'عضويّتان لعميلين مختلفين');

        // وبعد التفعيل، نطاقُه = العميلان اللذان دُعي إليهما فقط (لا ثالث)
        $this->activateInvitee();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], hub_client_ids($u->fresh()),
            'نطاقُ العضو = العملاءُ المدعوُّ إليهم فقط');
    }

    /* ────────── ٣) منحُ Owner يتطلّب تصعيداً؛ دورٌ أدنى لا ────────── */

    public function test_granting_client_owner_requires_stepup_but_a_lower_role_does_not(): void
    {
        $this->seedCore();
        $a = $this->client();

        // عضوٌ قائمٌ بدور Finance (دعوةٌ بلا تصعيد)
        $this->actingAs($this->owner)->post(route('clients.members.invite', $a->id),
            ['email' => 'zamil@client.test', 'name' => 'زميل', 'role' => 'finance'])->assertRedirect();
        $u = User::where('email', 'zamil@client.test')->first();
        $m = ClientMembership::where('client_id', $a->id)->where('user_id', $u->id)->first();

        // رفعُه إلى Lead (دورٌ أدنى) بلا تصعيدٍ — يمرّ
        $this->actingAs($this->owner)->post(route('clients.members.role', [$a->id, $m->id]), ['role' => 'lead'])
            ->assertRedirect();
        $this->assertSame('lead', $m->fresh()->role, 'رفعٌ لدورٍ أدنى لا يتطلّب تصعيداً');

        // رفعُه إلى Owner بلا تصعيدٍ — يُحوَّل إلى شاشة التصعيد، ولا يتغيّر الدور
        $this->actingAs($this->owner)->post(route('clients.members.role', [$a->id, $m->id]), ['role' => 'owner'])
            ->assertRedirectContains('/stepup');
        $this->assertSame('lead', $m->fresh()->role, 'منحُ Owner بلا تصعيدٍ يجب ألّا يمرّ');

        // بعد ختم التصعيد — يمرّ ويصير Owner
        $this->actingAs($this->owner);
        $this->stampStepUp();
        $this->post(route('clients.members.role', [$a->id, $m->id]), ['role' => 'owner'])->assertRedirect();
        $this->assertSame('owner', $m->fresh()->role, 'منحُ Owner بعد التصعيد يمرّ');
    }

    /* ────────── ٤) سحبُ الوصول: تصعيدٌ + إسقاطٌ فوريّ من النطاق + أثرُ تدقيق ────────── */

    public function test_revoking_access_requires_stepup_drops_scope_and_is_audited(): void
    {
        $this->seedCore();
        $a = $this->client();

        // عضوٌ فعّالٌ (دعوة ثم تفعيل) — نطاقُه يشمل «ألف»
        $this->actingAs($this->owner)->post(route('clients.members.invite', $a->id),
            ['email' => 'zamil@client.test', 'name' => 'زميل', 'role' => 'viewer'])->assertRedirect();
        $u = User::where('email', 'zamil@client.test')->first();
        $this->activateInvitee();
        $m = ClientMembership::where('client_id', $a->id)->where('user_id', $u->id)->first();
        $this->assertSame([$a->id], hub_client_ids($u->fresh()), 'العضوُ الفعّالُ يرى عميلَه');

        // سحبٌ بلا تصعيدٍ — يُحوَّل للتصعيد، والعضويّةُ تبقى فعّالة
        $this->actingAs($this->owner)->post(route('clients.members.revoke', [$a->id, $m->id]))
            ->assertRedirectContains('/stepup');
        $this->assertSame('active', $m->fresh()->status, 'السحبُ بلا تصعيدٍ يجب ألّا يمرّ');

        // بعد التصعيد — يُسحَب: الحالةُ «معلّق»، ويسقط العميلُ من نطاقه فوراً
        $this->actingAs($this->owner);
        $this->stampStepUp();
        $this->post(route('clients.members.revoke', [$a->id, $m->id]))->assertRedirect();
        $this->assertSame('suspended', $m->fresh()->status, 'السحبُ يعلّق العضويّة');
        $this->assertNull(hub_client_ids($u->fresh()),
            'العضوُ المسحوبُ لا يرى العميلَ بعد الآن (سقط من النطاق فوراً)');

        // ودخل سلسلةَ التدقيق
        $this->assertTrue(
            DB::table('audits')->where('action', 'سحب عضويّة عميل')->where('record_id', $a->id)->exists(),
            'سحبُ العضويّة يجب أن يُدخِل قيدَ تدقيقٍ على العميل');
    }

    /* ────────── ٥) داخليٌّ للقراءة فقط لا يدعو/يمنح/يسحب ────────── */

    public function test_a_read_only_internal_user_cannot_manage_memberships(): void
    {
        $this->seedCore();
        $a = $this->client();

        // عضوٌ قائمٌ ليُختبَر عليه المنعُ من المنح/السحب
        $this->actingAs($this->owner)->post(route('clients.members.invite', $a->id),
            ['email' => 'zamil@client.test', 'name' => 'زميل', 'role' => 'viewer'])->assertRedirect();
        $u = User::where('email', 'zamil@client.test')->first();
        $m = ClientMembership::where('client_id', $a->id)->where('user_id', $u->id)->first();

        // المشاهدُ الداخليّ (clients: v فقط، بلا e) — يرى الصفحة لكن لا يُدير
        $this->actingAs($this->viewer)->get(route('m.show', ['clients', $a->id]))->assertOk();

        $this->actingAs($this->viewer)->post(route('clients.members.invite', $a->id),
            ['email' => 'other@client.test', 'name' => 'دخيل', 'role' => 'viewer'])->assertForbidden();
        $this->actingAs($this->viewer)->post(route('clients.members.role', [$a->id, $m->id]), ['role' => 'lead'])
            ->assertForbidden();
        $this->actingAs($this->viewer)->post(route('clients.members.revoke', [$a->id, $m->id]))->assertForbidden();

        // ولم يُنشأ الدخيلُ ولم تتغيّر العضويّة
        $this->assertNull(User::where('email', 'other@client.test')->first(), 'المشاهدُ أنشأ حساباً');
        $this->assertSame('viewer', $m->fresh()->role);
        $this->assertSame('invited', $m->fresh()->status);
    }

    /* ────────── ٦) عضوُ Client Finance: فواتيرُه نعم، تكلفةٌ/هامشٌ لا (field-mode + audience) ────────── */

    public function test_a_client_finance_member_sees_invoices_but_no_internal_cost_or_margin(): void
    {
        $this->seedCore();
        $a = $this->client();

        // عضوٌ فعّالٌ بدور Finance عبر الدعوةِ والتفعيل
        $this->actingAs($this->owner)->post(route('clients.members.invite', $a->id),
            ['email' => 'finance@client.test', 'name' => 'ماليّةُ العميل', 'role' => 'finance'])->assertRedirect();
        $u = User::where('email', 'finance@client.test')->first();
        $this->activateInvitee2('finance@client.test');

        // فاتورةُ مبيعاتٍ ظاهرة، ومشروعٌ بتكلفةٍ وميزانيّةٍ داخليّتين لا يُعرَضان قط
        FinDocument::create(['doc_no' => 'INV-F1', 'kind' => 'فاتورة مبيعات',
            'client_id' => $a->id, 'total' => 5400, 'state' => 'مرسلة']);
        Project::create(['name' => 'مشروعُ الماليّة', 'client_id' => $a->id, 'status' => 'نشط',
            'cost' => 313131, 'budget' => 424242]);

        $res = $this->actingAs($u)->get(route('portal.invoices'))->assertOk();
        $res->assertSee('INV-F1');                 // فاتورتُه ظاهرة
        $res->assertSee('5,400');                  // إجماليُّ فاتورته ظاهر (منسَّقاً)
        $res->assertDontSee('313131');             // تكلفةٌ داخليّة — محجوبة
        $res->assertDontSee('424242');             // ميزانيّةٌ داخليّة — محجوبة
    }

    /** تفعيلٌ لبريدٍ بعينه (نسخةٌ للاختبار ٦ حيث البريد مختلف) */
    private function activateInvitee2(string $email): void
    {
        $text = $this->lastActivationText();
        preg_match('#/activate/([A-Za-z0-9]+)#', $text, $tm);
        preg_match('/\b(\d{6})\b/u', $text, $om);
        $this->post("/activate/{$tm[1]}/otp", ['otp' => $om[1]])->assertRedirect();
        $this->post("/activate/{$tm[1]}/set",
            ['password' => self::INVITEE_PW, 'password_confirmation' => self::INVITEE_PW]);
    }

    /* ────────── ٧) عزلٌ عبر البوّابة: حسابُ عميلٍ لا يبلغ لوحةَ العضويّة الداخلية ────────── */

    public function test_a_client_account_cannot_reach_the_internal_membership_routes(): void
    {
        $this->seedCore();
        $a = $this->client();

        // عضوٌ عميلٌ فعّال — لا يبلغ إدارةَ العضويّة الداخلية (٤٠٤ عبر PortalGuard)
        $this->actingAs($this->owner)->post(route('clients.members.invite', $a->id),
            ['email' => 'zamil@client.test', 'name' => 'زميل', 'role' => 'viewer'])->assertRedirect();
        $u = User::where('email', 'zamil@client.test')->first();
        $this->activateInvitee();
        $m = ClientMembership::where('client_id', $a->id)->where('user_id', $u->id)->first();

        $this->actingAs($u->fresh())->post(route('clients.members.invite', $a->id),
            ['email' => 'x@client.test', 'name' => 'x', 'role' => 'viewer'])->assertNotFound();
        $this->actingAs($u->fresh())->post(route('clients.members.role', [$a->id, $m->id]), ['role' => 'owner'])
            ->assertNotFound();
        $this->actingAs($u->fresh())->post(route('clients.members.revoke', [$a->id, $m->id]))->assertNotFound();
    }
}
