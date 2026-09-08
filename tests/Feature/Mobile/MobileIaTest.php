<?php

namespace Tests\Feature\Mobile;

use App\Support\InformationArchitecture;
use Tests\TestCase;

/**
 * **IA الطور 9 — تسليمُ التنقّل للجوال**: `GET /api/mobile/v1/navigation` يعيد **نفسَ**
 * معماريةِ IA مُنطَّقةً (لا `mobile_nav.php` ثانٍ)، والإقلاعُ يحمل `ia` إلى جانب `nav`.
 * يحرسُ: مطابقةَ الصلاحيةِ لِـ`visibleDomains` على الويب، لا تسريبَ مجالٍ محجوب،
 * لا مفاتيحَ أسرار، وسلوكَ ETag/304.
 */
class MobileIaTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    public function test_navigation_returns_permission_filtered_ia_tree(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner, 'inst-nav-owner-1111');

        $res = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/navigation')->assertOk();

        $res->assertJsonStructure(['data' => ['schema_version', 'feature_flags', 'ia' => ['surfaces', 'domains']]]);

        // مطابقةُ الصلاحية: مفاتيحُ مجالاتِ الجوال == visibleDomains على الويب لنفس المستخدم
        $mobileDomains = array_column($res->json('data.ia.domains'), 'key');
        $webDomains = array_keys((new InformationArchitecture())->visibleDomains($this->owner));
        sort($mobileDomains);
        sort($webDomains);
        $this->assertSame($webDomains, $mobileDomains, 'تنقّلُ الجوال يخالف visibleDomains على الويب');
    }

    public function test_navigation_is_scoped_and_leaks_no_hidden_domain(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee, 'inst-nav-emp-11111');

        $res = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/navigation')->assertOk();

        $keys = array_column($res->json('data.ia.domains'), 'key');
        // الموظّفُ العاديُّ لا يرى مجالَ الإدارة (سطحُ النظام) في تنقّل الجوال
        $this->assertNotContains('administration', $keys, 'مجالُ الإدارة سُرِّب لموظّفٍ عاديّ في تنقّل الجوال');

        // مطابقةٌ صارمةٌ مع الويب (نفسُ المُسنِد — لا تسريبَ ولا حجبٌ خاطئ)
        $web = array_keys((new InformationArchitecture())->visibleDomains($this->employee));
        sort($keys); sort($web);
        $this->assertSame($web, $keys);
    }

    public function test_navigation_carries_no_secret_keys(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner, 'inst-nav-secret-11');

        $res = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/navigation')->assertOk();

        $this->assertNoKeysDeep($this->forbiddenSecretKeys(), $res->json('data'), 'تنقّلُ الجوال لا يحمل مفاتيحَ أسرار');
    }

    public function test_navigation_etag_gives_304_on_second_request(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner, 'inst-nav-etag-1111');
        $h = $this->bearer($data['access_token']);

        $first = $this->withHeaders($h)->getJson('/api/mobile/v1/navigation')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag, 'التنقّلُ يضع ETag');

        $this->withHeaders($h + ['If-None-Match' => $etag])
            ->getJson('/api/mobile/v1/navigation')->assertStatus(304);
    }

    public function test_navigation_requires_authentication(): void
    {
        $this->seedCore();
        $this->getJson('/api/mobile/v1/navigation')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    /** الإقلاعُ يحمل ia (نفسُ الشجرة) إلى جانب nav (توافقٌ خلفيّ) — كلاهما حاضرٌ */
    public function test_bootstrap_carries_both_nav_and_ia(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner, 'inst-nav-boot-1111');

        $res = $this->withHeaders($this->bearer($data['access_token']))
            ->getJson('/api/mobile/v1/bootstrap')->assertOk();

        $this->assertIsArray($res->json('data.nav'), 'الإقلاعُ فقد nav (توافقٌ خلفيّ)');
        $this->assertArrayHasKey('g', $res->json('data.nav.0'));
        $this->assertNotEmpty($res->json('data.ia.domains'), 'الإقلاعُ لا يحمل ia');
        $this->assertNotEmpty($res->json('data.ia.surfaces'));
    }
}
