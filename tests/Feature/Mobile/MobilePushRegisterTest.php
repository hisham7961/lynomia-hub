<?php

namespace Tests\Feature\Mobile;

use App\Models\HubNotification;
use App\Models\PushDelivery;
use App\Models\PushToken;
use App\Models\User;
use Tests\TestCase;

/**
 * **E.5/E.7 · تسجيلُ دفعِ الجوال وإدارتُه (HTTP حيّاً)** — Mobile Readiness · الطور E.
 *
 * يفحص العقدَ كما يراه العميلُ الأصيل على `/api/mobile/v1/push/*`:
 *  • **التسجيل:** يُنشئ رمزاً منسوباً إلى (مستخدمِ الجلسة + تنصيبِ جلسته لا العميل)،
 *    ولا يُعيد نصَّ الرمزِ قط، ويقول صدقَ التهيئة (`configured=false` بلا اعتماد).
 *  • **F7 · إزالةُ التكرار عابرةُ المستخدمين:** مستخدمٌ آخرُ يسجّل الرمزَ نفسَه ⇒ يُبطَل
 *    ربطُ الأوّل (فيتوقّف تلقّيه).
 *  • **إلغاءُ التسجيل:** مقصورٌ على رموزي (رمزُ غيري ⇒ ٠ — لا IDOR ولا كشفَ وجود).
 *  • **الإدارة (للمالكِ وحدَه):** حالةٌ/اختبارٌ **صادقان** يقولان `not_configured` بلا
 *    مزوّد — لا نجاحٌ مُزيَّفٌ أبداً، ولا مفتاحٌ خاصّ.
 */
class MobilePushRegisterTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    // ═══════════════════════ التسجيل ═══════════════════════

    public function test_register_creates_a_token_bound_to_session_installation(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner, 'inst-push-reg-1');
        $h = $this->bearer($data['access_token']);

        $res = $this->withHeaders($h)->postJson('/api/mobile/v1/push/register', [
            'platform' => 'ios', 'provider' => 'fcm', 'token' => 'device-token-abc',
        ])->assertOk()
            ->assertJsonPath('data.registered', true)
            ->assertJsonPath('data.token.provider', 'fcm')
            ->assertJsonPath('data.token.installation_id', (string) $data['installation_id'])
            ->assertJsonPath('data.delivery.configured', false);   // صدقُ NOT_CONFIGURED بلا اعتماد

        // الصفُّ منسوبٌ لمستخدمِ الجلسة وتنصيبها — لا للعميل
        $this->assertDatabaseHas('push_tokens', [
            'user_id' => $this->owner->id, 'installation_id' => $data['installation_id'],
            'token' => 'device-token-abc', 'revoked_at' => null,
        ]);

        // نصُّ الرمزِ لا يُعاد قط (spec §Security «never log/return tokens»)
        $scalars = $this->allScalarsDeep($res->json('data'));
        $this->assertNotContains('device-token-abc', $scalars, 'نصُّ الرمزِ لا يظهر في الردّ أبداً');
    }

    public function test_reregister_same_provider_token_dedupes_to_one_active(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner, 'inst-push-dedupe-1');
        $h = $this->bearer($data['access_token']);
        $body = ['platform' => 'ios', 'provider' => 'fcm', 'token' => 'dup-token'];

        $this->withHeaders($h)->postJson('/api/mobile/v1/push/register', $body)->assertOk();
        $this->withHeaders($h)->postJson('/api/mobile/v1/push/register', $body)->assertOk();

        $this->assertSame(1, PushToken::where('token', 'dup-token')->count(), 'صفٌّ واحدٌ فقط (dedupe على provider,token)');
        $this->assertSame(1, PushToken::active()->where('token', 'dup-token')->count());
    }

    public function test_f7_another_user_registering_same_token_revokes_the_first_owner(): void
    {
        $this->seedCore();
        // المالكُ يسجّل الرمزَ من جلسته
        $ownerData = $this->mobileLogin($this->owner, 'inst-push-f7-owner');
        $this->withHeaders($this->bearer($ownerData['access_token']))
            ->postJson('/api/mobile/v1/push/register', ['platform' => 'ios', 'provider' => 'fcm', 'token' => 'shared-dev'])
            ->assertOk();

        // مستخدمٌ آخرُ (جهازٌ أُعيد توفيرُه) يسجّل الرمزَ نفسَه من جلسته
        $viewerData = $this->mobileLogin($this->viewer, 'inst-push-f7-viewer');
        $this->withHeaders($this->bearer($viewerData['access_token']))
            ->postJson('/api/mobile/v1/push/register', ['platform' => 'android', 'provider' => 'fcm', 'token' => 'shared-dev'])
            ->assertOk();

        // المالكُ السابقُ لم يعد يملك رمزاً حيّاً به — توقّف تلقّيه (F7)
        $this->assertSame(0, PushToken::active()->where('user_id', $this->owner->id)->where('token', 'shared-dev')->count());
        // صفٌّ حيٌّ واحدٌ للمالكِ الحاليّ فقط
        $this->assertSame(1, PushToken::active()->where('token', 'shared-dev')->count());
        $this->assertSame($this->viewer->id, PushToken::active()->where('token', 'shared-dev')->value('user_id'));
    }

    public function test_register_validation_rejects_bad_platform_provider_or_missing_token(): void
    {
        $this->seedCore();
        $h = $this->bearer($this->mobileLogin($this->owner, 'inst-push-val-1')['access_token']);

        $this->withHeaders($h)->postJson('/api/mobile/v1/push/register', ['platform' => 'windows', 'token' => 't'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->withHeaders($h)->postJson('/api/mobile/v1/push/register', ['platform' => 'ios', 'provider' => 'bogus', 'token' => 't'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->withHeaders($h)->postJson('/api/mobile/v1/push/register', ['platform' => 'ios'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_register_requires_a_valid_mobile_access_token(): void
    {
        $this->seedCore();
        $this->postJson('/api/mobile/v1/push/register', ['platform' => 'ios', 'token' => 't'])
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    // ═══════════════════════ إلغاءُ التسجيل (لا IDOR) ═══════════════════════

    public function test_unregister_revokes_my_own_token(): void
    {
        $this->seedCore();
        $h = $this->bearer($this->mobileLogin($this->owner, 'inst-push-unreg-1')['access_token']);
        $this->withHeaders($h)->postJson('/api/mobile/v1/push/register',
            ['platform' => 'ios', 'provider' => 'fcm', 'token' => 'mine-tok'])->assertOk();

        $this->withHeaders($h)->postJson('/api/mobile/v1/push/unregister', ['provider' => 'fcm', 'token' => 'mine-tok'])
            ->assertOk()->assertJsonPath('data.revoked', 1);

        $this->assertSame(0, PushToken::active()->where('token', 'mine-tok')->count(), 'الرمزُ أُبطِل — يتوقّف التسليم');
    }

    public function test_unregister_another_users_token_returns_zero_and_does_not_touch_it(): void
    {
        $this->seedCore();
        // المالكُ يسجّل رمزاً
        $ownerH = $this->bearer($this->mobileLogin($this->owner, 'inst-push-idor-owner')['access_token']);
        $this->withHeaders($ownerH)->postJson('/api/mobile/v1/push/register',
            ['platform' => 'ios', 'provider' => 'fcm', 'token' => 'owner-tok'])->assertOk();

        // مستخدمٌ آخرُ يحاول إلغاءَه ⇒ ٠ (لا يُبطِل رمزَ غيره، ولا يكشف وجودَه)
        $viewerH = $this->bearer($this->mobileLogin($this->viewer, 'inst-push-idor-viewer')['access_token']);
        $this->withHeaders($viewerH)->postJson('/api/mobile/v1/push/unregister', ['provider' => 'fcm', 'token' => 'owner-tok'])
            ->assertOk()->assertJsonPath('data.revoked', 0);

        $this->assertSame(1, PushToken::active()->where('user_id', $this->owner->id)->where('token', 'owner-tok')->count(),
            'رمزُ المالكِ لم يُمَسّ (لا IDOR)');
    }

    // ═══════════════════════ E.7 · الإدارة (للمالكِ وحدَه · صادقةٌ بلا سرّ) ═══════════════════════

    public function test_admin_status_is_owner_only(): void
    {
        $this->seedCore();
        // موظفةٌ (ليست مالكاً) ⇒ ٤٠٣
        $empH = $this->bearer($this->mobileLogin($this->employee, 'inst-push-admin-emp')['access_token']);
        $this->withHeaders($empH)->getJson('/api/mobile/v1/push/admin/status')
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_admin_status_reports_not_configured_truthfully_without_credentials(): void
    {
        $this->seedCore();
        $h = $this->bearer($this->mobileLogin($this->owner, 'inst-push-admin-1')['access_token']);

        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/push/admin/status')->assertOk();
        $res->assertJsonPath('data.push.configured', false)     // صدقُ NOT_CONFIGURED
            ->assertJsonPath('data.push.has_access_token', false)
            ->assertJsonPath('data.push.has_project_id', false);

        // لا مفتاحٌ خاصٌّ في أيّ مكانٍ من الحمولة (حضورٌ لا قيمة)
        $scalars = $this->allScalarsDeep($res->json('data'));
        foreach ($scalars as $v) {
            $this->assertStringNotContainsString('PRIVATE', strtoupper((string) $v), 'لا مفتاحٌ خاصٌّ في الحالة');
        }
    }

    public function test_admin_test_is_truthful_never_fakes_delivered(): void
    {
        $this->seedCore();
        $h = $this->bearer($this->mobileLogin($this->owner, 'inst-push-admin-2')['access_token']);
        // رمزٌ حيٌّ للمالك — الاختبارُ يُجرَّب عليه لكنه يقول not_configured (لا مزوّد)
        $this->withHeaders($h)->postJson('/api/mobile/v1/push/register',
            ['platform' => 'ios', 'provider' => 'fcm', 'token' => 'admin-tok'])->assertOk();

        $res = $this->withHeaders($h)->postJson('/api/mobile/v1/push/admin/test')->assertOk();
        $res->assertJsonPath('data.overall', 'not_configured')   // لا نجاحٌ مُزيَّف
            ->assertJsonPath('data.configured', false);

        foreach ($res->json('data.results') as $row) {
            $this->assertNotSame('delivered', $row['status'], 'المزوّدُ الصفريُّ لا يُزيّف delivered أبداً');
            $this->assertSame('not_configured', $row['status']);
        }
    }

    public function test_admin_test_is_owner_only(): void
    {
        $this->seedCore();
        $empH = $this->bearer($this->mobileLogin($this->viewer, 'inst-push-admin-emp2')['access_token']);
        $this->withHeaders($empH)->postJson('/api/mobile/v1/push/admin/test')
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    // ═══════════════════════ E2E · إشعارٌ لمستخدمٍ ذي رمزٍ يُسجّل محاولةَ تسليم ═══════════════════════

    public function test_notification_to_a_user_with_a_token_records_a_delivery_attempt_truthfully(): void
    {
        $this->seedCore();
        // تسجيلٌ حيٌّ عبر HTTP (المزوّدُ الافتراضيُّ الصفريُّ — لا اعتماد)
        $h = $this->bearer($this->mobileLogin($this->owner, 'inst-push-e2e-1')['access_token']);
        $this->withHeaders($h)->postJson('/api/mobile/v1/push/register',
            ['platform' => 'ios', 'provider' => 'fcm', 'token' => 'e2e-tok'])->assertOk();

        // إشعارٌ داخليٌّ ⇒ hook «created» يفرّع ⇒ صفُّ تسليمٍ يقول الحقيقةَ (not_configured)
        $n = HubNotification::create([
            'user_id' => $this->owner->id, 'kind' => 'assign', 'text' => 'نصٌّ حسّاسٌ لا يُدفَع',
            'read' => false, 'created_at' => now(),
        ]);

        $row = PushDelivery::where('notification_id', $n->id)->first();
        $this->assertNotNull($row, 'محاولةُ تسليمٍ مسجَّلةٌ لمستخدمٍ ذي رمزٍ حيّ');
        $this->assertSame('not_configured', $row->status, 'المزوّدُ الصفريُّ صادقٌ — لا delivered مُزيَّف');
        $this->assertSame(0, PushDelivery::where('status', 'delivered')->count());
        $this->assertTrue(HubNotification::whereKey($n->id)->exists(), 'الإشعارُ الداخليُّ باقٍ');
    }
}
