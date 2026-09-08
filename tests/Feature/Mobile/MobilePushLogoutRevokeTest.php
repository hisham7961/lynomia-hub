<?php

namespace Tests\Feature\Mobile;

use App\Models\PushDelivery;
use App\Models\PushToken;
use App\Support\Push\PushProvider;
use App\Support\PushService;
use Illuminate\Support\Str;
use Tests\Support\FakePushProvider;
use Tests\TestCase;

/**
 * **الخروجُ يعطّل الدفع** — Mobile Readiness · الطور E (يُفعّل حارسَ الطور B).
 *
 * منذ الطور B يُبطِل `MobileAuthController::logout`/`logoutAll` رموزَ الدفع، لكنّه
 * محروسٌ بوجود جدول `push_tokens` (لم يكن قد أُنشئ). هذه الدفعةُ تُنشئ الجدولَ —
 * فيصبح الحارسُ فاعلاً: الخروجُ يوقف تسليمَ الدفع فعلاً (spec §Push «logout/revoke
 * disables delivery»).
 */
class MobilePushLogoutRevokeTest extends TestCase
{
    use InteractsWithMobileAuth;

    public function test_logout_all_revokes_push_tokens_and_stops_delivery(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner);

        // رمزُ دفعٍ حيٌّ مربوطٌ بمستخدمِ الجلسة وتنصيبها
        $tok = PushToken::create([
            'installation_id'   => $data['installation_id'],
            'user_id'           => $this->owner->id,
            'platform'          => 'ios',
            'provider'          => 'fcm',
            'token'             => 'live-token',
            'last_confirmed_at' => now(),
        ]);
        $this->assertNull($tok->revoked_at);

        // خروجٌ شامل ⇒ يُبطِل رموزَ دفعِ المستخدم (حارسُ B فاعلٌ الآن)
        $this->postJson('/api/mobile/v1/auth/logout-all', [], $this->bearer($data['access_token']))
            ->assertOk();

        $this->assertNotNull($tok->fresh()->revoked_at, 'الخروجُ الشاملُ يُبطِل رمزَ الدفع');

        // والتفريعُ لا يُسلَّم عبر الرمز المُبطَل
        $fake = new FakePushProvider('deliver');
        app()->instance(PushProvider::class, $fake);
        $n = \App\Models\HubNotification::create([
            'user_id' => $this->owner->id, 'kind' => 'assign', 'text' => 'ن',
            'read' => false, 'created_at' => now(),
        ]);
        PushService::fanout($n);

        $this->assertCount(0, $fake->sent, 'رمزٌ مُبطَلٌ بالخروج لا يتلقّى تسليماً');
        $this->assertSame(0, PushDelivery::where('notification_id', $n->id)->count());
    }

    public function test_logout_revokes_only_this_installations_tokens(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner);

        $mine = PushToken::create([
            'installation_id' => $data['installation_id'], 'user_id' => $this->owner->id,
            'platform' => 'ios', 'provider' => 'fcm', 'token' => 'this-install', 'last_confirmed_at' => now(),
        ]);
        // رمزٌ لتنصيبٍ آخرَ لنفس المستخدم — لا يمسّه خروجُ هذا التنصيب
        $other = PushToken::create([
            'installation_id' => (string) Str::uuid(), 'user_id' => $this->owner->id,
            'platform' => 'android', 'provider' => 'fcm', 'token' => 'other-install', 'last_confirmed_at' => now(),
        ]);

        $this->postJson('/api/mobile/v1/auth/logout', [], $this->bearer($data['access_token']))->assertOk();

        $this->assertNotNull($mine->fresh()->revoked_at, 'خروجُ التنصيب يُبطِل رمزَه');
        $this->assertNull($other->fresh()->revoked_at, 'ولا يمسّ رمزَ تنصيبٍ آخر (خروجُ جهازٍ لا كلّ الأجهزة)');
    }
}
