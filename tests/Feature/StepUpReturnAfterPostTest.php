<?php

namespace Tests\Feature;

use App\Support\StepUp;
use Tests\TestCase;

/**
 * عطلٌ إنتاجيّ (v2.408): فعلٌ حسّاسٌ POST (ترحيلٌ مثلاً) يصعّد الهويةَ، فكانت وجهةُ
 * العودة بعد التأكيد هي مسارَ الفعل نفسِه — و`stepup.verify` يُعيد التوجيه بـGET،
 * فيردّ الخادمُ **405 Method Not Allowed** على مسارٍ لا يقبل إلا POST. الإصلاح:
 * لغيرِ GET تكون العودةُ إلى صفحةِ النموذج (الإحالة، GET) لا إلى مسار الفعل.
 */
class StepUpReturnAfterPostTest extends TestCase
{
    public function test_step_up_from_a_post_action_returns_to_a_get_page_not_the_post_route(): void
    {
        $this->seedCore();
        $this->hubSetting('security.stepup_ops', '1');   // تصعيدُ العمليات مفعَّل

        // المالكُ يضغط «ترحيل» من صفحة التشغيل (POST) بلا تصعيدٍ ساري:
        $redirect = $this->actingAs($this->owner)
            ->from('/admin/ops')
            ->post('/admin/ops/migrate')
            ->assertRedirect();

        // الوجهةُ شاشةُ التصعيد، و`next` فيها **مسارُ GET** (صفحةُ التشغيل) لا مسارُ POST.
        $location = $redirect->headers->get('Location');
        $this->assertStringContainsString('stepup', $location, 'لم يُوجَّه لشاشة التصعيد');
        $next = urldecode((string) (parse_url($location, PHP_URL_QUERY) ? array_column(
            array_map(fn ($p) => explode('=', $p, 2), explode('&', parse_url($location, PHP_URL_QUERY))), 1, 0
        )['next'] ?? '' : ''));
        $this->assertSame('/admin/ops', $next, "وجهةُ العودة مسارُ POST لا صفحةَ النموذج — next={$next}");

        // يؤكّد هويتَه، فتكون الوجهةُ صفحةَ GET سليمة (لا 405 عند إتباعها).
        $this->actingAs($this->owner)
            ->post('/stepup', ['answer' => 'Secret!2026x', 'next' => $next])
            ->assertRedirect('/admin/ops');
        $this->assertTrue(StepUp::fresh());

        // وإتباعُ تلك الوجهةِ بـGET يعمل (200/302) — لا 405.
        $this->actingAs($this->owner)->get($next)->assertSuccessful();
    }

    /** فعلُ GET حسّاس يبقى يعود إلى مساره نفسِه (لا انحدار) */
    public function test_a_safe_get_action_still_returns_to_itself(): void
    {
        $this->seedCore();
        // hub_require_stepup على طلبِ GET يحفظ المسارَ نفسَه في next.
        $u = route('stepup.show', ['next' => '/admin/ops']);
        $this->assertStringContainsString('next=', $u);
    }
}
