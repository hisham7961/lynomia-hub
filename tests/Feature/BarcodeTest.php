<?php

namespace Tests\Feature;

use App\Support\Barcode;
use Tests\TestCase;

/**
 * **مولّد Code 128 — جدولٌ قياسيٌّ يُثبَت رياضياً لا بالعين.**
 *
 * الجدولُ ١٠٦ أنماط لكل منها ستُّ خانات مجموعُها ١١ وحدةً دائماً، والإيقافُ
 * ١٣ — ثابتان في المواصفة نفسِها: أي خطأِ نسخٍ في خانةٍ واحدة يكسر المجموع
 * فيصطاده هذا الملف قبل أن يطبع أحدٌ ملصقاً لا يقرؤه ماسح.
 */
class BarcodeTest extends TestCase
{
    public function test_the_pattern_table_is_mathematically_sound(): void
    {
        $this->assertCount(106, Barcode::PATTERNS, 'رموز 0..105');
        foreach (Barcode::PATTERNS as $i => $p) {
            $this->assertSame(11, array_sum(str_split($p)), "النمط $i مجموعه ١١ وحدة");
            $this->assertSame(6, strlen($p), "النمط $i ستُّ خانات");
        }
        $this->assertSame(13, array_sum(str_split(Barcode::STOP)), 'الإيقاف ١٣ وحدة');
    }

    public function test_checksums_match_hand_computed_values(): void
    {
        // «LYN» بالمجموعة B: 104 + (44×1 + 57×2 + 46×3) = 400 ⇒ 400 % 103 = 91
        $vals = Barcode::codes('LYN');
        $this->assertSame([104, 44, 57, 46, 91], $vals);

        // أرقامٌ زوجية الطول ⇒ المجموعة C أزواجاً: 105 + 12×1 + 34×2 = 185 ⇒ 82
        $c = Barcode::codes('1234');
        $this->assertSame([105, 12, 34, 82], $c);

        // فرديّةُ الطول تبقى في B — لا صفرَ يُحشى فيغيّر القيمة الممسوحة
        $odd = Barcode::codes('12345');
        $this->assertSame(104, $odd[0]);
    }

    public function test_svg_output_is_scannable_shaped_and_fails_safely(): void
    {
        $svg = Barcode::svg('LYN-PRD-00000001');
        $this->assertNotNull($svg);
        $this->assertStringContainsString('viewBox', $svg);
        $this->assertStringContainsString('crispEdges', $svg, 'حوافٌ حادة — الضبابية تقتل المسح');
        $this->assertStringContainsString('LYN-PRD-00000001', $svg, 'النص المقروء تحت الأشرطة');

        // عرضُ الرسم = هادئتان + ١١ وحدة لكل رمزٍ + ١٣ للإيقاف — يُحسب لا يُقدَّر
        $n = count(Barcode::codes('LYN-PRD-00000001'));
        preg_match('/viewBox="0 0 (\d+) /', $svg, $m);
        $this->assertSame(20 + $n * 11 + 13, (int) $m[1]);

        // ما يخرج عن ASCII المطبوع يفشل بأمان null — والواجهة تكتفي بالنص
        $this->assertNull(Barcode::svg('نصٌ عربي'));
        $this->assertNull(Barcode::svg(''));
        $this->assertNull(Barcode::svg(str_repeat('X', 61)), 'طولٌ فوق الحد');
    }
}
