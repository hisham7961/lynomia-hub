<?php

namespace Tests\Feature\Mobile;

use App\Models\HubNotification;
use App\Models\PushDelivery;
use App\Models\PushToken;
use App\Support\Mobile\PushService;
use App\Support\Platform\Settings;
use App\Support\Push\FcmServiceAccount;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **اعتمادُ FCM بحساب الخدمة** — رمزُ OAuth يُسكّ خادميّاً (JWT RS256) ويُخبَّأ حتى قبيل انتهائه،
 * والرمزُ الثابتُ احتياط؛ وحمولةُ الشارة والقناة (إضافيّ). بلا شبكةٍ حقيقيّة (`Http::fake`).
 */
class MobileFcmServiceAccountTest extends TestCase
{
    private string $privatePem = '';
    private string $publicPem = '';

    private function serviceAccount(): string
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($k, $this->privatePem);
        $this->publicPem = (string) openssl_pkey_get_details($k)['key'];

        return (string) json_encode(['type' => 'service_account', 'project_id' => 'lynomia-hub',
            'private_key_id' => 'kid-123', 'private_key' => $this->privatePem,
            'client_email' => 'push@lynomia-hub.iam.gserviceaccount.com']);
    }

    private function arm(?string $static = null): void
    {
        $this->seedCore();
        Settings::put('mobile.push_driver', 'fcm', 'cli');
        Settings::put('mobile.push_fcm_service_account', $this->serviceAccount(), 'cli');
        if ($static) Settings::put('mobile.push_fcm_access_token', $static, 'cli');
        PushToken::create(['installation_id' => (string) Str::uuid(), 'user_id' => $this->owner->id,
            'platform' => 'ios', 'provider' => 'fcm', 'token' => 'device-tok', 'last_confirmed_at' => now()]);
    }

    private function notify(): HubNotification
    {
        return HubNotification::create(['user_id' => $this->owner->id, 'kind' => 'assign', 'text' => 'سرٌّ داخليّ 998877',
            'read' => false, 'created_at' => now()]);
    }

    public function test_token_is_minted_from_service_account_cached_and_payload_carries_badge_and_channel(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.minted', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/lynomia-hub/messages/1']),
        ]);
        $this->arm();
        $this->assertTrue(PushService::status()['has_service_account']);

        $n1 = $this->notify();
        $n2 = $this->notify();

        $this->assertSame(2, PushDelivery::where('status', 'delivered')->count());
        $oauth = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'oauth2.googleapis.com'));
        $this->assertCount(1, $oauth, 'الرمزُ مخبوءٌ — لا سكَّ لكلِّ إرسال');

        // الـJWT موقَّعٌ RS256 بمفتاح الحساب وبالنطاق والجمهور الصحيحين
        [$req] = $oauth->values()->first();
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $req['grant_type']);
        [$h, $c, $sig] = explode('.', $req['assertion']);
        $dec = fn ($s) => base64_decode(strtr($s, '-_', '+/'));
        $this->assertSame(1, openssl_verify($h . '.' . $c, $dec($sig), $this->publicPem, OPENSSL_ALGO_SHA256));
        $claims = json_decode($dec($c), true);
        $this->assertSame(FcmServiceAccount::SCOPE, $claims['scope']);
        $this->assertSame(FcmServiceAccount::TOKEN_URL, $claims['aud']);
        $this->assertSame('push@lynomia-hub.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('RS256', json_decode($dec($h), true)['alg']);

        $sends = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'fcm.googleapis.com'));
        $this->assertCount(2, $sends);
        [$send] = $sends->values()->last();
        $this->assertSame('Bearer ya29.minted', $send->header('Authorization')[0]);
        $this->assertStringContainsString('/projects/lynomia-hub/messages:send', $send->url(), 'المشروعُ من ملفّ الحساب');
        $msg = $send['message'];
        $this->assertSame(2, $msg['apns']['payload']['aps']['badge'], 'الشارةُ = غيرُ المقروء');
        $this->assertSame('lynomia_default', $msg['android']['notification']['channel_id']);
        $this->assertSame(PushService::GENERIC_BODY, $msg['notification']['body']);
        $this->assertStringNotContainsString('998877', json_encode($msg, JSON_UNESCAPED_UNICODE), 'لا نصَّ خامّاً');
    }

    public function test_failed_exchange_without_fallback_is_an_honest_failure_without_secrets(): void
    {
        Http::preventStrayRequests();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        Log::spy();
        $this->arm();

        $this->notify();

        $this->assertSame('failed', PushDelivery::orderBy('id')->value('status'));
        $this->assertSame('auth_failed', PushDelivery::orderBy('id')->value('error_category'));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'fcm.googleapis.com'));
        Log::shouldNotHaveReceived('error');
        foreach (['audits', 'push_deliveries', 'setting_changes'] as $t) {
            $this->assertStringNotContainsString('PRIVATE KEY', (string) json_encode(DB::table($t)->get()),
                "السرُّ تسرّب إلى {$t}");
        }
    }

    public function test_static_token_remains_a_fallback(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([], 500),
            'fcm.googleapis.com/*' => Http::response([]),
        ]);
        $this->arm('static-fallback-token');

        $this->notify();

        $this->assertSame('delivered', PushDelivery::orderBy('id')->value('status'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'fcm.googleapis.com')
            && $r->header('Authorization')[0] === 'Bearer static-fallback-token');
    }

    public function test_editor_validates_and_never_echoes_the_service_account(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);

        $this->post('/admin/mobile-platform/settings/push', ['push_fcm_service_account' => '{"client_email":"x@y.z"}'])
            ->assertSessionHasErrors(['push_fcm_service_account'], null, 'mobileSettings');
        $this->assertNull(session()->getOldInput('push_fcm_service_account'));
        $this->assertNull(DB::table('settings')->where('key', 'mobile.push_fcm_service_account')->value('value'));

        $sa = $this->serviceAccount();
        $this->post('/admin/mobile-platform/settings/push', ['push_driver' => 'fcm', 'push_fcm_service_account' => $sa])
            ->assertSessionHas('ok');
        $this->assertStringContainsString('enc:', (string) DB::table('settings')->where('key', 'mobile.push_fcm_service_account')->value('value'));
        $this->assertTrue(PushService::status()['configured'], 'المشروعُ من الملفّ + الحسابُ ⇒ مُهيّأ');

        $html = $this->get('/admin/mobile-platform?tab=push')->assertOk()->getContent();
        $this->assertStringNotContainsString('PRIVATE KEY', $html);
        $this->assertStringNotContainsString('push@lynomia-hub', $html);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) json_encode(DB::table('audits')->get()));
    }

    /**
     * (مراجعة) **الفشلُ يُذكر**: التفريعُ متزامنٌ لكلِّ جهاز، فكان رمزُ OAuth الفاشلُ يُطلب من جديد لكلِّ جهاز
     * (DNS + فحصٌ صادر + مهلةُ ٨ ثوانٍ) — مستخدمٌ بخمسة أجهزةٍ يؤخّر الطلبَ أربعين ثانية ويُمطر خادمَ Google.
     */
    public function test_a_failed_token_exchange_is_not_retried_per_device(): void
    {
        Http::preventStrayRequests();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->arm();
        foreach (range(1, 3) as $i) {
            PushToken::create(['installation_id' => (string) Str::uuid(), 'user_id' => $this->owner->id,
                'platform' => 'android', 'provider' => 'fcm', 'token' => 'device-tok-' . $i, 'last_confirmed_at' => now()]);
        }

        $this->notify();

        $this->assertSame(4, PushDelivery::where('status', 'failed')->count(), 'كلُّ جهازٍ يُسجَّل فشلُه بصدق');
        Http::assertSentCount(1);
    }

    /** (مراجعة) معرّفُ المشروع من ملفّ حساب الخدمة يمرّ بنمط المحرّر نفسِه قبل أن يدخل مسارَ عنوان FCM */
    public function test_project_id_from_service_account_is_validated(): void
    {
        $this->seedCore();
        $sa = json_decode($this->serviceAccount(), true);
        $sa['project_id'] = 'x/../../evil';
        $this->assertNull(\App\Support\Push\FcmServiceAccount::parse((string) json_encode($sa)),
            'حسابُ خدمةٍ بمعرّف مشروعٍ غير صالح قُبل');
    }
}
