<?php

namespace Tests\Feature\MobilePlatform;

use App\Models\Role;
use App\Models\User;
use App\Support\MobilePlatform;
use Tests\TestCase;

/**
 * **مركزُ منصّة الجوال — الوثائق (MPC-8)**: فهرسٌ حيٌّ من نظامِ الملفات + عارضٌ **آمن**
 * (قراءةٌ من allowlist عبر realpath — لا اجتيازَ مسارٍ `../`، والنصُّ يُطمَس بـBlade).
 */
class MobilePlatformDocsTest extends TestCase
{
    private function mobileAdmin(): User
    {
        $role = Role::create(['name' => 'مسؤولُ جوال', 'scope' => 'all', 'flags' => ['mobile' => 1], 'matrix' => []]);

        return User::create(['name' => 'مسؤول', 'email' => 'mob8@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    public function test_docs_tab_shows_catalog(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=docs')->assertOk()
            ->assertSee('وثائقُ جاهزيّة الجوال')
            ->assertSee('وثائقُ مركز منصّة الجوال')
            ->assertSee('docs/mobile-readiness/00-current-architecture.md')
            ->assertSee('مراجعُ حيّة');
    }

    public function test_employee_cannot_access_docs_tab(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/admin/mobile-platform?tab=docs')->assertForbidden();
    }

    public function test_mobile_admin_can_view_docs_tab(): void
    {
        $this->seedCore();
        $this->actingAs($this->mobileAdmin())->get('/admin/mobile-platform?tab=docs')->assertOk()
            ->assertSee('الوثائق');
    }

    /** الفهرسُ يُكتشَف من نظامِ الملفات (لا قائمةٌ يدويّةٌ تتباعد) */
    public function test_catalog_is_discovered_from_filesystem(): void
    {
        $this->seedCore();
        $docs = MobilePlatform::documentation();
        $this->assertNotEmpty($docs['readiness']);
        $names = array_column($docs['readiness'], 'name');
        $this->assertContains('00-current-architecture.md', $names);
        $this->assertContains('FINAL_REPORT.md', $names);
    }

    /** العارضُ يعرض وثيقةً مُدرجةً (نصٌّ خامّ) */
    public function test_viewer_renders_whitelisted_doc(): void
    {
        $this->seedCore();
        $content = MobilePlatform::docContent('readiness', '00-current-architecture.md');
        $this->assertNotNull($content);
        $this->assertNotSame('', trim($content));

        $this->actingAs($this->owner)
            ->get('/admin/mobile-platform?tab=docs&doc_set=readiness&doc=00-current-architecture.md')->assertOk()
            ->assertSee('00-current-architecture.md')
            ->assertSee('عودة للفهرس');
    }

    /** **الأمان:** اجتيازُ المسار مرفوضٌ (realpath خارجَ القائمة ⇒ null) */
    public function test_viewer_rejects_path_traversal(): void
    {
        $this->seedCore();
        $this->assertNull(MobilePlatform::docContent('readiness', '../../.env'));
        $this->assertNull(MobilePlatform::docContent('readiness', '../../../etc/passwd'));
        $this->assertNull(MobilePlatform::docContent('readiness', '..%2f..%2f.env'));
        $this->assertNull(MobilePlatform::docContent('center', 'CLAUDE.md'));       // خارجَ المجلّد
        $this->assertNull(MobilePlatform::docContent('bogus_set', '00-current-architecture.md'));
        $this->assertNull(MobilePlatform::docContent('readiness', 'does-not-exist.md'));
    }

    public function test_no_secret_rendered_on_docs_tab(): void
    {
        $this->seedCore();
        $this->hubSetting('mobile.push_fcm_access_token', 'SECRET-FCM-DOCS-111');

        $html = $this->actingAs($this->owner)->get('/admin/mobile-platform?tab=docs')->assertOk()->getContent();
        $this->assertStringNotContainsString('SECRET-FCM-DOCS-111', $html);
    }
}
