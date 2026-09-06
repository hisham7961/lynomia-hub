<?php

namespace Tests\Feature;

use App\Models\ErrorEvent;
use App\Models\HubNotification;
use App\Support\ErrorLog;
use App\Support\ErrorStats;
use App\Support\Health;
use Tests\TestCase;

/**
 * **إدارةُ الضجيج (WP-3.5): الإشعارُ الذي يُهجَر أسوأ من لا إشعار — والحرجُ لا
 * يختفي صامتاً.**
 *
 * أربعُ سكك تحكم صوتَ مركز الأخطاء:
 *   · **كتمٌ لكل بصمة** (`muted_until` يختمه المالك من شاشة الخطأ): بصمةٌ مكتومة
 *     لا تُنشئ إشعاراً — حتى لو عادت بعد حلٍّ — والحالةُ تتغيّر كما هي (الكتمُ
 *     يُسكِت الصوتَ لا الحقيقة).
 *   · **تبريدٌ لكل بصمة** (`errnotify:fp:<hash>`): خطأٌ يرتدّ بين «محلول» و«عاد»
 *     كلَّ دقيقة لا يكتب ستين إشعاراً — إشعارُ عودةٍ واحد ثم صمتٌ مدةَ التبريد.
 *   · **CRITICAL يتجاوز سقفَ الانفجار**: كان الحرجُ يسقط صامتاً بعد الإشعار
 *     الثامن في النافذة — مخالفةٌ صريحة لـ§4.12.
 *   · **وكتمُ المستخدم لنوع `error`** من تفضيلاته — سكّةُ الكتم القائمة
 *     (HubNotification::MUTEABLE) تشمل أخطاءَ التقنية أيضاً.
 *
 * والمتجاهَلُ الحرج يبقى محسوباً في صحّة النظام: إخفاءُ العرض قرارُ عرضٍ لا شفاء.
 */
class ErrorNoiseTest extends TestCase
{
    private function boom(string $msg = 'انفجار', array $ctx = []): void
    {
        ErrorLog::capture('php', $msg, '/app/Foo.php', 42, null, $ctx);
    }

    private function resolveAll(): void
    {
        ErrorEvent::query()->update(['status' => 'محلول']);
    }

    private function errNotifs($user = null): int
    {
        return HubNotification::where('user_id', ($user ?? $this->owner)->id)
            ->where('kind', 'error')->count();
    }

    /* ────────── الكتم لكل بصمة ────────── */

    /** بصمةٌ كتمها المالك تعود بعد حلٍّ — الحالةُ تتغيّر والإشعارُ لا يُكتب */
    public function test_a_muted_fingerprint_regression_stays_silent(): void
    {
        $this->seedCore();
        $this->boom();
        $this->assertSame(1, $this->errNotifs(), 'أول ظهورٍ يصل صاحبه');

        ErrorEvent::query()->update(['status' => 'محلول', 'muted_until' => now()->addDays(7)]);
        $this->boom();

        $this->assertSame(1, $this->errNotifs(),
            'بصمةٌ مكتومة حتى الأسبوع القادم كتبت إشعارَ عودة — الكتمُ حبرٌ على شاشة');
        $this->assertSame('جديد', (string) ErrorEvent::first()->status,
            'الكتمُ أسكت الحقيقةَ لا الصوت — العودةُ يجب أن تُختم حالةً ولو صمت الإشعار');
    }

    /** وكتمٌ انقضى أمدُه لا يُسكِت شيئاً */
    public function test_an_expired_mute_speaks_again(): void
    {
        $this->seedCore();
        $this->boom();
        ErrorEvent::query()->update(['status' => 'محلول', 'muted_until' => now()->subMinute()]);

        $this->boom();

        $this->assertSame(2, $this->errNotifs(), 'كتمٌ منقضٍ ما زال يكتم — الأمدُ بلا معنى');
    }

    /* ────────── التبريد لكل بصمة ────────── */

