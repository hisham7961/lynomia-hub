<?php

namespace Tests\Unit;

use App\Support\Qr;
use App\Support\QrEncoder;
use PHPUnit\Framework\TestCase;

/**
 * الرمزُ يُبنى من مُرمِّزنا **الصرف** (App\Support\QrEncoder) — لا BaconQrCode:
 * تلك تُثبَّت بـcomposer وvendor مستبعدٌ من المستودع، فعلى استضافةٍ تُرفَع بنسخ
 * الملفات كانت تغيب فيختفي الرمز («qr غير موجود»). مُرمِّزنا يُشحَن مع الكود دائماً.
 *
 * الصحّةُ مثبَّتةٌ ببصماتٍ ذهبية: المصفوفاتُ أدناه **طابقت مرجع qrcode (npm) حرفاً
 * بحرف وفكّها jsQR** (فحص كاميرا حقيقي) لحظةَ توليد البصمة — فأيُّ انحرافٍ لاحقٍ
 * في الترميز (RS/الصيغة/الأقنعة/الوضع) يكسر البصمةَ فيصيح الحارس.
 */
class QrTest extends TestCase
{
    private const URL = 'https://hub.lynomia.com/verify/LYN-1234-5678/doc';

    /** بصمةُ مصفوفة v2 بايتيّة بقناع 2 — طابقت qrcode npm وفكّها jsQR */
    public function test_matrix_matches_the_golden_reference(): void
    {
        $m = QrEncoder::matrix('https://example.com', 2);
        $this->assertNotNull($m);
        $this->assertSame('514a050e1b617d4b9d177243bfb81c07f64567e5',
            sha1(implode("\n", array_map(fn ($r) => implode('', $r), $m))),
            'مصفوفةُ الرمز انحرفت عن البصمة الذهبية (المُتحقَّق منها بفكّ jsQR) — رمزٌ قد لا يُمسَح');
    }

    /** بصمةُ مصفوفة v1 قصيرة بقناع 2 */
    public function test_short_code_matrix_matches_golden(): void
    {
        $m = QrEncoder::matrix('LYN-9F2A-7C41', 2);
        $this->assertSame('02ce8885a011810a5c2a9a6b5be0da3abbeca8fd',
            sha1(implode("\n", array_map(fn ($r) => implode('', $r), $m))));
    }

    /** خصائصُ المصفوفة البنيوية: مربعةٌ بقياسٍ صحيح، وأنماطُ الكاشف في أركانها */
    public function test_matrix_structure_is_sound(): void
    {
        $m = QrEncoder::matrix(self::URL);
        $n = count($m);
        $this->assertSame(1, $n % 4 === 1 ? 1 : 0, 'القياس ليس 17+4v');
        $this->assertGreaterThanOrEqual(21, $n);
        foreach ($m as $row) $this->assertCount($n, $row, 'صفٌّ بعرضٍ مخالف');
        // زوايا أنماط الكاشف الثلاثة مظلمة
        $this->assertSame(1, $m[0][0]);
        $this->assertSame(1, $m[0][$n - 1]);
        $this->assertSame(1, $m[$n - 1][0]);
        // الوحدة المظلمة الإلزامية
        $this->assertSame(1, $m[$n - 8][8], 'الوحدة المظلمة الإلزامية غائبة');
    }

    public function test_svg_is_emitted_in_our_dependency_free_format(): void
    {
        $svg = Qr::svg(self::URL, 120);
        $this->assertNotNull($svg, 'الرمز لم يُبنَ');
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('shape-rendering="crispEdges"', $svg);   // بصمةُ راسمنا
        $this->assertStringContainsString('width="120"', $svg);
        $this->assertStringContainsString('<path fill="#000"', $svg);
    }

    /** الوحداتُ الداكنة في مسار الـSVG = مصفوفةُ المُرمِّز حرفاً بحرف (بإزاحة منطقةٍ هادئة) */
    public function test_rendered_modules_match_the_encoder_matrix(): void
    {
        $svg = Qr::svg(self::URL, 200);
        $matrix = QrEncoder::matrix(self::URL);
        $n = count($matrix);
        $quiet = 4;

        preg_match('/<path fill="#000" d="([^"]*)"/', $svg, $mm);
        $this->assertNotEmpty($mm[1] ?? '', 'لا مسار رسمٍ في الرمز');

        $drawn = [];
        preg_match_all('/M(\d+) (\d+)h(\d+)v1h-\3z/', $mm[1], $runs, PREG_SET_ORDER);
        foreach ($runs as $r) {
            $px = (int) $r[1]; $py = (int) $r[2]; $len = (int) $r[3];
            for ($i = 0; $i < $len; $i++) {
                $drawn[($px - $quiet + $i) . ',' . ($py - $quiet)] = true;
            }
        }

        $mismatch = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if (($matrix[$y][$x] === 1) !== isset($drawn["{$x},{$y}"])) $mismatch++;
            }
        }
        $this->assertSame(0, $mismatch, 'رسمُ الـSVG لا يطابق مصفوفة المُرمِّز — رمزٌ لا يُمسَح');
    }

    /** رمزٌ لنصٍّ مختلف يعطي مسارًا مختلفًا — ليس ثابتًا مطبوعًا */
    public function test_distinct_text_yields_distinct_qr(): void
    {
        $this->assertNotSame(Qr::svg('https://example.com/a', 100), Qr::svg('https://example.com/b', 100));
    }

    /** يعمل بلا BaconQrCode إطلاقاً — لا استدعاء لأي صنفٍ من المكتبة في المسار */
    public function test_qr_sources_do_not_reference_the_composer_library(): void
    {
        // بلا التعليقات: ذكرُ المكتبة في التوثيق تاريخٌ لا اعتماد — الحارس على الكود الحيّ
        $strip = fn (string $s) => preg_replace(['/\/\*.*?\*\//s', '~//[^\n]*~'], ' ', $s);
        $src = $strip(file_get_contents(__DIR__ . '/../../app/Support/Qr.php'))
            . $strip(file_get_contents(__DIR__ . '/../../app/Support/QrEncoder.php'));
        $this->assertStringNotContainsString('BaconQrCode', $src,
            'مسار الرمز يعتمد مكتبة composer — يختفي على استضافةٍ بلا vendor حديث');
    }
}
