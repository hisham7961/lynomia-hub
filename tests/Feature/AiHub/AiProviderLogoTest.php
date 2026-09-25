<?php

namespace Tests\Feature\AiHub;

use App\Console\Commands\HubAiLogos;
use App\Support\Ai\Catalog\AiProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **شعاراتُ المزوّدين — أصلٌ مُثبَّتٌ يُمسَح لا أصلٌ يُوثَق به** (LG-3).
 *
 * الشعاراتُ **تُدرَج في متنِ الصفحة** لا في `<img>`، لأنّ أحدَ عشرَ منها
 * أحاديُّ اللونِ بـ`currentColor` فيختفي في `<img>` على خلفيّةٍ داكنة.
 * والإدراجُ يشتري الظهورَ بثمنٍ واضح: **ملفٌّ بُدِّل يوماً يصير شيفرةً في
 * صفحتِنا**. فهذه الحزمةُ هي الثمن: تمسح الملفّاتِ **ملفّاً ملفّاً** عند كلِّ
 * تشغيل، ولا تثق بأنّها نُسخت نظيفةً يومَ نُسخت.
 *
 * **والاحتياطُ يُختبَر كما يُختبَر الأصل**: ستّون مزوّداً بلا شعار، ومزوّدٌ
 * يظهر غداً بلا ملفّ. فسقوطُ الاحتياطِ يترك ثُلثَ الشاشةِ مربّعاتٍ فارغة.
 */
class AiProviderLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        AiProviderRegistry::flushLogoMemo();
    }

    private function dir(): string
    {
        return base_path(HubAiLogos::DIR);
    }

    /** @return array<string,string> */
    private function map(): array
    {
        return (array) config('ai_logos.logos', []);
    }

    // ═══ ① الخريطةُ والملفّات ═══

    public function test_كلُّ_شعارٍ_مُعلَنٍ_له_ملفٌّ_موجود(): void
    {
        $missing = [];
        foreach ($this->map() as $key => $file) {
            if (! is_file($this->dir() . '/' . $file)) $missing[] = "{$key} → {$file}";
        }

        $this->assertSame([], $missing,
            "خريطةٌ تَعِد بشعارٍ لا ملفَّ له — والبطاقةُ تظهر فارغةً:\n" . implode("\n", $missing));
    }

    /** **ولا ملفَّ يتيماً** — وزنٌ يُنشَر ولا يُعرَض */
    public function test_لا_ملفَّ_في_المجلَّدِ_خارجَ_الخريطة(): void
    {
        $declared = array_values(array_unique(array_values($this->map())));
        $orphans  = [];

        foreach ((array) glob($this->dir() . '/*') as $f) {
            $b = basename((string) $f);
            if (! in_array($b, $declared, true)) $orphans[] = $b;
        }

        $this->assertSame([], $orphans,
            "ملفّاتٌ لا تشير إليها الخريطة:\n" . implode("\n", $orphans));
    }

    /** **وكلُّ اسمٍ مكتوبٍ بيدٍ أنتج مطابقةً فعلاً** — وإلّا فهو سطرٌ ميّت */
    public function test_لا_اسمَ_مكتوبٌ_بيدٍ_بلا_أثر(): void
    {
        $dead = [];
        foreach ((array) config('ai_logo_aliases', []) as $key => $base) {
            if (AiProviderRegistry::logo((string) $key) === null) $dead[] = "{$key} → {$base}";
        }

        $this->assertSame([], $dead,
            "اسمٌ مكتوبٌ بيدٍ لا يُنتج شعاراً — إمّا المفتاحُ تغيّر أو الملفُّ زال:\n"
            . implode("\n", $dead));
    }

    // ═══ ② المتنُ نفسُه — يُمسَح ملفّاً ملفّاً ═══

    /**
     * **كلُّ ملفٍّ في المجلَّدِ متنٌ نظيف.**
     *
     * وليس هذا احترازاً من مهاجمٍ يكتب في مستودعِنا — من بلغ ذلك بلغ أكثر.
     * هو حارسٌ ضدّ **التبديلِ الصامت**: ترقيةُ مجموعةٍ تُدخِل ملفّاً بصيغةٍ
     * أخرى، أو نسخٌ من مصدرٍ ثانٍ لم يُراجَع. والمتنُ يُدرَج في صفحتِنا،
     * فالشرطُ يبقى مكتوباً ويُفحَص.
     */
    public function test_كلُّ_ملفٍّ_svg_نظيفٌ_بلا_شيفرة(): void
    {
        $bad   = [];
        $files = (array) glob($this->dir() . '/*.svg');

        $this->assertGreaterThan(50, count($files), 'مجلَّدُ الشعاراتِ شبهُ فارغ — الحارسُ يحرس فراغاً');

        foreach ($files as $f) {
            $svg = (string) file_get_contents((string) $f);
            if (! AiProviderRegistry::safeSvg($svg)) $bad[] = basename((string) $f);
        }

        $this->assertSame([], $bad,
            "ملفٌّ ليس SVG نظيفاً ويُدرَج في متنِ الصفحة:\n" . implode("\n", $bad));
    }

    /** **والحارسُ يردُّ ما يجب أن يردَّه** — وإلّا فهو تعويذةٌ لا حارس */
    public function test_حارسُ_المتنِ_يردُّ_الخبيث(): void
    {
        $hostile = [
            'نصٌّ ليس svg أصلاً',
            '<svg><script>alert(1)</script></svg>',
            '<svg onload="alert(1)"><path/></svg>',
            '<svg><a xlink:href="javascript:alert(1)">x</a></svg>',
            '<svg><foreignObject><body onload="x()"/></foreignObject></svg>',
            '<svg><iframe src="//evil"></iframe></svg>',
            '<html><svg/></html>',
        ];

        foreach ($hostile as $i => $s) {
            $this->assertFalse(AiProviderRegistry::safeSvg($s),
                "متنٌ خبيثٌ مرّ من الحارسِ [{$i}]: " . mb_substr($s, 0, 48));
        }

        $this->assertTrue(AiProviderRegistry::safeSvg(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M1 1h2"/></svg>'),
            'الحارسُ يردُّ الصالحَ — فيصير كلُّ شعارٍ احتياطاً');
    }

    /** **واسمُ ملفٍّ فيه مسارٌ يُرَدّ** ولو جاء من خريطةٍ مُبدَّلة */
    public function test_اسمٌ_فيه_مسارٌ_لا_يُقرَأ(): void
    {
        config(['ai_logos.logos.openai' => '../../../.env']);
        AiProviderRegistry::flushLogoMemo();

        $this->assertNull(AiProviderRegistry::logoSvg('openai'),
            '**اجتيازُ مسار**: اسمُ ملفٍّ خرج من مجلَّدِ الشعارات');
    }

    // ═══ ③ الاحتياطُ — ثُلثُ الشاشةِ يعتمد عليه ═══

    public function test_من_لا_شعارَ_له_يأخذ_علامةً_حرفيّة(): void
    {
        // وضعٌ عامٌّ لا شركة — **ولا يُسمَّى له صاحبٌ لا وجودَ له**
        $this->assertNull(AiProviderRegistry::logo('custom'));
        $this->assertNull(AiProviderRegistry::logoSvg('custom'));

        $mark = AiProviderRegistry::mark('custom');
        $this->assertNotSame('', $mark['initials']);
        $this->assertGreaterThanOrEqual(0, $mark['hue']);
        $this->assertLessThan(360, $mark['hue']);
    }

    /** **ومزوّدٌ لم يُقَس قطّ يأخذ علامتَه بلا ملفٍّ ولا سطر** */
    public function test_مزوّدٌ_مجهولٌ_لا_يُسقِط_شيئاً(): void
    {
        $this->assertNull(AiProviderRegistry::logo('مزوّدٌ-لم-يولد-بعد-9981'));
        $this->assertNotSame('', AiProviderRegistry::mark('brand_new_provider')['initials']);
    }

    /** والتغطيةُ لم تنهَر صامتةً — خريطةٌ فارغةٌ تُسقط هذا الصفَّ لا الشاشة */
    public function test_التغطيةُ_معقولةٌ_لا_صفر(): void
    {
        $this->assertGreaterThan(60, count($this->map()),
            'تغطيةُ الشعاراتِ انهارت — راجِع التوليدَ قبل الدفع');
    }

    // ═══ ④ الشاشة ═══

    public function test_الشاشةُ_تعرض_شعاراً_حقيقيّاً_وتسقط_إلى_الحرفين(): void
    {
        $html = (string) $this->actingAs($this->owner)
            ->get(route('ai.providers.index'))->assertOk()->getContent();

        $this->assertStringContainsString('pvsvg', $html,
            'لا شعارَ واحدٌ أُدرِج في الشاشة');
        $this->assertStringContainsString('<svg', $html);

        // والاحتياطُ حاضرٌ أيضاً — فالشاشةُ فيها الصنفان معاً
        $this->assertStringContainsString('--pv-h:', $html,
            'اختفى الاحتياطُ الحرفيُّ — ومن لا شعارَ له يظهر فارغاً');
    }

    /** **ولا يُدرَج في الصفحةِ شيءٌ غيرُ نظيف** مهما كثرت الشعارات */
    public function test_لا_شيفرةَ_في_ما_أُدرِج(): void
    {
        $html = (string) $this->actingAs($this->owner)
            ->get(route('ai.providers.index'))->assertOk()->getContent();

        // الصفحةُ نفسُها تحمل سكربتَ حراسةِ الإرسال، فيُقصُّ البحثُ على ما
        // بين وسومِ svg وحدَها — وهو ما أُدرِج من الملفّات
        preg_match_all('#<svg\b.*?</svg>#is', $html, $m);
        $this->assertNotEmpty($m[0], 'لا وسمَ svg في الصفحة');

        foreach ($m[0] as $svg) {
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $svg,
                'معالجُ حدثٍ داخلَ شعارٍ مُدرَج');
            $this->assertStringNotContainsStringIgnoringCase('<script', $svg);
        }
    }
}