    /** خطأٌ يرتدّ بين حلٍّ وعودة — إشعارُ عودةٍ واحد في نافذة التبريد لا واحدٌ لكل ارتداد */
    public function test_regression_flapping_is_cooled_down(): void
    {
        $this->seedCore();
        $this->boom();                                   // ظهورٌ أول → إشعار ١
        $this->resolveAll();
        $this->boom();                                   // عودةٌ أولى → إشعار ٢ (يبدأ التبريد)
        $this->resolveAll();
        $this->boom();                                   // عودةٌ ثانية خلال التبريد → صمت

        $this->assertSame(2, $this->errNotifs(),
            'خطأٌ يرتدّ كلَّ دقيقة كتب إشعاراً لكل ارتداد — هذا ما يُهجَر منه الصندوق');
    }

    /** وبعد انقضاء التبريد تعود العودةُ خبراً */
    public function test_cooldown_expires_and_the_regression_speaks_again(): void
    {
        $this->seedCore();
        $this->boom();
        $this->resolveAll();
        $this->boom();                                   // إشعار العودة — يبدأ التبريد (١٥ دقيقة)

        $this->travel(16)->minutes();
        $this->resolveAll();
        $this->boom();

        $this->assertSame(3, $this->errNotifs(),
            'انقضى التبريد وعاد الخطأ بعد حلٍّ — وصمتَ المركز');
    }

    /* ────────── CRITICAL يتجاوز سقف الانفجار ────────── */

    /** بعد الإشعار الثامن في النافذة يسقط العاديّ صامتاً — والحرجُ لا يسقط أبداً */
    public function test_critical_beats_the_burst_cap(): void
    {
        $this->seedCore();
        for ($i = 0; $i < ErrorLog::NOTIFY_BURST_CAP + 4; $i++) $this->boom("خطأ رقم {$i}");
        $this->assertSame(ErrorLog::NOTIFY_BURST_CAP, $this->errNotifs(),
            'سقفُ الانفجار نفسُه انكسر — هذا شرطُ الاختبار لا هدفُه');

        $this->boom('انقطاعٌ حرج في القاعدة', ['severity' => 'CRITICAL']);

        $this->assertSame(ErrorLog::NOTIFY_BURST_CAP + 1, $this->errNotifs(),
            'وقع عطلٌ حرج بعد الإشعار الثامن فسقط صامتاً — §4.12: الحرجُ لا يختفي');
        $this->assertTrue(
            HubNotification::where('user_id', $this->owner->id)->where('kind', 'error')
                ->where('text', 'LIKE', '%انقطاعٌ حرج%')->exists(),
            'إشعارُ الحرج وصل لكن بلا نصّه');
    }

    /* ────────── كتمُ المستخدم لنوع error ────────── */

    /** نوعُ `error` قابلٌ للكتم من تفضيلات المستخدم — سكّةُ MUTEABLE القائمة */
    public function test_error_kind_is_user_muteable(): void
    {
        $this->assertArrayHasKey('error', HubNotification::MUTEABLE,
            'نوع error خارج قائمة MUTEABLE — لا سبيل لمراقبٍ أن يكتم قناته');

        $this->seedCore();
        $this->owner->forceFill(['prefs' => ['mute' => ['error']]])->save();
        $this->boom();

        $this->assertSame(0, $this->errNotifs(),
            'مستخدمٌ كتم نوع error وما زال يُشعَر — الكتمُ عند المصدر لا يعرف الأخطاء');
    }

    /* ────────── المتجاهَل الحرج يبقى في الصحّة ────────── */

    /** إخفاءُ الحرج من الإشعارات قرارُ عرضٍ — صحّةُ النظام تحسبه كما هو */
    public function test_ignored_critical_still_counts_in_health_errors(): void
    {
        $this->seedCore();
        $this->boom('انهيارٌ حرج', ['severity' => 'CRITICAL']);
        ErrorEvent::query()->update(['status' => 'متجاهَل', 'muted_until' => now()->addDays(30)]);

        $w = ErrorStats::healthWindow();
        $this->assertGreaterThanOrEqual(1, $w['critical_1h'],
            'حرجٌ متجاهَل اختفى من نافذة الصحّة — التجاهلُ صار شفاءً');

        $errors = Health::check()['components']['errors'];
        $this->assertSame(Health::UNAVAILABLE, $errors['status'],
            'Health::errors لا يرى الحرجَ المتجاهَل — /healthz يكذب');
    }
}
