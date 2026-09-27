<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * **بوّابةُ phpstan لا تُفكَّك بصمت** (البند #36 و#31 · QE-05).
 *
 * phpstan نفسُه لا يُشغَّل في الحزمة: يحتاج ثنائيّاً يُنزَّل من الشبكة، وحزمةٌ
 * تسقط يومَ تسقط شبكةٌ تُعلّم تجاهلَها (ولذلك يبقى `StaticSoundnessTest`
 * حارساً بلا شبكة). لكنّ **البوّابةَ** — ما يجعل phpstan يُسقط دفعةً — أربعُ
 * قطعٍ نصّيّة، وكلُّ واحدةٍ منها تُفكّ بسطرٍ لا يلاحظه أحد:
 *
 *   ١. وظيفةُ CI تُحذف أو تُعلَّق             ⇒ لا شيءَ يُشغّله
 *   ٢. المستوى يُخفَض في `phpstan.neon`       ⇒ يُشغَّل ولا يرى
 *   ٣. خطُّ الأساسِ يُعاد توليدُه بأخطاءٍ أكثر ⇒ يرى ويَقبل — **وهذا ما جعل بنودَ السجلِّ تنمو**
 *   ٤. البصمةُ تُسقَط من السكربت              ⇒ ثنائيٌّ يُنفَّذ بثقةٍ في الشبكة
 *
 * فهذا الحارسُ يقرأ القطعَ الأربعَ ويسقط عند فكِّ أيٍّ منها. ولا يحتاج قاعدةً
 * ولا تطبيقاً — `PHPUnit\TestCase` لا `Tests\TestCase`.
 */
class StaticAnalysisGateTest extends TestCase
{
    /**
     * **سقفُ خطِّ الأساس** — ٢٩٠ خطأً قِيست على v2.616.0 بالمستوى ٥ (بعد
     * استثناءِ سحرِ Laravel المحسوبِ في `tools/phpstan/laravel-magic.php`).
     *
     * **يُخفَض ولا يُرفَع.** إصلاحٌ يُزيل خطأً ⇒ phpstan نفسُه يُلزم بحذفِ سطرِه
     * (`reportUnmatchedIgnoredErrors`) ⇒ اخفض هذا الرقمَ في الدفعةِ نفسِها.
     * ورفعُه يلزمه سببٌ مكتوبٌ في `docs/TECH_DEBT.md` أوّلاً.
     */
    private const BASELINE_CEILING = 290;

    private const MIN_LEVEL = 5;

    private static function read(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        self::assertFileExists($path, $rel . ' غائب');

        return (string) file_get_contents($path);
    }

    public function test_وظيفةُ_CI_تُشغّل_phpstan(): void
    {
        $ci = self::read('.github/workflows/ci.yml');

        $this->assertMatchesRegularExpression('/^  static:\s*$/m', $ci, 'وظيفةُ static غائبة من ci.yml');
        $this->assertMatchesRegularExpression('/^\s+run: tools\/phpstan\.sh\b/m', $ci,
            'لا خطوةَ في CI تُشغّل tools/phpstan.sh');
        $this->assertStringNotContainsString('continue-on-error: true', self::jobBlock($ci, 'static'),
            'وظيفةُ phpstan صارت إنذاراً لا بوّابة');
    }

    public function test_المستوى_لا_يُخفَض(): void
    {
        $neon = self::read('phpstan.neon');

        $this->assertMatchesRegularExpression('/^\s+level:\s*(\d+)\s*$/m', $neon);
        preg_match('/^\s+level:\s*(\d+)\s*$/m', $neon, $m);
        $this->assertGreaterThanOrEqual(self::MIN_LEVEL, (int) $m[1], 'مستوى phpstan خُفض');

        foreach (['app', 'routes', 'config', 'database'] as $dir) {
            $this->assertMatchesRegularExpression('/^\s+- ' . $dir . '\s*$/m', $neon, "المسار {$dir} خرج من التحليل");
        }
        $this->assertStringContainsString('phpstan-baseline.neon', $neon);
        $this->assertMatchesRegularExpression('/reportUnmatchedIgnoredErrors:\s*true/', $neon,
            'خطُّ أساسٍ لا يُلزم بحذفِ ما أُصلح يتضخّم بأخطاءٍ ميّتة');
    }

    public function test_خطُّ_الأساس_لا_يكبر(): void
    {
        $baseline = self::read('phpstan-baseline.neon');
        preg_match_all('/^\s+count:\s*(\d+)\s*$/m', $baseline, $m);
        $total = array_sum(array_map('intval', $m[1]));

        $this->assertGreaterThan(0, $total, 'خطُّ الأساسِ فارغٌ أو تغيّرت صيغتُه — راجع المقياس');
        $this->assertLessThanOrEqual(self::BASELINE_CEILING, $total,
            "خطُّ أساسِ phpstan كبر إلى {$total} (السقف " . self::BASELINE_CEILING . ') — '
            . 'أصلِح الخطأَ الجديدَ بدل إضافتِه، أو اكتب سببَ الرفعِ في docs/TECH_DEBT.md');
    }

    public function test_الثنائيُّ_مثبّتٌ_ببصمته_ولا_يدخل_المستودع(): void
    {
        $sh = self::read('tools/phpstan.sh');

        $this->assertMatchesRegularExpression('/^PHPSTAN_VERSION="\d+\.\d+\.\d+"$/m', $sh, 'النسخةُ غيرُ مثبّتة');
        $this->assertMatchesRegularExpression('/^PHPSTAN_SHA256="[0-9a-f]{64}"$/m', $sh, 'البصمةُ غائبة');
        $this->assertStringContainsString('"$actual" != "$PHPSTAN_SHA256"', $sh, 'التحقّقُ من البصمة سقط');

        $this->assertMatchesRegularExpression('#^/tools/\.phpstan/$#m', self::read('.gitignore'),
            'مجلّدُ phpstan.phar غيرُ مُهمَل — ثنائيٌّ سيدخل المستودع');
    }

    /** نصُّ وظيفةٍ واحدةٍ من ci.yml — حتى أوّلِ وظيفةٍ تاليةٍ بمسافتين */
    private static function jobBlock(string $ci, string $job): string
    {
        $start = strpos($ci, "\n  {$job}:");
        if ($start === false) return '';
        $next = preg_match('/\n  [a-z_]+:\s*\n/', $ci, $mm, PREG_OFFSET_CAPTURE, $start + 1) ? $mm[0][1] : strlen($ci);

        return substr($ci, $start, $next - $start);
    }
}
