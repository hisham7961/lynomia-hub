<?php

namespace App\Support;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * حرقُ علامةٍ مائيةٍ في البايتات المخدومة — لا في صفحة الغلاف وحدها.
 *
 * غرفةُ البيانات تَعِدُ «عرض فقط بعلامة مائية»، لكنّ الوسمَ كان مجرّدَ طبقةِ CSS في
 * صفحة الغلاف تُتجاوَز بجلب رابط الملف مباشرةً (وهو نفسه مصدرُ الإطار/الصورة) —
 * فيصل المُشاهِدُ إلى نسخةٍ نظيفةٍ بلا وسم من مستندٍ وُصف حسّاساً. هنا يُحرَق الوسمُ
 * في نفس بايتات الـPDF/الصورة فيلازم المستندَ أينما نُقل: بصمةُ مُشاهِدٍ رادعة.
 *
 * يفشل بإلقاء استثناء (لا يُرجع الأصل) — فالمنادي يفشل **مغلقاً** بدل تسريب النظيف.
 */
class Watermark
{
    /**
     * بايتات PDF موسومةٌ بنصٍّ مائلٍ شفيفٍ مكرّرٍ فوق كل صفحة (mPDF + FPDI).
     * الوسمُ يُرسَم **بعد** useTemplate فيُطلى فوق الصفحة المستوردة لا خلفها.
     */
    /** هل يستطيع محلّلُ الوسم قراءةَ هذا الملف؟ — يُسأل عند إنشاء رابط «عرض فقط» لا عند فتحه */
    public static function pdfSupported(string $abs): bool
    {
        try {
            $tmp = storage_path('app/mpdf-tmp');
            is_dir($tmp) || @mkdir($tmp, 0755, true);
            (new Mpdf(['tempDir' => $tmp]))->setSourceFile($abs);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function pdf(string $abs, string $text): string
    {
        $tmp = storage_path('app/mpdf-tmp');
        is_dir($tmp) || @mkdir($tmp, 0755, true);

        $m     = new Mpdf(['tempDir' => $tmp]);
        try {
            $pages = $m->setSourceFile($abs);
        } catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException|\setasign\Fpdi\PdfParser\PdfParserException $e) {
            // (v2.399) كان يسقط ٥٠٠ عامّاً على كل PDF حديث (1.5+ بجداول مضغوطة) — الآن سببٌ مسمّى
            throw new UnsupportedPdfException('PDF بضغطٍ حديث لا يقرؤه محلّلُ الوسم: ' . $e->getMessage(), 0, $e);
        }

        for ($p = 1; $p <= $pages; $p++) {
            $tpl  = $m->importPage($p);
            $size = $m->getTemplateSize($tpl);
            $w    = (float) ($size['width'] ?? 210);
            $h    = (float) ($size['height'] ?? 297);

            $m->AddPageByArray([
                'orientation' => $w > $h ? 'L' : 'P',
                'sheet-size'  => [$w, $h],
            ]);
            $m->useTemplate($tpl);

            $m->SetFont('dejavusans', '', 9);
            $m->SetTextColor(120, 120, 120);
            $m->SetAlpha(0.14);
            for ($y = 15.0; $y < $h + 20; $y += 42) {
                for ($x = -10.0; $x < $w; $x += 78) {
                    $m->Rotate(-28, $x, $y);
                    $m->Text($x, $y, $text);
                    $m->Rotate(0);
                }
            }
            $m->SetAlpha(1);
        }

        $out = $m->Output('', Destination::STRING_RETURN);
        if (! is_string($out) || $out === '' || ! str_starts_with($out, '%PDF')) {
            throw new \RuntimeException('تعذّر توليد PDF الموسوم');
        }

        return $out;
    }

    /** بايتات صورةٍ نقطيةٍ موسومةٌ بنصٍّ مائلٍ مكرّر (GD) — بنفس نوع الأصل */
    public static function image(string $abs, string $text, string $mime): string
    {
        $img = @imagecreatefromstring((string) file_get_contents($abs));
        if ($img === false) {
            throw new \RuntimeException('صورةٌ غير قابلةٍ للقراءة للوسم');
        }

        imagealphablending($img, true);
        $w   = imagesx($img);
        $h   = imagesy($img);
        $col = (int) imagecolorallocatealpha($img, 110, 110, 110, 95);

        $font   = base_path('vendor/mpdf/mpdf/ttfonts/DejaVuSans.ttf');
        $useTtf = is_file($font) && function_exists('imagettftext');
        $stepY  = max(70, (int) ($h / 5));
        $stepX  = max(180, (int) ($w / 3));

        for ($y = 30; $y < $h + $stepY; $y += $stepY) {
            for ($x = -20; $x < $w; $x += $stepX) {
                if ($useTtf) {
                    @imagettftext($img, 11, 28, $x, $y, $col, $font, $text);
                } else {
                    imagestring($img, 4, max(2, $x), $y, $text, $col);
                }
            }
        }

        ob_start();
        self::encode($img, $mime);
        $out = (string) ob_get_clean();
        imagedestroy($img);

        if ($out === '') {
            throw new \RuntimeException('تعذّر ترميز الصورة الموسومة');
        }

        return $out;
    }

    /** ترميزٌ يطابق نوعَ الأصل — إن غاب مُرمِّزُ النوع نفشل مغلقاً لا نُضلّل النوع */
    protected static function encode(\GdImage $img, string $mime): void
    {
        switch ($mime) {
            case 'image/png':
                imagepng($img);
                break;
            case 'image/jpeg':
                imagejpeg($img, null, 90);
                break;
            case 'image/gif':
                imagegif($img);
                break;
            case 'image/webp':
                if (! function_exists('imagewebp')) {
                    throw new \RuntimeException('ترميز webp غير متاح');
                }
                imagewebp($img);
                break;
            case 'image/bmp':
                if (! function_exists('imagebmp')) {
                    throw new \RuntimeException('ترميز bmp غير متاح');
                }
                imagebmp($img);
                break;
            case 'image/avif':
                if (! function_exists('imageavif')) {
                    throw new \RuntimeException('ترميز avif غير متاح');
                }
                imageavif($img);
                break;
            default:
                throw new \RuntimeException('نوعُ صورةٍ غير مدعومٍ للوسم');
        }
    }
}
