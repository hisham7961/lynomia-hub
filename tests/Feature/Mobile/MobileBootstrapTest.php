<?php

namespace Tests\Feature\Mobile;

use App\Models\HubNotification;
use Tests\TestCase;

/**
 * **C.2 · GET bootstrap** (ETag/304) — Mobile Readiness · الطور C · §109.
 *
 * لقطةُ إقلاعٍ باردةٍ مضغوطة: المستخدمُ/الدور/المالك، أعلامُ قدرةٍ (own-identity)،
 * الإصدارات، نسخةُ المخطّط، عدُّ غير المقروء (المُنادي وحدَه)، والتنقّلُ المُنطَّق.
 * تُبرهن: لا أسرار، عدُّ غير المقروء **للمُنادي فقط**، وبصمةٌ حقيقيّةٌ ⇒ 304 فارغة.
 */
class MobileBootstrapTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    public function test_bootstrap_returns_user_role_owner_versions_and_nav(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee, 'inst-boot-11111111');

        $res = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/bootstrap');
        $res->assertOk();

        $res->assertJsonPath('data.user.id', $this->employee->id)
            ->assertJsonPath('data.user.email', $this->employee->email)
            ->assertJsonPath('data.user.is_owner', false)
            ->assertJsonPath('data.user.role', $this->employee->role->name);

        // الإصدارات + نسخةُ المخطّط + الوقت + المنطقة حاضرة
        $this->assertSame((string) config('hub.version'), $res->json('data.versions.app'));
        $this->assertSame('1', $res->json('data.versions.mobile_api'));
        $this->assertNotEmpty($res->json('data.versions.schema'));
        $this->assertSame($res->json('data.versions.schema'), $res->json('data.schema_version'), 'نسخةُ المخطّط متطابقةٌ في الموضعين');
        $this->assertNotEmpty($res->json('data.server_time'));
        $this->assertNotEmpty($res->json('data.timezone'));

        // التنقّلُ مُنطَّقٌ سلفاً (مجموعاتٌ بمفاتيح g/icon/items) لا مصفوفةٌ فارغة
        $nav = $res->json('data.nav');
        $this->assertIsArray($nav);
        $this->assertNotEmpty($nav, 'الموظفُ يرى وحداتٍ ⇒ تنقّلٌ غيرُ فارغ');
        $this->assertArrayHasKey('g', $nav[0]);
        $this->assertArrayHasKey('items', $nav[0]);

        // أعلامُ القدرة موجودةٌ كمنطقيّات (own-identity)
        foreach (['can_approve', 'can_monitor', 'can_secrets', 'mfa_enrolled', 'restricted_company', 'restricted_client'] as $flag) {
            $this->assertIsBool($res->json("data.feature_flags.{$flag}"), "علمُ {$flag} منطقيّ");
        }
        // الموظفُ العاديّ ليس معتمِداً ولا مالكاً للأسرار
        $this->assertFalse($res->json('data.feature_flags.can_approve'));
        $this->assertFalse($res->json('data.feature_flags.can_secrets'));
    }

    public function test_owner_bootstrap_reflects_owner_capabilities(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner, 'inst-boot-owner-111');

        $res = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/bootstrap');
        $res->assertOk()
            ->assertJsonPath('data.user.is_owner', true)
            ->assertJsonPath('data.feature_flags.can_approve', true)
            ->assertJsonPath('data.feature_flags.can_secrets', true)
            ->assertJsonPath('data.feature_flags.can_monitor', true)
            ->assertJsonPath('data.feature_flags.restricted_company', false);
    }

    public function test_bootstrap_unread_count_is_scoped_to_the_caller_only(): void
    {
        $this->seedCore();

        // إشعاران غيرُ مقروءَين للموظفة + مقروءٌ واحد، وإشعارٌ لمستخدمٍ آخر
        HubNotification::create(['user_id' => $this->employee->id, 'kind' => 'assign',
            'text' => 'أُسنِدت إليك مهمّة', 'read' => false, 'created_at' => now()]);
        HubNotification::create(['user_id' => $this->employee->id, 'kind' => 'assign',
            'text' => 'مهمّةٌ أخرى', 'read' => false, 'created_at' => now()]);
        HubNotification::create(['user_id' => $this->employee->id, 'kind' => 'assign',
            'text' => 'قرأتُها', 'read' => true, 'created_at' => now()]);
        HubNotification::create(['user_id' => $this->owner->id, 'kind' => 'assign',
            'text' => 'إشعارُ المالك', 'read' => false, 'created_at' => now()]);

        $data = $this->mobileLogin($this->employee, 'inst-boot-unread-11');
        $res  = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/bootstrap');
        $res->assertOk();

        $this->assertSame(2, $res->json('data.unread_notifications'),
            'غيرُ المقروء للمُنادي وحدَه — لا يعدّ المقروءَ ولا إشعارَ غيرِه');
    }

    public function test_bootstrap_contains_no_secret_shaped_keys_or_raw_tokens(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee, 'inst-boot-secret-11');

        $res = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/bootstrap');
        $res->assertOk();

        // لا مفتاحَ سرٍّ في أيّ عمقٍ من الشجرة (مطابقةٌ حرفيّة — `can_secrets` علمٌ لا سرّ)
        $this->assertNoKeysDeep($this->forbiddenSecretKeys(), $res->json('data'), 'الإقلاعُ لا يحمل مفاتيحَ أسرار');

        // ولا يظهر رمزُ الوصول/التحديث الخام في أيّ قيمة
        $scalars = $this->allScalarsDeep($res->json('data'));
        $this->assertNotContains($data['access_token'], $scalars, 'رمزُ الوصولِ لا يُعاد في الإقلاع');
        $this->assertNotContains($data['refresh_token'], $scalars, 'رمزُ التحديثِ لا يُعاد في الإقلاع');
    }

    public function test_bootstrap_sets_etag_and_second_request_with_if_none_match_is_304(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee, 'inst-boot-etag-111');
        $h    = $this->bearer($data['access_token']);

        $first = $this->withHeaders($h)->getJson('/api/mobile/v1/bootstrap');
        $first->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag, 'الإقلاعُ يضع ترويسةَ ETag');

        // الطلبُ الثاني بنفس البصمة ⇒ 304 بلا جسم (البصمةُ على الحمولة الثابتة — server_time خارجها)
        $second = $this->withHeaders($h + ['If-None-Match' => $etag])
            ->getJson('/api/mobile/v1/bootstrap');
        $second->assertStatus(304);
        $this->assertSame('', $second->getContent(), 'جسمُ 304 فارغٌ تماماً');
        $this->assertSame($etag, $second->headers->get('ETag'), 'البصمةُ نفسُها على 304');
    }

    public function test_bootstrap_etag_changes_when_the_snapshot_changes(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee, 'inst-boot-chg-1111');
        $h    = $this->bearer($data['access_token']);

        $etag1 = $this->withHeaders($h)->getJson('/api/mobile/v1/bootstrap')->headers->get('ETag');

        // إشعارٌ جديدٌ غيرُ مقروء يغيّر اللقطة ⇒ بصمةٌ جديدة ⇒ لا 304
        HubNotification::create(['user_id' => $this->employee->id, 'kind' => 'assign',
            'text' => 'جديد', 'read' => false, 'created_at' => now()]);

        $res = $this->withHeaders($h + ['If-None-Match' => $etag1])
            ->getJson('/api/mobile/v1/bootstrap');
        $res->assertOk();   // ليست 304 — تغيّرت اللقطة
        $this->assertNotSame($etag1, $res->headers->get('ETag'), 'تغيّرُ عدِّ غيرِ المقروء يبدّل البصمة');
        $this->assertSame(1, $res->json('data.unread_notifications'));
    }

    public function test_bootstrap_requires_authentication(): void
    {
        $this->seedCore();
        $this->getJson('/api/mobile/v1/bootstrap')
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
