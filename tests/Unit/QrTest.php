<?php

namespace Tests\Unit;

use App\Support\Qr;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use PHPUnit\Framework\TestCase;

/**
 * الرمزُ يُبنى من مُرمِّز BaconQrCode (PHP صرف) لا من باطنه SvgImageBackEnd الذي
 * يشترط امتداد xmlwriter — فيظهر على أي استضافة. هذا الحارس يُثبت أمرين:
 *  (١) الرمزُ يُرسَم بصيغتنا نحن (لا صيغة المكتبة المشروطة بالامتداد)؛
 *  (٢) وحداتُه الداكنة **مطابقةٌ تماماً** لمصفوفة المُرمِّز — فالرسمُ أمينٌ للترميز
 *      (لا إزاحة، لا انقلاب محور، ومنطقةٌ هادئةٌ صحيحة) — فالرمزُ يُمسَح فعلاً.
 */
class QrTest extends TestCase
{
    private const URL = 'https://hub.lynomia.com/verify/LYN-1234-5678/doc';

    public function test_svg_is_emitted_in_our_dependency_free_format(): void
    {
        $svg = Qr::svg(self::URL, 120);
        $this->assertNotNull($svg, 'الرمز لم يُبنَ — المُرمِّز غائب؟');
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('shape-rendering="crispEdges"', $svg);   // بصمةُ راسمنا
        $this->assertStringContainsString('width="120"', $svg);
        $this->assertStringContainsString('<path fill="#000"', $svg);
    }

    /** الوحداتُ الداكنة في مسار الـSVG = مصفوفةُ المُرمِّز حرفاً بحرف (بإزاحة منطقةٍ هادئة) */
    public function test_rendered_modules_match_the_encoder_matrix(): void
    {
        $svg = Qr::svg(self::URL, 200);
        $matrix = Encoder::encode(self::URL, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        $n = $matrix->getWidth();
        $quiet = 4;

        // نستخرج مسار الرسم ونعيد بناء مجموعة الخلايا الداكنة منه
        preg_match('/<path fill="#000" d="([^"]*)"/', $svg, $mm);
        $this->assertNotEmpty($mm[1] ?? '', 'لا مسار رسمٍ في الرمز');

        $drawn = [];   // "x,y" => true بإحداثيات الشبكة (بعد طرح المنطقة الهادئة)
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
                $dark = $matrix->get($x, $y) === 1;
                $has = isset($drawn["{$x},{$y}"]);
                if ($dark !== $has) $mismatch++;
            }
        }
        $this->assertSame(0, $mismatch, 'رسمُ الـSVG لا يطابق مصفوفة المُرمِّز — رمزٌ لا يُمسَح');
        // ولا وحداتٍ مرسومةٍ خارج الشبكة (تسرّبٌ في الإحداثيات)
        $this->assertLessThanOrEqual($n * $n, count($drawn), 'وحداتٌ مرسومةٌ خارج حدود الشبكة');
    }

    /** رمزٌ لنصٍّ مختلف يعطي مسارًا مختلفًا — ليس ثابتًا مطبوعًا */
    public function test_distinct_text_yields_distinct_qr(): void
    {
        $a = Qr::svg('https://example.com/a', 100);
        $b = Qr::svg('https://example.com/b', 100);
        $this->assertNotSame($a, $b);
    }
}
