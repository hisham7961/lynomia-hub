<?php

namespace Tests\Feature;

use App\Support\SecurityPosture;
use App\Support\Settings;
use Tests\TestCase;

/**
 * (WP-9.4 · spec §7.13) **راياتُ التشغيل تُقرأ من مكانٍ وتُبدَّل من مكانها.**
 *
 * خمسُ راياتٍ تغيّر سلوك النظام كلَّه (صيانةٌ · تجريبيّ · قفلُ طوارئ ·
 * تجميدُ التصدير · تجميدُ سكّ الرموز)، ولكلٍّ شاشةٌ تملكها وتسجّل تبديلَها في
 * التدقيق. فمفتاحُ تبديلٍ **ثانٍ** في شاشة الإعدادات ليس تسهيلاً: هو بابٌ
 * يُرفع منه قفلُ الطوارئ بلا مرورٍ بمركز الأمان — أي بلا التأكيد والتصعيد
 * والرسالة التي بُنيت هناك.
 *
 * فالبطاقةُ هنا **قراءةٌ فقط**: تقول ما المرفوع الآن وتحيل إلى مالكه. وحالةُ
 * التجميدَين تُقرأ من `SecurityPosture` نفسِها — لا قارئَ سادس.
 */
class RuntimeFlagsCardTest extends TestCase
{
    /** البطاقةُ تعرض الرايات الخمس وتحيل كلَّ واحدةٍ إلى شاشتها */
    public function test_the_card_lists_every_flag_and_links_to_its_owner_screen(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringContainsString('رايات التشغيل', $html, 'لا بطاقةَ رايات في شاشة الإعدادات');
        foreach (Settings::RUNTIME_FLAGS as $key => [$label, $route]) {
            $this->assertStringContainsString($label, $html, "الرايةُ {$key} غائبةٌ عن البطاقة");
            $this->assertStringContainsString(route($route), $html, "الرايةُ {$key} بلا رابطٍ إلى شاشتها");
        }
    }

    /** ولا نموذجَ تبديلٍ في الصفحة: التبديلُ من مالكه وحده */
    public function test_the_settings_page_has_no_toggle_form_for_any_runtime_flag(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/settings')->assertOk()->getContent();

        foreach ([route('security.lockdown'), route('ops.maintenance'),
                  route('security.freeze', ['key' => 'exports']),
                  route('security.freeze', ['key' => 'tokens'])] as $action) {
            $this->assertStringNotContainsString('action="' . $action . '"', $html,
                'نموذجُ تبديلٍ ثانٍ للراية في شاشة الإعدادات: ' . $action);
        }
        foreach (['security_lockdown', 'security_freeze_exports', 'security_freeze_tokens',
                  'maintenance_on', 'demo_on'] as $input) {
            $this->assertStringNotContainsString('name="' . $input . '"', $html,
                "مدخلُ تحريرٍ للراية {$input} في شاشة الإعدادات");
        }
    }

    /** الحالةُ صادقة: رايةٌ مرفوعةٌ تظهر مرفوعةً في البطاقة وفي عدّاد اللوحة */
    public function test_a_raised_flag_shows_as_raised(): void
    {
        $this->seedCore();
        $this->hubSetting('security.freeze_exports', '1');
        $this->hubSetting('maintenance.on', '1');

        $dash = Settings::dashboard();
        $this->assertSame(2, $dash['flags'], 'عدّادُ الرايات المرفوعة لا يطابق الواقع');
        $this->assertArrayHasKey('security.freeze_exports', $dash['flags_on']);

        $html = $this->actingAs($this->owner)->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringContainsString('مرفوعة', $html);
    }

    /** التجميدان صارا صفَّين في وضعية الأمان — فالحالةُ من مصدرٍ واحد */
    public function test_both_freezes_are_read_from_the_security_posture(): void
    {
        $this->seedCore();

        $keys = array_column(SecurityPosture::checks(), 'key');
        $this->assertContains('freeze_exports', $keys, 'تجميدُ التصدير خارج وضعية الأمان');
        $this->assertContains('freeze_tokens', $keys, 'تجميدُ سكّ الرموز خارج وضعية الأمان');

        // مُنزَّلان: سليمان بلا ضجيج
        $rows = collect(SecurityPosture::checks())->keyBy('key');
        $this->assertSame('ok', $rows['freeze_exports']['tone']);
        $this->assertSame('ok', $rows['freeze_tokens']['tone']);

        // مرفوعان: حالةٌ مقصودةٌ تستحق الانتباه، وتحيل إلى مركز الأمان
        $this->hubSetting('security.freeze_exports', '1');
        $this->hubSetting('security.freeze_tokens', '1');
        $rows = collect(SecurityPosture::checks())->keyBy('key');
        $this->assertSame('wn', $rows['freeze_exports']['tone']);
        $this->assertSame('wn', $rows['freeze_tokens']['tone']);
        $this->assertSame(route('security.index'), $rows['freeze_exports']['url']);
        $this->assertSame(route('security.index'), $rows['freeze_tokens']['url']);
    }
}
