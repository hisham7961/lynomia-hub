<?php

namespace Tests\Feature\Mobile;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\OutboxMessage;
use App\Models\User;
use Tests\TestCase;

/**
 * **إدارةُ أعضاء العميل على الجوال** (§15) — لوحةُ المدير الداخليّ عبر الجوهر
 * المشترك (`ClientMembers`): دعوةٌ على سكّة B.1 (لا كلمةَ سرٍّ تُرسَل)، منحُ
 * Owner وسحبُ الوصول خلف **تصعيد الجوال** المربوط بالغرض، صلاحيةُ `clients:e`
 * ونطاقُ المدير، وحسابُ العميل نفسُه محجوبٌ بالسياج (404).
 */
class MobileClientMembersTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    private Client $clientA;

    private function seedWorld(): void
    {
        $this->seedCore();
        $this->clientA = Client::create(['name' => 'عميل الإدارة']);
    }

    private function managerHeaders(): array
    {
        $data = $this->mobileLogin($this->employee, 'inst-mgr-11111111');

        return $this->bearer($data['access_token']);
    }

    /** تصعيدُ الجوال بالغرض المطلوب — كلمةُ المرور اعتماداً (لا TOTP للموظفة) */
    private function stepUp(array $h, string $purpose): void
    {
        $this->withHeaders($h)->postJson('/api/mobile/v1/auth/step-up', [
            'purpose' => $purpose, 'credential' => 'Secret!2026x',
        ])->assertOk();
    }

    public function test_invite_creates_passwordless_client_user_membership_and_activation_message(): void
    {
        $this->seedWorld();
        $h = $this->managerHeaders();

        $res = $this->withHeaders($h)->postJson(
            '/api/mobile/v1/clients/' . $this->clientA->id . '/members',
            ['email' => 'member@ext.local', 'name' => 'عضو جديد', 'role' => 'finance']);
        $res->assertOk();
        $this->assertSame('invited', $res->json('data.member.status'));
        $this->assertSame('finance', $res->json('data.member.role'));
        $this->assertFalse($res->json('data.member.user.activated'));

        $u = User::where('email', 'member@ext.local')->first();
        $this->assertNotNull($u);
        $this->assertSame('client', $u->account_type, 'الحدُّ الصلب يُفرض لا يُمرَّر');
        $this->assertNull($u->password_changed_at, 'بلا كلمةِ سرٍّ صالحة حتى يضعها بنفسه');

        // رسالةُ التفعيل تحمل رابطاً ورمزاً — **لا كلمةَ سرٍّ أبداً**
        $text = (string) OutboxMessage::where('kind', 'account_activation')
            ->where('target', 'member@ext.local')->value('text');
        $this->assertStringContainsString('activate/', $text);
        $this->assertMatchesRegularExpression('/\b\d{6}\b/u', $text);

        // القائمةُ تعدّه
        $list = $this->withHeaders($h)->getJson(
            '/api/mobile/v1/clients/' . $this->clientA->id . '/members');
        $list->assertOk();
        $this->assertSame(['member@ext.local'],
            collect($list->json('data.members'))->pluck('user.email')->all());
    }

    public function test_owner_grant_and_revoke_require_purpose_bound_mobile_step_up(): void
    {
        $this->seedWorld();
        $h = $this->managerHeaders();

        // منحُ Owner بلا تصعيد ⇒ 428 بغرضه المسمّى
        $res = $this->withHeaders($h)->postJson(
            '/api/mobile/v1/clients/' . $this->clientA->id . '/members',
            ['email' => 'boss@ext.local', 'role' => 'owner']);
        $res->assertStatus(428);
        $this->assertSame('STEP_UP_REQUIRED', $res->json('code'));
        $this->assertSame('action:clients:member_owner', $res->json('details.purpose'));

        // بعد التصعيد بالغرض نفسِه — يمرّ
        $this->stepUp($h, 'action:clients:member_owner');
        $ok = $this->withHeaders($h)->postJson(
            '/api/mobile/v1/clients/' . $this->clientA->id . '/members',
            ['email' => 'boss@ext.local', 'role' => 'owner']);
        $ok->assertOk();
        $membershipId = $ok->json('data.member.id');

        // السحبُ بلا تصعيدٍ ⇒ 428 دائماً؛ وغرضُ owner لا يفتح غرضَ السحب (مِنحةٌ مربوطة)
        $rev = $this->withHeaders($h)->deleteJson(
            '/api/mobile/v1/clients/' . $this->clientA->id . '/members/' . $membershipId);
        $rev->assertStatus(428);
        $this->assertSame('action:clients:member_revoke', $rev->json('details.purpose'));

        $this->stepUp($h, 'action:clients:member_revoke');
        $done = $this->withHeaders($h)->deleteJson(
            '/api/mobile/v1/clients/' . $this->clientA->id . '/members/' . $membershipId);
        $done->assertOk();
        $this->assertSame('suspended', $done->json('data.member.status'));

        // «المعلَّقُ» يسقط عن نطاق العضويّة فوراً (hub_client_ids يعدّ الفعّالَ وحدَه)
        $bossUser = User::where('email', 'boss@ext.local')->first();
        $this->assertSame([], hub_client_ids($bossUser) ?? []);
    }

    public function test_set_role_below_owner_needs_no_step_up(): void
    {
        $this->seedWorld();
        $h = $this->managerHeaders();

        $made = $this->withHeaders($h)->postJson(
            '/api/mobile/v1/clients/' . $this->clientA->id . '/members',
            ['email' => 'viewer@ext.local', 'role' => 'viewer']);
        $made->assertOk();

        $res = $this->withHeaders($h)->putJson(
            '/api/mobile/v1/clients/' . $this->clientA->id . '/members/' . $made->json('data.member.id'),
            ['role' => 'technical']);
        $res->assertOk();
        $this->assertSame('technical', $res->json('data.member.role'));
    }

    public function test_internal_email_is_rejected_and_guards_hold(): void
    {
        $this->seedWorld();
        $h = $this->managerHeaders();

        // بريدُ حسابٍ داخليّ لا يُدعى عضوَ عميل
        $this->withHeaders($h)->postJson(
            '/api/mobile/v1/clients/' . $this->clientA->id . '/members',
            ['email' => $this->owner->email, 'role' => 'viewer'])
            ->assertStatus(422);

        // «مشاهد» بلا clients:e ⇒ 403
        $viewer = $this->mobileLogin($this->viewer, 'inst-view-11111111');
        $this->withHeaders($this->bearer($viewer['access_token']))->getJson(
            '/api/mobile/v1/clients/' . $this->clientA->id . '/members')
            ->assertStatus(403);

        // مديرٌ محصورٌ بشركةٍ لا يبلغ عميلَ شركةٍ أخرى — 404 لا كشف
        $coA = \App\Models\Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = \App\Models\Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $clientB = Client::create(['name' => 'عميل باء', 'company_id' => $coB->id]);
        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();
        $this->withHeaders($this->managerHeaders())->getJson(
            '/api/mobile/v1/clients/' . $clientB->id . '/members')
            ->assertStatus(404);
    }

    public function test_client_account_cannot_reach_member_management_even_as_own_org(): void
    {
        $this->seedWorld();
        $clientUser = User::create([
            'name' => 'مالكة العميل', 'email' => 'cowner@ext.local',
            'password' => 'Secret!2026x', 'status' => 'نشط',
            'password_changed_at' => now(), 'account_type' => 'client',
        ]);
        ClientMembership::create([
            'client_id' => $this->clientA->id, 'user_id' => $clientUser->id,
            'role' => 'owner', 'status' => 'active', 'activated_at' => now(),
        ]);

        $data = $this->mobileLogin($clientUser, 'inst-cowner-1111111');
        // العقدُ الحاليُّ لا يمنح العميلَ إدارةَ أعضائه — السياجُ 404 (منعٌ فوق المصفوفة)
        $this->withHeaders($this->bearer($data['access_token']))->getJson(
            '/api/mobile/v1/clients/' . $this->clientA->id . '/members')
            ->assertStatus(404);
    }
}
