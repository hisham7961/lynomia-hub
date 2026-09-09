<?php

namespace Tests\Feature;

use App\Support\FeatureRegistry;
use App\Support\FeatureStatus;
use App\Support\Settings;
use Tests\TestCase;

/**
 * **سجلُّ القدرات — نزاهةُ الكتالوجِ واشتقاقُ الحالة** (سجلّ القدرات · §17/§23).
 *
 * الكتالوجُ لا يتعفّن: مفاتيحُ فريدة · حالاتٌ مشروعة · مجالاتٌ معروفة · اعتماديّاتٌ صحيحة
 * بلا دورات · مفاتيحُ إعدادٍ مُعلَنة · ثوابتُ نظاميّةٍ لا تُطفأ · مؤجَّلٌ لا يُعرَض مُفعَّلاً ·
 * خارجيٌّ لا يُزيَّف ENABLED. واشتقاقُ الحالةِ صادقٌ من الفاحصات. إثباتٌ بنيويٌّ لا نثريّ.
 */
class FeatureRegistryIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FeatureRegistry::flush();
    }

    public function test_catalog_integrity_has_zero_problems(): void
    {
        $problems = FeatureRegistry::integrity();
        $this->assertSame([], $problems, 'مشاكلُ نزاهةٍ في كتالوج القدرات: ' . implode(' · ', $problems));
    }

    public function test_every_status_is_valid_and_every_domain_is_known(): void
    {
        $domains = array_keys(FeatureRegistry::domains());
        foreach (FeatureRegistry::all() as $key => $f) {
            $this->assertTrue(FeatureStatus::valid($f['status']), "$key: حالةٌ غيرُ مشروعة");
            $this->assertContains($f['domain'], $domains, "$key: مجالٌ مجهول");
            $this->assertNotSame('', trim((string) $f['title_ar']), "$key: عنوانٌ عربيٌّ ناقص");
            $this->assertNotSame('', trim((string) $f['title_en']), "$key: عنوانٌ إنجليزيٌّ ناقص");
        }
    }

    public function test_no_system_invariant_is_toggleable_and_none_can_be_disabled(): void
    {
        foreach (FeatureRegistry::all() as $key => $f) {
            if ($f['status'] !== FeatureStatus::SYSTEM_INVARIANT) continue;
            $this->assertFalse(FeatureRegistry::isToggleable($key), "$key: ثابتٌ نظاميٌّ قابلٌ للتبديل");
            // محاولةُ إطفائه تُرفَض صراحةً
            try {
                FeatureRegistry::setEnabled($key, false, 'محاولةٌ ممنوعة');
                $this->fail("$key: قُبِل إطفاءُ ثابتٍ نظاميّ");
            } catch (\InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_toggleable_features_declare_a_real_settings_key(): void
    {
        $toggleables = array_filter(FeatureRegistry::keys(), fn ($k) => FeatureRegistry::isToggleable($k));
        $this->assertNotEmpty($toggleables, 'لا قدرةَ اختياريّةٌ للاختبار');
        foreach ($toggleables as $key) {
            $sk = (string) FeatureRegistry::entry($key)['setting_key'];
            $this->assertTrue(Settings::entry($sk) !== null || Settings::internalEntry($sk) !== null,
                "$key: مفتاحُ إعدادٍ يتيم ($sk)");
        }
    }

    public function test_deferred_features_are_never_available_or_enabled(): void
    {
        foreach (FeatureRegistry::all() as $key => $f) {
            if ($f['status'] !== FeatureStatus::DEFERRED) continue;
            $this->assertFalse($f['available'], "$key: مؤجَّلٌ يُعرَض متاحاً");
            $this->assertNotSame(FeatureStatus::ENABLED, $f['status']);
        }
    }

    public function test_external_and_provider_dependent_features_are_not_fake_enabled(): void
    {
        // بلا مزوّدات مُهيَّأة: الاعتمادُ الخارجيُّ يُشتقُّ NOT_CONFIGURED/EXTERNAL لا ENABLED
        foreach (['mobile.production_push', 'collab.websocket_provider', 'endpoint.usb_enforcement',
                  'security.edge_blocking', 'telecom.live_provisioning'] as $key) {
            $st = FeatureRegistry::status($key)['status'];
            $this->assertContains($st, [FeatureStatus::NOT_CONFIGURED, FeatureStatus::EXTERNAL, FeatureStatus::DEGRADED],
                "$key: حالةٌ متفائلةٌ ($st) بلا مزوّدٍ مُهيَّأ");
            $this->assertFalse(FeatureRegistry::available($key), "$key: يُعرَض متاحاً بلا مزوّد");
        }
    }

    public function test_counts_cover_all_nine_states_and_sum_correctly(): void
    {
        $counts = FeatureRegistry::counts();
        foreach (FeatureStatus::ALL as $state) {
            $this->assertArrayHasKey($state, $counts, "حالةٌ غائبةٌ من العدّ: $state");
        }
        $sum = 0;
        foreach (FeatureStatus::ALL as $state) $sum += $counts[$state];
        $this->assertSame(count(FeatureRegistry::keys()), $sum, 'مجموعُ العدّ لا يطابق عددَ القدرات');
        $this->assertSame($sum, $counts['total']);
    }

    public function test_transition_rules_reject_forbidden_and_allow_optional_toggle(): void
    {
        // ممنوع: من/إلى الثابت النظاميّ، ومن NOT_CONFIGURED/DEFERRED إلى ENABLED
        $this->assertFalse(FeatureStatus::transitionAllowed(FeatureStatus::SYSTEM_INVARIANT, FeatureStatus::DISABLED));
        $this->assertFalse(FeatureStatus::transitionAllowed(FeatureStatus::ENABLED, FeatureStatus::SYSTEM_INVARIANT));
        $this->assertFalse(FeatureStatus::transitionAllowed(FeatureStatus::NOT_CONFIGURED, FeatureStatus::ENABLED));
        $this->assertFalse(FeatureStatus::transitionAllowed(FeatureStatus::DEFERRED, FeatureStatus::ENABLED));
        // مسموح: تبديلُ الاختياريّة
        $this->assertTrue(FeatureStatus::transitionAllowed(FeatureStatus::ENABLED, FeatureStatus::DISABLED));
        $this->assertTrue(FeatureStatus::transitionAllowed(FeatureStatus::DISABLED, FeatureStatus::ENABLED));
    }

    public function test_toggling_an_optional_capability_flips_its_status_via_settings(): void
    {
        // مُفعَّلةٌ افتراضاً (رايةٌ غائبة)
        $this->assertSame(FeatureStatus::ENABLED, FeatureRegistry::status('collab.typing')['status']);
        $this->assertTrue(FeatureRegistry::available('collab.typing'));

        // إطفاءٌ عبر مركز القدرات (يكتب عبر Settings::put — كاتبٌ واحد)
        FeatureRegistry::setEnabled('collab.typing', false, 'اختبار الإطفاء');
        $this->assertSame(FeatureStatus::DISABLED, FeatureRegistry::status('collab.typing')['status']);
        $this->assertFalse(FeatureRegistry::available('collab.typing'));
        // وسُجِّل التغييرُ في تاريخ الإعدادات (سكّةٌ واحدة)
        $hist = Settings::lastChanges(['feature.collab_typing']);
        $this->assertArrayHasKey('feature.collab_typing', $hist);

        // إعادةُ التفعيل
        FeatureRegistry::setEnabled('collab.typing', true, 'اختبار التفعيل');
        $this->assertSame(FeatureStatus::ENABLED, FeatureRegistry::status('collab.typing')['status']);
    }

    public function test_setting_a_non_toggleable_capability_is_refused(): void
    {
        // قدرةٌ أساسيّةٌ غيرُ اختياريّة — لا تُبدَّل زمنيّاً
        $this->expectException(\InvalidArgumentException::class);
        FeatureRegistry::setEnabled('collab.channels', false, 'محاولةٌ ممنوعة');
    }
}
