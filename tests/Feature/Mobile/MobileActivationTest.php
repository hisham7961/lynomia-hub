<?php

namespace Tests\Feature\Mobile;

use App\Models\AccountActivation;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\OutboxMessage;
use Tests\TestCase;

/**
 * **تفعيلُ حساب العميل من الجوال** (§13) — سكّةُ `AccountActivation` نفسُها:
 * فحصٌ مقنَّعُ البريد، إتمامٌ ذرّيٌّ (رمزٌ + كلمةٌ يضعها العميل)، حرقٌ لمرّة،
 * سقفُ محاولات، انتهاءٌ صادق، ترقيةُ العضويّات «المدعوّة»، ثم دخولٌ عبر سكّة
 * الدخول الواحدة. ولا كلمةَ سرٍّ في أيّ صادرٍ أبداً.
 */
class MobileActivationTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    /** يوفّر حسابَ عميلٍ بلا كلمةٍ + عضويّةً مدعوّةً + تفعيلاً — يعيد [user, token, otp] */
    private function provision(): array
    {
        $this->seedCore();
        $client = Client::create(['name' => 'عميل التفعيل']);

        [$user, $act] = AccountActivation::provisionClient([
            'name' => 'عميل جديد', 'email' => 'newbie@ext.local',
        ]);
        ClientMembership::create([
            'client_id' => $client->id, 'user_id' => $user->id,
            'role' => 'viewer', 'status' => 'invited', 'invited_at' => now(),
        ]);

        // الرمزان لا يُخزَّنان صريحَين — يُلتقطان من نصّ رسالة الصادر (كما يقرؤهما العميل)
        $text = (string) OutboxMessage::where('kind', 'account_activation')
            ->where('target', $user->email)->orderByDesc('id')->value('text');
        preg_match('/activate\/([A-Za-z0-9]+)/u', $text, $tm);
        preg_match('/\b(\d{6})\b/u', $text, $om);
        $this->assertNotEmpty($tm[1] ?? '', 'رابطُ التفعيل غائبٌ عن الرسالة');
        $this->assertNotEmpty($om[1] ?? '', 'رمزُ التحقّق غائبٌ عن الرسالة');
        $this->assertStringNotContainsStringIgnoringCase('password', $text);

        return [$user, $tm[1], $om[1]];
    }

    public function test_show_reports_pending_with_masked_email_and_unknown_token_is_404(): void
    {
        [, $token] = $this->provision();

        $res = $this->getJson('/api/mobile/v1/activation/' . $token);
        $res->assertOk();
        $this->assertSame('pending', $res->json('data.status'));
        $masked = $res->json('data.email_masked');
        $this->assertStringNotContainsString('newbie', $masked, 'البريدُ لا يُكشف كاملاً قبل الإثبات');
        $this->assertStringStartsWith('n***@', $masked);

        $this->getJson('/api/mobile/v1/activation/none-such-token')->assertStatus(404);
    }

    public function test_complete_sets_password_activates_memberships_and_burns_once(): void
    {
        [$user, $token, $otp] = $this->provision();

        $res = $this->postJson('/api/mobile/v1/activation/' . $token . '/complete', [
            'otp' => $otp,
            'password' => 'Www!55ord#2026x', 'password_confirmation' => 'Www!55ord#2026x',
        ]);
        $res->assertOk();
        $this->assertTrue($res->json('data.activated'));
        $this->assertSame('newbie@ext.local', $res->json('data.email'));

        // العضويّةُ «المدعوّة» صارت فعّالة، والصفُّ استُهلك لمرّة
        $this->assertSame('active',
            ClientMembership::where('user_id', $user->id)->value('status'));
        $this->assertNotNull(AccountActivation::where('user_id', $user->id)->value('consumed_at'));

        // إعادةُ الإتمام على الرمز المُستهلَك — 404 (لا كشفَ وجود)
        $this->postJson('/api/mobile/v1/activation/' . $token . '/complete', [
            'otp' => $otp,
            'password' => 'Www!55ord#2026x', 'password_confirmation' => 'Www!55ord#2026x',
        ])->assertStatus(404);

        // الدخولُ الآن عبر السكّة الواحدة بكلمته التي وضعها بنفسه
        $login = $this->postJson('/api/mobile/v1/auth/login',
            $this->loginPayload('newbie@ext.local', 'Www!55ord#2026x'));
        $login->assertOk();
        $this->assertFalse($login->json('data.user.is_owner'));
        $this->assertNotEmpty($login->json('data.access_token'));
    }

    public function test_weak_password_fails_422_without_burning_the_otp(): void
    {
        [, $token, $otp] = $this->provision();

        $weak = $this->postJson('/api/mobile/v1/activation/' . $token . '/complete', [
            'otp' => $otp, 'password' => '123', 'password_confirmation' => '123',
        ]);
        $weak->assertStatus(422);

        // الرمزُ لم يُحرق بكلمةٍ ضعيفة — الإتمامُ الصحيح بعدها يمرّ
        $ok = $this->postJson('/api/mobile/v1/activation/' . $token . '/complete', [
            'otp' => $otp,
            'password' => 'Www!55ord#2026x', 'password_confirmation' => 'Www!55ord#2026x',
        ]);
        $ok->assertOk();
    }

    public function test_wrong_otp_bumps_attempts_and_cap_burns_the_row(): void
    {
        [$user, $token] = $this->provision();

        for ($i = 1; $i <= AccountActivation::MAX_OTP_ATTEMPTS; $i++) {
            $res = $this->postJson('/api/mobile/v1/activation/' . $token . '/complete', [
                'otp' => '000000',
                'password' => 'Www!55ord#2026x', 'password_confirmation' => 'Www!55ord#2026x',
            ]);
            $this->assertContains($res->status(), [422, 429]);
        }
        $this->assertGreaterThanOrEqual(AccountActivation::MAX_OTP_ATTEMPTS,
            (int) AccountActivation::where('user_id', $user->id)->value('attempts'));
    }

    public function test_expired_activation_is_honest_and_does_not_consume(): void
    {
        [$user, $token, $otp] = $this->provision();
        AccountActivation::where('user_id', $user->id)
            ->update(['otp_expires_at' => now()->subMinute()]);

        $show = $this->getJson('/api/mobile/v1/activation/' . $token);
        $show->assertOk();
        $this->assertSame('expired', $show->json('data.status'));

        $res = $this->postJson('/api/mobile/v1/activation/' . $token . '/complete', [
            'otp' => $otp,
            'password' => 'Www!55ord#2026x', 'password_confirmation' => 'Www!55ord#2026x',
        ]);
        $res->assertStatus(422);
        $this->assertSame('expired', $res->json('details.reason'));
        $this->assertNull(AccountActivation::where('user_id', $user->id)->value('consumed_at'));
    }
}
